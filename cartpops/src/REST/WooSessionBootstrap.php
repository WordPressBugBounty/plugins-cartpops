<?php
/**
 * Prepare WooCommerce session authority before REST permission callbacks.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/**
 * Keep one validated Cart-Token bound to Woo's Store session for its dispatch.
 */
final class WooSessionBootstrap {
	private const MAX_CART_TOKEN_LENGTH = 2048;

	private const STORE_API_SESSION_HANDLER = 'Automattic\\WooCommerce\\StoreApi\\SessionHandler';

	private const MODERN_TOKEN_UTILITY = 'Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils';

	private const LEGACY_TOKEN_UTILITY = 'Automattic\\WooCommerce\\StoreApi\\Utilities\\JsonWebToken';

	private const HANDLER_HOOK = 'woocommerce_session_handler';

	private const BEFORE_CALLBACKS_HOOK = 'rest_request_before_callbacks';

	private const AFTER_CALLBACKS_HOOK = 'rest_request_after_callbacks';

	private const POST_DISPATCH_HOOK = 'rest_post_dispatch';

	private const SHUTDOWN_HOOK = 'shutdown';

	private const HANDLER_PRIORITY = PHP_INT_MAX;

	private const CLEANUP_PRIORITY = PHP_INT_MAX;

	/**
	 * The only active CartPops dispatch lease in this PHP process.
	 *
	 * @var self|null
	 */
	private static ?self $active_owner = null;

	/**
	 * WooCommerce singleton resolver.
	 *
	 * @var \Closure(): mixed
	 */
	private \Closure $woocommerce_resolver;

	/**
	 * Official Store API token authority adapter.
	 *
	 * A boolean result remains supported for existing explicit test adapters:
	 * true means the adapter fully prepared the session, while false means the
	 * infrastructure was unavailable. Production returns the typed outcome.
	 *
	 * @var \Closure(object, string): (WooSessionBootstrapOutcome|bool)
	 */
	private \Closure $store_session_preparer;

	/**
	 * Request-lifecycle hook adapter.
	 *
	 * @var WooSessionDispatchAdapter
	 */
	private WooSessionDispatchAdapter $dispatch;

	/**
	 * Request that owns the active lease.
	 *
	 * @var \WP_REST_Request|null
	 */
	private ?\WP_REST_Request $active_request = null;

	/**
	 * WooCommerce singleton bound to the active lease.
	 *
	 * @var object|null
	 */
	private ?object $active_woocommerce = null;

	/**
	 * Exact Store API customer bound after initialization.
	 *
	 * @var string|null
	 */
	private ?string $expected_customer_id = null;

	/**
	 * WooCommerce singleton retained until its shutdown persistence completes.
	 *
	 * @var object|null
	 */
	private ?object $shutdown_woocommerce = null;

	/**
	 * Exact Store session eligible for maximum-priority shutdown cleanup.
	 *
	 * @var object|null
	 */
	private ?object $shutdown_session = null;

	/**
	 * Whether the Woo handler selector was installed.
	 *
	 * @var bool
	 */
	private bool $selector_installed = false;

	/**
	 * Whether the nested-dispatch guard was installed.
	 *
	 * @var bool
	 */
	private bool $before_hook_installed = false;

	/**
	 * Whether callback cleanup was installed.
	 *
	 * @var bool
	 */
	private bool $after_hook_installed = false;

	/**
	 * Whether final REST cleanup was installed.
	 *
	 * @var bool
	 */
	private bool $post_hook_installed = false;

	/**
	 * Whether exception/shutdown cleanup was installed.
	 *
	 * @var bool
	 */
	private bool $shutdown_hook_installed = false;

	/**
	 * Whether handler and customer authority remain unchanged.
	 *
	 * @var bool
	 */
	private bool $lease_healthy = true;

