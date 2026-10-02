<?php
/**
 * Shared authority guard for public CartPops REST endpoints.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/**
 * Require a signed, session-bound WooCommerce Cart-Token on public endpoints.
 *
 * Anonymous WordPress REST nonces all use user ID zero and therefore are not
 * visitor authority. A valid logged-in cookie still requires its wp_rest nonce
 * as an additional WordPress cookie-authentication check.
 */
final class PublicEndpointGuard {

	private const NONCE_ACTION = 'wp_rest';

	private const INVALID_NONCE_CODE = 'cartpops_invalid_nonce';

	private const INVALID_CART_TOKEN_CODE = 'cartpops_invalid_cart_token';

	/**
	 * Validate the request's signed Cart-Token and any logged-in REST nonce.
	 *
	 * @param \WP_REST_Request         $request   Incoming request.
	 * @param CartTokenAuthority|null  $authority Optional Woo boundary for tests.
	 * @param WooSessionBootstrap|null $bootstrap Optional session boundary for tests.
	 * @return true|\WP_Error
	 */
	public static function verify_session(
		\WP_REST_Request $request,
		?CartTokenAuthority $authority = null,
		?WooSessionBootstrap $bootstrap = null
	): bool|\WP_Error {
		$bootstrap = $bootstrap ?? new WooSessionBootstrap();
		$outcome   = $bootstrap->prepare_outcome( $request );
		if ( WooSessionBootstrapOutcome::INVALID_CREDENTIAL === $outcome ) {
			return new \WP_Error(
				self::INVALID_CART_TOKEN_CODE,
				__( 'Your cart session could not be verified. Please refresh and try again.', 'cartpops' ),
				array( 'status' => 403 )
			);
		}
		if ( WooSessionBootstrapOutcome::READY !== $outcome ) {
			return new \WP_Error(
				self::INVALID_CART_TOKEN_CODE,
				__( 'Your cart session is unavailable. Please refresh and try again.', 'cartpops' ),
				array( 'status' => 503 )
			);
		}

		$token     = $request->get_header( 'Cart-Token' );
		$authority = $authority ?? new CartTokenAuthority();

		if ( ! is_string( $token ) || ! $authority->validate( $token ) ) {
			return new \WP_Error(
				self::INVALID_CART_TOKEN_CODE,
				__( 'Your cart session could not be verified. Please refresh and try again.', 'cartpops' ),
				array( 'status' => 403 )
			);
		}

		$authenticated_user_id = self::authenticated_cookie_user_id();
		$nonce                 = $request->get_header( 'X-WP-Nonce' );
		if ( $authenticated_user_id > 0 ) {
			if (
				! is_string( $nonce )
				|| ! wp_verify_nonce( $nonce, self::NONCE_ACTION )
				|| get_current_user_id() !== $authenticated_user_id
			) {
				return new \WP_Error(
					self::INVALID_NONCE_CODE,
					__( 'Cookie check failed. Please refresh and try again.', 'cartpops' ),
					array( 'status' => 403 )
				);
			}
		} elseif ( is_string( $nonce ) && '' !== $nonce ) {
			// Anonymous REST nonces are site-wide user-zero values, never
			// CartPops authority. Reject a supplied/stale header so the client
			// refreshes live cookie state and retries once without that header.
			return new \WP_Error(
				self::INVALID_NONCE_CODE,
				__( 'Cookie check failed. Please refresh and try again.', 'cartpops' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Backward-compatible callback name used by existing route registrations.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	public static function verify_nonce( \WP_REST_Request $request ): bool|\WP_Error {
		return self::verify_session( $request );
	}

	/**
	 * Validate the logged-in cookie independently of REST nonce state.
	 *
	 * Core resets the current user to zero when a cookie-authenticated REST
	 * request omits its nonce. Validating the cookie directly ensures such a
	 * request cannot silently fall back to anonymous Cart-Token authority.
	 */
	private static function authenticated_cookie_user_id(): int {
		if ( function_exists( 'wp_validate_auth_cookie' ) ) {
			$user_id = wp_validate_auth_cookie( '', 'logged_in' );
			return is_int( $user_id ) && $user_id > 0 ? $user_id : 0;
		}

		return is_user_logged_in() ? get_current_user_id() : 0;
	}
}
