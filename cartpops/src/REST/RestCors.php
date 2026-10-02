<?php
/**
 * CartPops REST namespace request and response policy hooks.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/** Enforce the closed namespace policy before route parsing/callback work. */
final class RestCors {

	/** Latest documented WordPress hook priority practical on supported PHP. */
	private const TERMINAL_FILTER_PRIORITY = PHP_INT_MAX;

	/** Core's v1 batch endpoint accepts at most 25 subrequests. */
	private const MAX_BATCH_EXPECTATIONS = 25;

	/** Bound nested core/Woo batch lifecycles in one PHP request. */
	private const MAX_BATCH_DEPTH = 8;

	/**
	 * Value-free expectations awaiting callback entry or served-response cleanup.
	 *
	 * @var \WeakMap<\WP_REST_Request, RestPreDispatchFinalizer>|null
	 */
	private static ?\WeakMap $request_expectations = null;

	/**
	 * Active core/Woo batch lifecycles, outermost first.
	 *
	 * Core deliberately runs rest_pre_dispatch on a clone, then callback and
	 * rest_post_dispatch hooks on the original request. The bounded FIFO holds
	 * only method/route/outcome metadata and is cleared when the outer callback
	 * lifecycle ends.
	 *
	 * @var list<array{request: \WeakReference, expectations: list<RestPreDispatchFinalizer>, overflow: bool}>
	 */
	private static array $batch_contexts = array();

	/** Register each namespace policy hook exactly once. */
	public static function register(): void {
		NamespaceRequestPolicy::registry();
		self::add_filter_once( 'rest_jsonp_enabled', array( self::class, 'disable_jsonp_for_namespace' ), 1, 1 );
		self::add_filter_once( 'rest_pre_dispatch', array( self::class, 'enforce_request' ), self::TERMINAL_FILTER_PRIORITY, 3 );
		self::add_filter_once( 'rest_request_before_callbacks', array( self::class, 'before_callbacks' ), self::TERMINAL_FILTER_PRIORITY, 3 );
		self::add_filter_once( 'rest_request_after_callbacks', array( self::class, 'after_callbacks' ), self::TERMINAL_FILTER_PRIORITY, 3 );
		self::add_filter_once( 'rest_post_dispatch', array( self::class, 'apply_response_policy' ), self::TERMINAL_FILTER_PRIORITY, 3 );
		self::add_filter_once( 'rest_pre_serve_request', array( self::class, 'send_headers' ), 20, 4 );
	}

	/**
	 * Disable core JSONP before it selects a content type or parses a callback.
	 *
	 * WordPress evaluates this filter before constructing WP_REST_Request, so the
	 * route must be resolved from the already-validated rewrite/query globals.
	 * Other REST namespaces retain the site's existing JSONP setting.
	 *
	 * @param bool $enabled Existing site-wide JSONP setting.
	 */
	public static function disable_jsonp_for_namespace( bool $enabled ): bool {
		return self::is_global_namespace_request() ? false : $enabled;
	}

	/**
	 * Enforce raw-body, method, JSONP, origin, and OPTIONS policy before WordPress
	 * validates/sanitizes JSON or invokes permissions/controllers.
	 *
	 * @param mixed            $result  Earlier result.
	 * @param mixed            $server  REST server.
	 * @param \WP_REST_Request $request REST request.
	 * @return mixed|\WP_Error|\WP_REST_Response
	 */
	public static function enforce_request( $result, $server, \WP_REST_Request $request ) {
		if ( ! self::is_cartpops_request( $request ) ) {
			return $result;
		}

		$normalized = self::normalize_request_result( $result, $server, $request );
		self::remember_expectation( $request, RestPreDispatchFinalizer::capture( $request, $normalized ) );

		return $normalized;
	}

	/**
	 * Mark that core reached route matching and its permission/callback lifecycle.
	 *
	 * This public core hook is also the only stable outer-batch lifetime seam.
	 *
	 * @param mixed            $response Current callback response/error.
	 * @param array<mixed>     $handler  Matched route handler.
	 * @param \WP_REST_Request $request  Matched request.
	 * @return mixed
	 */
	public static function before_callbacks( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );
		if ( self::is_batch_request( $request ) ) {
			self::open_batch_context( $request );
		} elseif ( self::is_cartpops_request( $request ) ) {
			self::consume_expectation( $request );
		}