	/**
	 * Set the WooCommerce and WordPress hook seams.
	 *
	 * @param (callable(): mixed)|null                                           $woocommerce_resolver   WooCommerce singleton resolver.
	 * @param (callable(object, string): (WooSessionBootstrapOutcome|bool))|null $store_session_preparer Official token authority adapter.
	 * @param WooSessionDispatchAdapter|null                                     $dispatch               Request-lifecycle adapter.
	 */
	public function __construct(
		?callable $woocommerce_resolver = null,
		?callable $store_session_preparer = null,
		?WooSessionDispatchAdapter $dispatch = null
	) {
		$this->woocommerce_resolver   = \Closure::fromCallable(
			$woocommerce_resolver ?? static fn() => function_exists( 'WC' ) ? WC() : null
		);
		$this->store_session_preparer = \Closure::fromCallable(
			$store_session_preparer ?? array( self::class, 'authorize_store_api_session' )
		);
		$this->dispatch               = $dispatch ?? new WordPressWooSessionDispatchAdapter();
	}

	/**
	 * Backward-compatible readiness predicate.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function prepare( \WP_REST_Request $request ): bool {
		return WooSessionBootstrapOutcome::READY === $this->prepare_outcome( $request );
	}

	/**
	 * Prepare session authority and retain typed credential/infrastructure state.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function prepare_outcome( \WP_REST_Request $request ): WooSessionBootstrapOutcome {
		try {
			$woocommerce = ( $this->woocommerce_resolver )();
			if ( ! is_object( $woocommerce ) ) {
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}

			$cart_token = $request->get_header( 'Cart-Token' );
			if ( null === $cart_token ) {
				return $this->prepare_classic_session( $woocommerce );
			}
			if ( ! is_string( $cart_token ) || '' === $cart_token || strlen( $cart_token ) > self::MAX_CART_TOKEN_LENGTH ) {
				return WooSessionBootstrapOutcome::INVALID_CREDENTIAL;
			}

			$authority = ( $this->store_session_preparer )( $woocommerce, $cart_token );
			if ( is_bool( $authority ) ) {
				return $authority && $this->has_usable_session( $woocommerce )
					? WooSessionBootstrapOutcome::READY
					: WooSessionBootstrapOutcome::UNAVAILABLE;
			}
			if ( ! $authority instanceof WooSessionBootstrapOutcome ) {
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}
			if ( WooSessionBootstrapOutcome::READY !== $authority ) {
				return $authority;
			}
			if ( ! $this->has_compatible_existing_store_session( $woocommerce ) ) {
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}

			if ( ! $this->activate_dispatch( $request, $woocommerce ) ) {
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}
			if ( ! is_callable( array( $woocommerce, 'initialize_session' ) ) ) {
				$this->abort_dispatch();
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}

			$woocommerce->initialize_session();
			if ( ! $this->bind_current_identity() ) {
				$this->abort_dispatch();
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}

			// rest_send_allow_header() re-runs permission callbacks after the
			// controller. It needs the bound Store session for validation, but no
			// selector may survive the current rest_post_dispatch filter.
			if ( $this->dispatch->is_running( self::POST_DISPATCH_HOOK ) && ! $this->finish_dispatch( true ) ) {
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}

			return WooSessionBootstrapOutcome::READY;
		} catch ( \Throwable ) {
			$this->abort_dispatch();
			return WooSessionBootstrapOutcome::UNAVAILABLE;
		}
	}

	/**
	 * Keep the official handler selected through another Woo initialization.
	 *
	 * @param mixed $handler Previously selected Woo session handler.
	 */
	public function select_store_handler( mixed $handler ): string {
		if ( $this->selector_installed && $this->lease_healthy ) {
			return $this->dispatch->handler_class();
		}
		return is_string( $handler ) && '' !== $handler ? $handler : 'WC_Session_Handler';
	}

