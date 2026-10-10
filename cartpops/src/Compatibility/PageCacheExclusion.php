<?php
/**
 * Keep session-bound cart REST responses out of server page caches.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

/**
 * Mark Store API cart and CartPops REST responses as uncacheable.
 *
 * A Store API cart read carries the shopper's Cart-Token and nonce. LiteSpeed
 * Cache can cache REST GET responses despite WooCommerce's `no-store`, which
 * hands one guest's Cart-Token to every later visitor, so the drawer reads that
 * stranger's empty cart. LiteSpeed only honors its own no-cache signal.
 */
final class PageCacheExclusion {

	private const REASON = 'CartPops: session-bound cart response';

	/** Register the response hook. */
	public static function register(): void {
		add_filter( 'rest_post_dispatch', array( self::class, 'mark_response' ), 10, 3 );
	}

	/**
	 * Send LiteSpeed's no-cache signal for session-bound cart routes.
	 *
	 * @param mixed            $response REST response.
	 * @param mixed            $server   REST server (unused).
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public static function mark_response( $response, $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( ! self::is_session_bound_route( (string) $request->get_route() ) ) {
			return $response;
		}

		do_action( 'litespeed_control_set_nocache', self::REASON );
		if ( is_object( $response ) && is_callable( array( $response, 'header' ) ) ) {
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}

		return $response;
	}

	/**
	 * Whether a REST route returns data bound to the shopper's cart session.
	 *
	 * @param string $route REST route, for example `/wc/store/v1/cart`.
	 */
	public static function is_session_bound_route( string $route ): bool {
		return 1 === preg_match( '#^/(?:wc/store(?:/v\d+)?/(?:cart|batch)|cartpops/v\d+)(?:/|$)#D', $route );
	}
}
