<?php
/**
 * WooCommerce Cart-Token validation bound to the active session.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\REST;

/**
 * Validate a WooCommerce Store API Cart-Token for the active WC session.
 *
 * WooCommerce 9 exposes JsonWebToken directly. Newer releases expose the
 * narrower CartTokenUtils API. This adapter uses the narrow API when present
 * and otherwise calls the WC 9 API with WooCommerce's own signing secret.
 */
final class CartTokenAuthority {

	private const MAX_TOKEN_LENGTH = 2048;

	private const MODERN_UTILITY = 'Automattic\\WooCommerce\\StoreApi\\Utilities\\CartTokenUtils';

	private const LEGACY_JWT = 'Automattic\\WooCommerce\\StoreApi\\Utilities\\JsonWebToken';

	/**
	 * Active Woo customer ID resolver.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $customer_id_resolver;

	/**
	 * WordPress salt resolver.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $salt_resolver;

	/**
	 * Current WooCommerce token utility class.
	 *
	 * @var class-string
	 */
	private string $modern_utility;

	/**
	 * WooCommerce 9 JSON Web Token utility class.
	 *
	 * @var class-string
	 */
	private string $legacy_jwt;

	/**
	 * Set external WooCommerce and WordPress boundaries.
	 *
	 * @param (callable(): string)|null $customer_id_resolver Active WC customer ID resolver.
	 * @param (callable(): string)|null $salt_resolver        WordPress salt resolver.
	 * @param class-string|null         $modern_utility       Current WooCommerce token utility.
	 * @param class-string|null         $legacy_jwt           WC 9 JSON Web Token utility.
	 */
	public function __construct(
		?callable $customer_id_resolver = null,
		?callable $salt_resolver = null,
		?string $modern_utility = null,
		?string $legacy_jwt = null
	) {
		$this->customer_id_resolver = \Closure::fromCallable(
			$customer_id_resolver ?? array( self::class, 'resolve_customer_id' )
		);
		$this->salt_resolver        = \Closure::fromCallable(
			$salt_resolver ?? static fn(): string => function_exists( 'wp_salt' ) ? (string) wp_salt() : ''
		);
		$this->modern_utility       = $modern_utility ?? self::MODERN_UTILITY;
		$this->legacy_jwt           = $legacy_jwt ?? self::LEGACY_JWT;
	}

	/**
	 * Validate signature, expiry, Store API issuer, and exact session binding.
	 *
	 * @param string $token WooCommerce Cart-Token header.
	 */
	public function validate( string $token ): bool {
		$untrusted_payload = $this->decode_structurally_valid_payload( $token );
		if ( null === $untrusted_payload ) {
			return false;
		}

		$active_customer_id = ( $this->customer_id_resolver )();
		if ( '' === $active_customer_id || strlen( $active_customer_id ) > 128 ) {
			return false;
		}

		$verified_payload = $this->verify_with_woocommerce( $token, $untrusted_payload );
		if ( null === $verified_payload || $verified_payload !== $untrusted_payload ) {
			return false;
		}

		return hash_equals( $active_customer_id, $verified_payload['user_id'] );
	}

	/**
	 * Resolve the active WooCommerce customer/session ID.
	 */
	private static function resolve_customer_id(): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		$woocommerce = WC();
		$session     = is_object( $woocommerce ) ? ( $woocommerce->session ?? null ) : null;
		if ( ! is_object( $session ) ) {
			return '';
		}