	/**
	 * Reject nested dispatch use instead of leaking the outer Cart-Token handler.
	 *
	 * The outer rest_request_before_callbacks filter has already run when this
	 * lease is installed, so this callback observes only nested requests.
	 *
	 * @param mixed $response Current response or error.
	 * @param mixed $handler  Matched route handler.
	 * @param mixed $request  Nested request candidate.
	 */
	public function guard_nested_dispatch( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );
		if ( $request instanceof \WP_REST_Request && $request !== $this->active_request ) {
			$this->lease_healthy = false;
			return $this->lifecycle_error();
		}
		return $response;
	}

	/**
	 * Validate and release the exact lease after controller/permission handling.
	 *
	 * @param mixed $response Current response or error.
	 * @param mixed $handler  Matched route handler.
	 * @param mixed $request  Completed request.
	 */
	public function finish_after_callbacks( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );
		if ( $request !== $this->active_request ) {
			return $response;
		}
		return $this->finish_dispatch( true ) ? $response : $this->lifecycle_error();
	}

	/**
	 * Final response fallback when callback cleanup did not run.
	 *
	 * @param mixed $response Current REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  Completed request.
	 */
	public function finish_post_dispatch( mixed $response, mixed $server, mixed $request ): mixed {
		unset( $server );
		if ( $request !== $this->active_request ) {
			return $response;
		}
		return $this->finish_dispatch( true ) ? $response : $this->lifecycle_error();
	}

	/** Release the selector when a controller exception aborts REST dispatch. */
	public function finish_at_shutdown(): void {
		$this->finish_dispatch( false, true );
	}

	/**
	 * Initialize the normal Woo handler when no Cart-Token is present.
	 *
	 * @param object $woocommerce WooCommerce singleton.
	 */
	private function prepare_classic_session( object $woocommerce ): WooSessionBootstrapOutcome {
		$session = $woocommerce->session ?? null;
		if ( ! is_object( $session ) || $this->dispatch->handler_class() === get_class( $session ) ) {
			if ( ! is_callable( array( $woocommerce, 'initialize_session' ) ) ) {
				return WooSessionBootstrapOutcome::UNAVAILABLE;
			}
			$woocommerce->initialize_session();
		}

		return $this->has_usable_session( $woocommerce )
			? WooSessionBootstrapOutcome::READY
			: WooSessionBootstrapOutcome::UNAVAILABLE;
	}

	/**
	 * Install one exact request lease before any Woo session initialization.
	 *
	 * @param \WP_REST_Request $request     Request that owns the lease.
	 * @param object           $woocommerce WooCommerce singleton.
	 */
	private function activate_dispatch( \WP_REST_Request $request, object $woocommerce ): bool {
		$owner = self::$active_owner;
		if ( null !== $owner ) {
			return $owner->active_request === $request
				&& $owner->active_woocommerce === $woocommerce
				&& $owner->current_identity_matches();
		}

		$this->active_request       = $request;
		$this->active_woocommerce   = $woocommerce;
		$this->expected_customer_id = null;
		$this->lease_healthy        = true;
		self::$active_owner         = $this;

		$this->selector_installed      = $this->dispatch->add(
			self::HANDLER_HOOK,
			array( $this, 'select_store_handler' ),
			self::HANDLER_PRIORITY,
			1
		);
		$this->before_hook_installed   = $this->selector_installed && $this->dispatch->add(
			self::BEFORE_CALLBACKS_HOOK,
			array( $this, 'guard_nested_dispatch' ),
			-PHP_INT_MAX,
			3
		);
		$this->after_hook_installed    = $this->before_hook_installed && $this->dispatch->add(
			self::AFTER_CALLBACKS_HOOK,
			array( $this, 'finish_after_callbacks' ),
			self::CLEANUP_PRIORITY,
			3
		);
		$this->post_hook_installed     = $this->after_hook_installed && $this->dispatch->add(
			self::POST_DISPATCH_HOOK,
			array( $this, 'finish_post_dispatch' ),
			self::CLEANUP_PRIORITY,
			3
		);
		$this->shutdown_hook_installed = $this->post_hook_installed && $this->dispatch->add(
			self::SHUTDOWN_HOOK,
			array( $this, 'finish_at_shutdown' ),
			self::CLEANUP_PRIORITY,
			0
		);

		if ( ! $this->shutdown_hook_installed ) {
			$this->abort_dispatch();
			return false;
		}
		return true;
	}

	/** Bind the exact Store handler/customer selected under the active lease. */
	private function bind_current_identity(): bool {
		$session = $this->active_woocommerce->session ?? null;
		if (
			! is_object( $session )
			|| $this->dispatch->handler_class() !== get_class( $session )
			|| ! is_callable( array( $session, 'get_customer_id' ) )
		) {
			return false;
		}
		$customer_id = $session->get_customer_id();
		if ( ! is_string( $customer_id ) || '' === $customer_id ) {
			return false;
		}
		$this->expected_customer_id = $customer_id;
		return true;
	}

	/** Ensure no controller or third-party filter changed handler authority. */
	private function current_identity_matches(): bool {
		if ( ! $this->lease_healthy || null === $this->expected_customer_id ) {
			return false;
		}
		$session = $this->active_woocommerce->session ?? null;
		if (
			! is_object( $session )
			|| $this->dispatch->handler_class() !== get_class( $session )
			|| ! is_callable( array( $session, 'get_customer_id' ) )
		) {
			return false;
		}
		$customer_id = $session->get_customer_id();
		return is_string( $customer_id ) && hash_equals( $this->expected_customer_id, $customer_id );
	}

	/**
	 * Release every installed hook, returning false on identity or hook drift.
	 *
	 * @param bool $verify_identity Whether to prove the bound handler/customer.
	 * @param bool $at_shutdown     Whether Woo's shutdown persistence has finished.
	 */
	private function finish_dispatch( bool $verify_identity, bool $at_shutdown = false ): bool {
		if ( self::$active_owner !== $this ) {
			return $at_shutdown ? $this->clear_shutdown_session() : ! $this->selector_installed;
		}
		$healthy             = ! $verify_identity || $this->current_identity_matches();
		$this->lease_healthy = false;

		$removed = true;
		if ( $this->selector_installed ) {
			$removed                  = $this->dispatch->remove( self::HANDLER_HOOK, array( $this, 'select_store_handler' ), self::HANDLER_PRIORITY );
			$this->selector_installed = false;
		}
		if ( $this->before_hook_installed ) {
			$removed                     = $this->dispatch->remove( self::BEFORE_CALLBACKS_HOOK, array( $this, 'guard_nested_dispatch' ), -PHP_INT_MAX ) && $removed;
			$this->before_hook_installed = false;
		}
		if ( $this->after_hook_installed ) {
			$removed                    = $this->dispatch->remove( self::AFTER_CALLBACKS_HOOK, array( $this, 'finish_after_callbacks' ), self::CLEANUP_PRIORITY ) && $removed;
			$this->after_hook_installed = false;
		}
		if ( $this->post_hook_installed ) {
			$removed                   = $this->dispatch->remove( self::POST_DISPATCH_HOOK, array( $this, 'finish_post_dispatch' ), self::CLEANUP_PRIORITY ) && $removed;
			$this->post_hook_installed = false;
		}
		if ( $at_shutdown && $this->shutdown_hook_installed ) {
			$removed                       = $this->dispatch->remove( self::SHUTDOWN_HOOK, array( $this, 'finish_at_shutdown' ), self::CLEANUP_PRIORITY ) && $removed;
			$this->shutdown_hook_installed = false;
		}

		$session = $this->active_woocommerce->session ?? null;
		if ( is_object( $session ) ) {
			$this->shutdown_woocommerce = $this->active_woocommerce;
			$this->shutdown_session     = $session;
		}

		$this->active_request       = null;
		$this->active_woocommerce   = null;
		$this->expected_customer_id = null;
		self::$active_owner         = null;
		$shutdown_clean             = ! $at_shutdown || $this->clear_shutdown_session();
		return $healthy && $removed && $shutdown_clean;
	}

	/** Best-effort rollback for a partially installed or failed lease. */
	private function abort_dispatch(): void {
		$this->lease_healthy = false;
		$this->finish_dispatch( false );
	}

	/**
	 * Whether an explicitly prepared legacy test session is usable.
	 *
	 * @param object $woocommerce WooCommerce singleton.
	 */
	private function has_usable_session( object $woocommerce ): bool {
		$session = $woocommerce->session ?? null;
		return is_object( $session ) && is_callable( array( $session, 'get_customer_id' ) );
	}

	/**
	 * Refuse to replace a session initialized outside the validated Store lease.
	 *
	 * @param object $woocommerce WooCommerce singleton.
	 */
	private function has_compatible_existing_store_session( object $woocommerce ): bool {
		if ( ! property_exists( $woocommerce, 'session' ) ) {
			return false;
		}
		$session = $woocommerce->session ?? null;
		if ( null === $session ) {
			return true;
		}
		return (
			self::$active_owner === $this
			&& $this->active_woocommerce === $woocommerce
			&& $this->current_identity_matches()
		) || (
				$this->dispatch->is_running( self::POST_DISPATCH_HOOK )
				&& is_object( $session )
				&& $this->dispatch->handler_class() === get_class( $session )
				&& is_callable( array( $session, 'get_customer_id' ) )
			);
	}

	/** Clear only the exact Store session after Woo's own shutdown persistence. */
	private function clear_shutdown_session(): bool {
		$woocommerce                   = $this->shutdown_woocommerce;
		$session                       = $this->shutdown_session;
		$this->shutdown_woocommerce    = null;
		$this->shutdown_session        = null;
		$this->shutdown_hook_installed = false;
		if ( null === $woocommerce || null === $session ) {
			return true;
		}
		try {
			if ( ( $woocommerce->session ?? null ) !== $session ) {
				return true;
			}
			$woocommerce->session = null;
			return null === ( $woocommerce->session ?? null );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/** Build the generic fail-closed lifecycle response. */
	private function lifecycle_error(): \WP_Error {
		return new \WP_Error(
			'cartpops_session_lifecycle_unavailable',
			__( 'Your cart session is unavailable. Please refresh and try again.', 'cartpops' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Validate only through the installed WooCommerce Cart-Token implementation.
	 *
	 * @param object $woocommerce WooCommerce singleton (kept for adapter compatibility).
	 * @param string $cart_token  Bounded Cart-Token request header.
	 */
	private static function authorize_store_api_session( object $woocommerce, string $cart_token ): WooSessionBootstrapOutcome {
		unset( $woocommerce );
		if ( ! self::has_valid_signature( $cart_token ) || ! self::matches_outer_request_token( $cart_token ) ) {
			return WooSessionBootstrapOutcome::INVALID_CREDENTIAL;
		}
		if (
			! class_exists( self::STORE_API_SESSION_HANDLER )
			|| ! class_exists( 'WC_Session' )
			|| ! is_subclass_of( self::STORE_API_SESSION_HANDLER, 'WC_Session' )
		) {
			return WooSessionBootstrapOutcome::UNAVAILABLE;
		}
		return WooSessionBootstrapOutcome::READY;
	}

	/**
	 * Validate the token signature using the installed Woo implementation.
	 *
	 * @param string $cart_token Bounded Cart-Token request header.
	 */
	private static function has_valid_signature( string $cart_token ): bool {
		try {
			if ( class_exists( self::MODERN_TOKEN_UTILITY ) ) {
				return is_callable( array( self::MODERN_TOKEN_UTILITY, 'validate_cart_token' ) )
					&& true === self::MODERN_TOKEN_UTILITY::validate_cart_token( $cart_token );
			}
			if (
				! class_exists( self::LEGACY_TOKEN_UTILITY )
				|| ! is_callable( array( self::LEGACY_TOKEN_UTILITY, 'validate' ) )
				|| ! function_exists( 'wp_salt' )
			) {
				return false;
			}
			$salt = (string) wp_salt();
			return '' !== $salt && true === self::LEGACY_TOKEN_UTILITY::validate( $cart_token, '@' . $salt );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Ensure the official handler consumes the exact validated outer header.
	 *
	 * @param string $cart_token Bounded, validated Cart-Token request header.
	 */
	private static function matches_outer_request_token( string $cart_token ): bool {
		if (
			! isset( $_SERVER['HTTP_CART_TOKEN'] )
			|| ! is_string( $_SERVER['HTTP_CART_TOKEN'] )
			|| ! function_exists( 'wp_unslash' )
			|| ! function_exists( 'wc_clean' )
		) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact signed header is unslashed, Woo-sanitized, then hash-compared to the already validated token.
		$outer = wc_clean( wp_unslash( $_SERVER['HTTP_CART_TOKEN'] ) );
		return is_string( $outer ) && hash_equals( $cart_token, $outer );
	}
}