		return $response;
	}

	/**
	 * Release all correlation state owned by one completed outer batch callback.
	 *
	 * @param mixed            $response Current callback response/error.
	 * @param array<mixed>     $handler  Matched route handler.
	 * @param \WP_REST_Request $request  Matched request.
	 * @return mixed
	 */
	public static function after_callbacks( $response, array $handler, \WP_REST_Request $request ) {
		unset( $handler );
		if ( self::is_batch_request( $request ) ) {
			self::close_batch_context( $request );
		}

		return $response;
	}

	/**
	 * Apply the namespace request policy to the final peer-produced value.
	 *
	 * @param mixed            $result  Peer-produced value.
	 * @param mixed            $server  REST server.
	 * @param \WP_REST_Request $request Current REST request.
	 * @return mixed|\WP_Error|\WP_REST_Response
	 */
	private static function normalize_request_result( $result, $server, \WP_REST_Request $request ) {

		$origin_error = self::origin_error( $request, null );
		if ( null !== $origin_error ) {
			return $origin_error;
		}

		if ( self::has_method_override( $request ) ) {
			return self::error( 'cartpops_method_override_rejected', __( 'REST method overrides are not supported.', 'cartpops' ), 400 );
		}
		if ( self::has_query_parameter( $request, '_jsonp' ) ) {
			return self::error( 'cartpops_jsonp_rejected', __( 'JSONP is not supported for the CartPops API.', 'cartpops' ), 400 );
		}

		$method = self::request_method( $request );
		$path   = self::request_path( $request );
		if ( 'OPTIONS' === $method ) {
			if ( self::raw_body_length( $request ) > 0 ) {
				return self::error( 'cartpops_request_too_large', __( 'The request body is too large.', 'cartpops' ), 413 );
			}
			return self::options_response( $path, $server );
		}

		$policy = NamespaceRequestPolicy::find( $method, $path );
		if ( null === $policy ) {
			$status = array() === NamespaceRequestPolicy::for_path( $path ) ? 404 : 405;
			return self::error( 'cartpops_rest_policy_rejected', __( 'No CartPops REST policy permits this request.', 'cartpops' ), $status );
		}

		if ( self::raw_body_length( $request ) > $policy->raw_body_cap ) {
			return self::error( 'cartpops_request_too_large', __( 'The request body is too large.', 'cartpops' ), 413 );
		}

		if ( $result instanceof \WP_Error ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && is_int( $data['status'] ?? null )
				? $data['status']
				: 500;

			return self::pre_dispatch_error( $status );
		}

		if ( $result instanceof \WP_HTTP_Response ) {
			$status = $result->get_status();
			if ( $status >= 400 && $status <= 599 ) {
				return self::pre_dispatch_error( $status );
			}

			if ( $status >= 100 && $status < 400 ) {
				return null;
			}

			return self::pre_dispatch_error( 500 );
		}

		// WordPress short-circuits on any non-empty rest_pre_dispatch result.
		// An untyped value cannot be trusted as an explicit denial, so normalize
		// every such shape to null. Route matching, permissions, rate limiting,
		// and the controller remain the sole successful dispatch path.
		return null;
	}

	/**
	 * Remember one request expectation weakly and, inside a real batch callback,
	 * append its value-free clone correlation to the bounded current FIFO.
	 *
	 * @param \WP_REST_Request         $request     Exact pre-dispatch request.
	 * @param RestPreDispatchFinalizer $expectation Value-free expected outcome.
	 */
	private static function remember_expectation(
		\WP_REST_Request $request,
		RestPreDispatchFinalizer $expectation
	): void {
		if ( null === self::$request_expectations ) {
			self::$request_expectations = new \WeakMap();
		}
		self::$request_expectations[ $request ] = $expectation;
		self::prune_batch_contexts();

		$index = array_key_last( self::$batch_contexts );
		if ( null === $index ) {
			return;
		}
		if ( count( self::$batch_contexts[ $index ]['expectations'] ) >= self::MAX_BATCH_EXPECTATIONS ) {
			self::$batch_contexts[ $index ]['overflow'] = true;
			return;
		}
		self::$batch_contexts[ $index ]['expectations'][] = $expectation;
	}

	/**
	 * Consume exact-object state first, then the active batch clone FIFO.
	 *
	 * @param \WP_REST_Request $request Callback or post-dispatch request.
	 */
	private static function consume_expectation( \WP_REST_Request $request ): ?RestPreDispatchFinalizer {
		self::prune_batch_contexts();
		if ( null !== self::$request_expectations && isset( self::$request_expectations[ $request ] ) ) {
			$expectation = self::$request_expectations[ $request ];
			unset( self::$request_expectations[ $request ] );
			self::remove_batch_expectation( $expectation );
			return $expectation;
		}

		$index = array_key_last( self::$batch_contexts );
		if ( null === $index ) {
			return null;
		}
		foreach ( self::$batch_contexts[ $index ]['expectations'] as $position => $expectation ) {
			if ( ! $expectation->matches( $request ) ) {
				continue;
			}
			array_splice( self::$batch_contexts[ $index ]['expectations'], $position, 1 );
			return $expectation;
		}

		return self::$batch_contexts[ $index ]['overflow']
			? RestPreDispatchFinalizer::failed( $request )
			: null;
	}

	/**
	 * Start one exact outer WordPress or WooCommerce batch callback lifecycle.
	 *
	 * @param \WP_REST_Request $request Exact outer batch request.
	 */
	private static function open_batch_context( \WP_REST_Request $request ): void {
		self::prune_batch_contexts();
		if ( count( self::$batch_contexts ) >= self::MAX_BATCH_DEPTH ) {
			$index = array_key_last( self::$batch_contexts );
			if ( null !== $index ) {
				self::$batch_contexts[ $index ]['overflow'] = true;
			}
			return;
		}
		self::$batch_contexts[] = array(
			'request'      => \WeakReference::create( $request ),
			'expectations' => array(),
			'overflow'     => false,
		);
	}

	/**
	 * Close one exact outer batch callback and discard every unused correlation.
	 *
	 * @param \WP_REST_Request $request Exact outer batch request.
	 */
	private static function close_batch_context( \WP_REST_Request $request ): void {
		for ( $index = count( self::$batch_contexts ) - 1; $index >= 0; --$index ) {
			if ( $request === self::$batch_contexts[ $index ]['request']->get() ) {
				array_splice( self::$batch_contexts, $index, 1 );
				break;
			}
		}
		self::prune_batch_contexts();
	}

	/** Remove dead outer requests without retaining a PHP request lifecycle. */
	private static function prune_batch_contexts(): void {
		self::$batch_contexts = array_values(
			array_filter(
				self::$batch_contexts,
				static fn( array $context ): bool => null !== $context['request']->get()
			)
		);
	}

	/**
	 * Remove one exact expectation from every live batch context.
	 *
	 * @param RestPreDispatchFinalizer $expectation Exact expectation to remove.
	 */
	private static function remove_batch_expectation( RestPreDispatchFinalizer $expectation ): void {
		foreach ( self::$batch_contexts as $index => $context ) {
			foreach ( $context['expectations'] as $position => $candidate ) {
				if ( $candidate === $expectation ) {
					array_splice( self::$batch_contexts[ $index ]['expectations'], $position, 1 );
					break;
				}
			}
		}
	}

	/**
	 * Whether core is executing a supported outer batch route callback.
	 *
	 * @param \WP_REST_Request $request Candidate outer batch request.
	 */
	private static function is_batch_request( \WP_REST_Request $request ): bool {
		return in_array( self::request_path( $request ), array( '/batch/v1', '/wc/store/v1/batch' ), true );
	}

	/**
	 * Return one value-free, bounded denial for an earlier dispatch result.
	 *
	 * @param int $status Candidate HTTP status.
	 */
	private static function pre_dispatch_error( int $status ): \WP_Error {
		if ( $status < 400 || $status > 599 ) {
			$status = 500;
		}

		return self::error(
			'cartpops_pre_dispatch_error',
			__( 'The REST request was rejected.', 'cartpops' ),
			$status
		);
	}

	/**
	 * Backward-compatible origin-only seam used by existing security probes.
	 *
	 * @param mixed            $result           Earlier result.
	 * @param mixed            $server           REST server.
	 * @param \WP_REST_Request $request          REST request.
	 * @param string|null      $allowed_site_url Optional exact allowed-site seam.
	 * @return mixed
	 */
	public static function reject_cross_origin(
		$result,
		$server,
		\WP_REST_Request $request,
		?string $allowed_site_url = null
	) {
		unset( $server );
		if ( ! self::is_cartpops_request( $request ) ) {
			return $result;
		}

		$error = self::origin_error(
			$request,
			null === $allowed_site_url ? null : array( $allowed_site_url )
		);
		return null === $error ? $result : $error;
	}

	/**
	 * Add private/no-store and append Vary values on session-derived results and
	 * errors without touching other REST namespaces.
	 *
	 * @param mixed            $response REST response.
	 * @param mixed            $server   REST server (unused).
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public static function apply_response_policy( $response, $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( ! self::is_cartpops_request( $request ) ) {
			return $response;
		}
		$expectation = self::consume_expectation( $request );
		if ( null !== $expectation ) {
			$response = $expectation->served_response( $response );
		}
		if ( ! is_object( $response ) || ! is_callable( array( $response, 'header' ) ) ) {
			return $response;
		}

		$private = self::request_is_private( $request );
		$vary    = self::response_header( $response, 'Vary' );
		$vary    = self::merge_header_tokens( $vary, $private ? array( 'Cookie', 'Authorization', 'Origin' ) : array( 'Origin' ) );
		$response->header( 'Vary', $vary );

		if ( $private ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
		}

		return $response;
	}

	/**
	 * Replace WordPress's reflected CORS response with an exact allowlist. Vary
	 * values already attached to the response are merged, never discarded.
	 *
	 * @param bool             $served           Whether already served.
	 * @param mixed            $result           REST response.
	 * @param \WP_REST_Request $request          REST request.
	 * @param mixed            $server           REST server.
	 * @param string|null      $allowed_site_url Optional compatibility test seam.
	 */
	public static function send_headers(
		bool $served,
		$result,
		\WP_REST_Request $request,
		$server,
		?string $allowed_site_url = null
	): bool {
		if ( ! self::is_cartpops_request( $request ) ) {
			return $served;
		}

		foreach ( self::cors_header_names() as $header ) {
			$server->remove_header( $header );
		}

		$vary = is_object( $result ) ? self::response_header( $result, 'Vary' ) : '';
		foreach ( headers_list() as $queued_header ) {
			if ( is_string( $queued_header ) && 0 === stripos( $queued_header, 'Vary:' ) ) {
				$vary = self::merge_header_tokens( $vary, array( trim( substr( $queued_header, 5 ) ) ) );
			}
		}
		$server->send_header( 'Vary', self::merge_header_tokens( $vary, array( 'Origin' ) ) );
		if ( self::request_is_private( $request ) ) {
			// Core may emit generic nocache_headers() after rest_post_dispatch;
			// reassert the stronger namespace policy at the later serve hook.
			$server->send_header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
			$server->send_header( 'Pragma', 'no-cache' );
			$server->send_header( 'Expires', '0' );
		}

		$origin  = $request->get_header( 'Origin' );
		$allowed = null === $allowed_site_url ? null : array( $allowed_site_url );
		$exact   = is_string( $origin ) ? self::allowed_origin( $origin, $allowed ) : null;
		if ( null === $exact ) {
			return $served;
		}

		$path     = self::request_path( $request );
		$policies = NamespaceRequestPolicy::for_path( $path );
		$method   = self::request_method( $request );
		$current  = 'OPTIONS' === $method ? reset( $policies ) : NamespaceRequestPolicy::find( $method, $path );
		if (
			! $current instanceof RestRoutePolicy
			|| ! self::route_is_registered( $path, $server )
		) {
			return $served;
		}

		$methods   = array_map(
			static fn( RestRoutePolicy $policy ): string => $policy->method,
			$policies
		);
		$methods[] = 'OPTIONS';
		$methods   = array_values( array_unique( $methods ) );

		$server->send_header( 'Access-Control-Allow-Origin', $exact );
		$server->send_header( 'Access-Control-Allow-Credentials', 'true' );
		$server->send_header( 'Access-Control-Allow-Methods', implode( ', ', $methods ) );
		$server->send_header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, Cart-Token' );

		return $served;
	}

	/**
	 * Return every CORS response header that core or plugins may have queued.
	 *
	 * @return string[]
	 */
	private static function cors_header_names(): array {
		return array(
			'Access-Control-Allow-Origin',
			'Access-Control-Allow-Credentials',
			'Access-Control-Allow-Methods',
			'Access-Control-Allow-Headers',
			'Access-Control-Expose-Headers',
			'Access-Control-Max-Age',
		);
	}

	/**
	 * Whether the request belongs to the closed CartPops namespace.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function is_cartpops_request( \WP_REST_Request $request ): bool {
		$path = self::request_path( $request );
		return self::is_namespace_path( $path );
	}

	/**
	 * Whether early REST globals identify the closed CartPops namespace.
	 *
	 * @global \WP|null $wp WordPress rewrite state.
	 */
	private static function is_global_namespace_request(): bool {
		global $wp;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only route classification before REST dispatch; authority is enforced later.
		$route_query = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] )
			? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) )
			: null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$candidates = array( $route_query );
		if ( is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ) {
			$candidates[] = $wp->query_vars['rest_route'] ?? null;
		}
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && self::is_namespace_path( $candidate ) ) {
				return true;
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		if ( ! is_string( $request_uri ) || '' === $request_uri || strlen( $request_uri ) > 8192 ) {
			return false;
		}
		$path = function_exists( 'wp_parse_url' )
			? wp_parse_url( $request_uri, PHP_URL_PATH )
			: parse_url( $request_uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit fallback; runtime uses wp_parse_url().
		if ( ! is_string( $path ) ) {
			return false;
		}
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			return false;
		}
		$needle = '/' . trim( $prefix, '/' ) . NamespaceRequestPolicy::ROUTE_ROOT;
		return str_contains( $path, $needle . '/' ) || str_ends_with( $path, $needle );
	}

	/**
	 * Whether one path belongs to the closed namespace root.
	 *
	 * @param string $path Candidate request path.
	 */
	private static function is_namespace_path( string $path ): bool {
		return NamespaceRequestPolicy::ROUTE_ROOT === $path
			|| str_starts_with( $path, NamespaceRequestPolicy::ROUTE_ROOT . '/' );
	}

	/**
	 * Return the request route or an invalid empty sentinel.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function request_path( \WP_REST_Request $request ): string {
		if ( ! method_exists( $request, 'get_route' ) ) {
			return '';
		}
		$path = $request->get_route();
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Return the normalized request method or an invalid empty sentinel.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function request_method( \WP_REST_Request $request ): string {
		if ( ! method_exists( $request, 'get_method' ) ) {
			return '';
		}
		$method = $request->get_method();
		return is_string( $method ) ? strtoupper( $method ) : '';
	}

	/**
	 * Whether a response or error can relate to session/admin state.
	 *
	 * Unknown methods inherit the strictest policy for their known path. An
	 * unknown namespace path fails closed as private because no route manifest
	 * entry can prove that its response is globally cacheable.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function request_is_private( \WP_REST_Request $request ): bool {
		$method = self::request_method( $request );
		if ( 'OPTIONS' === $method ) {
			return true;
		}

		$path   = self::request_path( $request );
		$policy = NamespaceRequestPolicy::find( $method, $path );
		if ( null !== $policy ) {
			return $policy->is_private();
		}

		$path_policies = NamespaceRequestPolicy::for_path( $path );
		if ( array() === $path_policies ) {
			return true;
		}
		foreach ( $path_policies as $path_policy ) {
			if ( $path_policy->is_private() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return raw request-body bytes without parsing JSON.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function raw_body_length( \WP_REST_Request $request ): int {
		if ( ! method_exists( $request, 'get_body' ) ) {
			return 0;
		}
		$body = $request->get_body();
		return is_string( $body ) ? strlen( $body ) : 0;
	}

	/**
	 * Whether a header or query parameter attempts to override the method.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function has_method_override( \WP_REST_Request $request ): bool {
		$header = $request->get_header( 'X-HTTP-Method-Override' );
		return ( is_string( $header ) && '' !== $header ) || self::has_query_parameter( $request, '_method' );
	}

	/**
	 * Whether an exact query key is present, including an empty value.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $key     Exact query key.
	 */
	private static function has_query_parameter( \WP_REST_Request $request, string $key ): bool {
		if ( method_exists( $request, 'get_query_params' ) ) {
			$params = $request->get_query_params();
			return is_array( $params ) && array_key_exists( $key, $params );
		}
		return null !== $request->get_param( $key );
	}

	/**
	 * Validate Origin and Referer independently and reject disagreement.
	 *
	 * @param \WP_REST_Request $request          REST request.
	 * @param string[]|null    $allowed_override Optional exact allowlist seam.
	 */
	private static function origin_error( \WP_REST_Request $request, ?array $allowed_override ): ?\WP_Error {
		$origin  = $request->get_header( 'Origin' );
		$referer = $request->get_header( 'Referer' );
		$exact   = null;

		if ( null !== $origin && '' !== $origin ) {
			$exact = is_string( $origin ) ? self::allowed_origin( $origin, $allowed_override ) : null;
			if ( null === $exact ) {
				return self::error( 'cartpops_forbidden_origin', __( 'This origin is not allowed to access the CartPops API.', 'cartpops' ), 403 );
			}
		}

		if ( null !== $referer && '' !== $referer ) {
			$referer_origin = is_string( $referer ) ? self::normalize_url_origin( $referer, false ) : null;
			if ( null === $referer_origin || ! self::origin_is_allowlisted( $referer_origin, $allowed_override ) ) {
				return self::error( 'cartpops_forbidden_origin', __( 'This origin is not allowed to access the CartPops API.', 'cartpops' ), 403 );
			}
			if ( null !== $exact && ! hash_equals( $exact, $referer_origin ) ) {
				return self::error( 'cartpops_origin_mismatch', __( 'The request origin does not match its referrer.', 'cartpops' ), 403 );
			}
		}

		return null;
	}

	/**
	 * Return an exact allowlisted normalized Origin header.
	 *
	 * @param string        $origin           Raw Origin header.
	 * @param string[]|null $allowed_override Optional exact allowlist seam.
	 */
	private static function allowed_origin( string $origin, ?array $allowed_override ): ?string {
		if ( 'null' === strtolower( $origin ) ) {
			return null;
		}
		$normalized = self::normalize_url_origin( $origin, true );
		return null !== $normalized && self::origin_is_allowlisted( $normalized, $allowed_override )
			? $normalized
			: null;
	}

	/**
	 * Whether a normalized origin occurs in the exact allowlist.
	 *
	 * @param string        $origin           Normalized origin.
	 * @param string[]|null $allowed_override Optional exact allowlist seam.
	 */
	private static function origin_is_allowlisted( string $origin, ?array $allowed_override ): bool {
		foreach ( self::allowed_origins( $allowed_override ) as $allowed ) {
			if ( hash_equals( $allowed, $origin ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The filter is intentionally narrow: values must be absolute HTTP(S) URLs
	 * and are reduced to exact scheme/host/port origins.
	 *
	 * @param string[]|null $allowed_override Test/compatibility override.
	 * @return string[]
	 */
	private static function allowed_origins( ?array $allowed_override ): array {
		$urls = $allowed_override;
		if ( null === $urls ) {
			$urls = array();
			if ( function_exists( 'home_url' ) ) {
				$urls[] = (string) home_url( '/' );
			}
			if ( function_exists( 'site_url' ) ) {
				$urls[] = (string) site_url( '/' );
			}
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'cartpops_rest_allowed_origins', $urls );
				$urls     = is_array( $filtered ) ? $filtered : array();
			}
		}
		if ( count( $urls ) > 16 ) {
			return array();
		}

		$origins = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || strlen( $url ) > 2048 ) {
				continue;
			}
			$origin = self::normalize_url_origin( $url, false );
			if ( null !== $origin ) {
				$origins[] = $origin;
			}
		}
		return array_values( array_unique( $origins ) );
	}

	/**
	 * Normalize an HTTP(S) URL to exact scheme, host, and non-default port.
	 *
	 * @param string $url           Raw URL or Origin value.
	 * @param bool   $origin_header Whether Origin's stricter no-path grammar applies.
	 */
	private static function normalize_url_origin( string $url, bool $origin_header ): ?string {
		if ( '' === $url || trim( $url ) !== $url ) {
			return null;
		}
		$parts = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url )
			: parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit fallback; runtime uses wp_parse_url().
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return null;
		}
		if (
			$origin_header
			&& ( isset( $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) )
		) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( null !== $port && ( $port < 1 || $port > 65535 ) ) {
			return null;
		}
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}
		return $scheme . '://' . $host . ( null === $port ? '' : ':' . $port );
	}

	/**
	 * Answer preflight from the policy map without invoking a route callback.
	 *
	 * @param string $path   Exact namespace route path.
	 * @param mixed  $server REST server.
	 * @return mixed
	 */
	private static function options_response( string $path, mixed $server ): mixed {
		$policies = NamespaceRequestPolicy::for_path( $path );
		if ( array() === $policies || ! self::route_is_registered( $path, $server ) ) {
			return self::error( 'cartpops_rest_policy_rejected', __( 'No CartPops REST policy permits this request.', 'cartpops' ), 404 );
		}

		$methods  = array_values(
			array_unique( array_map( static fn( RestRoutePolicy $policy ): string => $policy->method, $policies ) )
		);
		$response = new \WP_REST_Response( array( 'methods' => $methods ), 200 );
		if ( is_callable( array( $response, 'header' ) ) ) {
			$response->header( 'Allow', implode( ', ', $methods ) );
		}
		return $response;
	}

	/**
	 * Guard preflight against physically absent future-edition routes.
	 *
	 * @param string $path   Exact namespace route path.
	 * @param mixed  $server REST server.
	 */
	private static function route_is_registered( string $path, mixed $server ): bool {
		if ( ! is_object( $server ) || ! is_callable( array( $server, 'get_routes' ) ) ) {
			return true;
		}
		$routes = $server->get_routes();
		if ( ! is_array( $routes ) ) {
			return false;
		}
		foreach ( array_keys( $routes ) as $route ) {
			if ( is_string( $route ) && 1 === preg_match( '@^' . $route . '$@i', $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a value-free REST error.
	 *
	 * @param string $code    Stable public error code.
	 * @param string $message Already translated public message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Read one response header case-insensitively.
	 *
	 * @param object $response REST response.
	 * @param string $name     Header name.
	 */
	private static function response_header( object $response, string $name ): string {
		if ( ! is_callable( array( $response, 'get_headers' ) ) ) {
			return '';
		}
		$headers = $response->get_headers();
		if ( ! is_array( $headers ) ) {
			return '';
		}
		foreach ( $headers as $key => $value ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, $name ) && is_string( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Append header tokens case-insensitively while preserving existing values.
	 *
	 * @param string   $existing Existing comma-separated tokens.
	 * @param string[] $append   Tokens to append.
	 */
	private static function merge_header_tokens( string $existing, array $append ): string {
		$tokens = array();
		foreach ( array_merge( explode( ',', $existing ), $append ) as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}
			$lower = strtolower( $token );
			if ( ! isset( $tokens[ $lower ] ) ) {
				$tokens[ $lower ] = $token;
			}
		}
		return implode( ', ', array_values( $tokens ) );
	}

	/**
	 * Register one callback only when the identical hook is not already present.
	 *
	 * @param string     $hook          Filter name.
	 * @param callable   $callback      Callback.
	 * @param int|string $priority      Hook priority.
	 * @param int        $accepted_args Accepted argument count.
	 */
	private static function add_filter_once( string $hook, callable $callback, int|string $priority, int $accepted_args ): void {
		if ( function_exists( 'has_filter' ) && false !== has_filter( $hook, $callback ) ) {
			return;
		}
		if ( ! function_exists( 'has_filter' ) ) {
			global $wp_filters;
			foreach ( is_array( $wp_filters[ $hook ] ?? null ) ? $wp_filters[ $hook ] : array() as $registered ) {
				if ( ( $registered['callback'] ?? null ) === $callback ) {
					return;
				}
			}
		}
		call_user_func_array( 'add_filter', array( $hook, $callback, $priority, $accepted_args ) );
	}
}