		$customer_id = $session->get_customer_id();
		return is_string( $customer_id ) || is_int( $customer_id ) ? (string) $customer_id : '';
	}

	/**
	 * Ask the installed WooCommerce version to validate the signature.
	 *
	 * @param string                                        $token                      WooCommerce Cart-Token header.
	 * @param array{user_id: string, exp: int, iss: string} $structurally_valid_payload Strictly parsed JWT payload.
	 * @return array{user_id: string, exp: int, iss: string}|null
	 */
	private function verify_with_woocommerce( string $token, array $structurally_valid_payload ): ?array {
		try {
			if ( class_exists( $this->modern_utility ) ) {
				if (
					! is_callable( array( $this->modern_utility, 'validate_cart_token' ) )
					|| ! ( $this->modern_utility )::validate_cart_token( $token )
				) {
					return null;
				}

				// Some intermediate or vendor-patched builds may expose the modern
				// validator without its payload accessor. Signature validation
				// authenticates the exact encoded payload already parsed above, so the
				// strictly normalized value remains safe on those capability surfaces.
				if ( ! is_callable( array( $this->modern_utility, 'get_cart_token_payload' ) ) ) {
					return $structurally_valid_payload;
				}

				$payload = ( $this->modern_utility )::get_cart_token_payload( $token );
				return is_array( $payload ) ? $this->normalize_payload( $payload ) : null;
			}

			if (
				! class_exists( $this->legacy_jwt )
				|| ! is_callable( array( $this->legacy_jwt, 'validate' ) )
				|| ! is_callable( array( $this->legacy_jwt, 'get_parts' ) )
			) {
				return null;
			}

			$salt = ( $this->salt_resolver )();
			if ( '' === $salt || ! ( $this->legacy_jwt )::validate( $token, '@' . $salt ) ) {
				return null;
			}

			$parts = ( $this->legacy_jwt )::get_parts( $token );
			if ( ! is_object( $parts ) || ! isset( $parts->payload ) || ! is_object( $parts->payload ) ) {
				return null;
			}

			return $this->normalize_payload( get_object_vars( $parts->payload ) );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Decode only enough untrusted JWT data to make calls into older WC safe.
	 * Signature authority always remains WooCommerce's implementation.
	 *
	 * @param string $token WooCommerce Cart-Token header.
	 * @return array{user_id: string, exp: int, iss: string}|null
	 */
	private function decode_structurally_valid_payload( string $token ): ?array {
		if (
			'' === $token
			|| strlen( $token ) > self::MAX_TOKEN_LENGTH
			|| 1 !== preg_match( '/^[a-zA-Z\d\-_=]+\.[a-zA-Z\d\-_=]+\.[a-zA-Z\d\-_=]+$/', $token )
		) {
			return null;
		}

		$segments = explode( '.', $token );
		$header   = $this->decode_segment( $segments[0] );
		$payload  = $this->decode_segment( $segments[1] );
		if (
			null === $header
			|| 'JWT' !== ( $header['typ'] ?? null )
			|| 'HS256' !== ( $header['alg'] ?? null )
			|| null === $payload
		) {
			return null;
		}

		return $this->normalize_payload( $payload );
	}

	/**
	 * Decode one base64url-encoded JSON object.
	 *
	 * @param string $segment Encoded JWT segment.
	 * @return array<string, mixed>|null
	 */
	private function decode_segment( string $segment ): ?array {
		$padding = strlen( $segment ) % 4;
		if ( 0 !== $padding ) {
			$segment .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( strtr( $segment, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required to validate WooCommerce's JWT format.
		if ( false === $decoded || strlen( $decoded ) > 2048 ) {
			return null;
		}

		$value = json_decode( $decoded, true );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Normalize the security-relevant token claims.
	 *
	 * @param array<string, mixed> $payload Decoded payload.
	 * @return array{user_id: string, exp: int, iss: string}|null
	 */
	private function normalize_payload( array $payload ): ?array {
		$user_id = $payload['user_id'] ?? null;
		$expiry  = $payload['exp'] ?? null;
		$issuer  = $payload['iss'] ?? null;

		if (
			! is_string( $user_id )
			|| '' === $user_id
			|| strlen( $user_id ) > 128
			|| preg_match( '/[\x00-\x20\x7f]/', $user_id )
			|| ! is_int( $expiry )
			|| time() > $expiry
			|| ! is_string( $issuer )
			|| ( 'store-api' !== $issuer && 1 !== preg_match( '#^wc/store/v[1-9][0-9]*$#', $issuer ) )
		) {
			return null;
		}

		return array(
			'user_id' => $user_id,
			'exp'     => $expiry,
			'iss'     => $issuer,
		);
	}
}
