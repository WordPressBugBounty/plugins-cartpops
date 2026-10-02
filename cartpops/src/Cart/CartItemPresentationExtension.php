<?php
/**
 * Bounded public cart-item presentation extension policy.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Cart;

/**
 * Keeps public cart-item callbacks presentation-only and JSON-safe.
 */
final class CartItemPresentationExtension {
	private const MAX_EXTRA_LINES          = 20;
	private const MAX_EXTRA_LABEL_BYTES    = 160;
	private const MAX_EXTRA_VALUE_BYTES    = 1000;
	private const MAX_WOO_ROW_FIELDS       = 8;
	private const MAX_WOO_FIELD_KEY_BYTES  = 64;
	private const MAX_NAME_BYTES           = 500;
	private const MAX_DESCRIPTION_BYTES    = 2000;
	private const MAX_VARIATION_BYTES      = 1000;
	private const MAX_CUSTOM_ROOT_FIELDS   = 16;
	private const MAX_CUSTOM_ENTRIES       = 16;
	private const MAX_CUSTOM_DEPTH         = 4;
	private const MAX_CUSTOM_NODES         = 64;
	private const MAX_CUSTOM_STRING_BYTES  = 1024;
	private const MAX_CUSTOM_ENCODED_BYTES = 8192;
	private const MAX_STORE_ITEMS          = 100;
	private const MAX_STORE_ENCODED_BYTES  = 1048576;
	private const MAX_SAFE_INTEGER         = 9007199254740991;
	private const CUSTOM_KEY_PATTERN       = '/\A[A-Za-z][A-Za-z0-9_.-]{0,63}\z/D';

	/**
	 * Accept only the documented public presentation fields.
	 *
	 * Every CartPops-owned field is copied from the authoritative item. Unknown
	 * root fields are ignored so a callback cannot create a future collision.
	 *
	 * @param array<string, mixed> $authoritative Server-owned cart item.
	 * @param mixed                $candidate     Public filter result.
	 * @return array<string, mixed>
	 */
	public static function sanitize_public_result( array $authoritative, mixed $candidate ): array {
		if ( ! is_array( $candidate ) ) {
			return $authoritative;
		}

		$result      = $authoritative;
		$text_fields = array(
			'name'              => self::MAX_NAME_BYTES,
			'short_description' => self::MAX_DESCRIPTION_BYTES,
			'variationSummary'  => self::MAX_VARIATION_BYTES,
		);
		foreach ( $text_fields as $field => $max_bytes ) {
			if ( ! array_key_exists( $field, $candidate ) || ! is_string( $candidate[ $field ] ) ) {
				continue;
			}

			$text = self::plain_text( $candidate[ $field ], $max_bytes );
			if ( null !== $text && ( 'name' !== $field || '' !== $text ) ) {
				$result[ $field ] = $text;
			}
		}

		if ( array_key_exists( 'extraLines', $candidate ) ) {
			$extra_lines = self::sanitize_extra_lines( $candidate['extraLines'] );
			if ( null !== $extra_lines ) {
				$result['extraLines'] = $extra_lines;
			}
		}

		if ( array_key_exists( 'customPresentation', $candidate ) ) {
			$custom = self::sanitize_custom_presentation( $candidate['customPresentation'] );
			if ( null !== $custom ) {
				$result['customPresentation'] = $custom;
			}
		}

		return $result;
	}

	/**
	 * Normalize customer-visible metadata from WooCommerce's standard item-data filter.
	 *
	 * The filter is invoked with an empty seed by CartStateBuilder, matching the
	 * Store API contract and avoiding a second copy of native variation details.
	 * Translation is deliberately left to the extension that authored each row.
	 *
	 * @param mixed $candidate Filtered WooCommerce item-data rows.
	 * @return list<array{label: string, value: string}>
	 */
	public static function sanitize_woocommerce_item_data( mixed $candidate ): array {
		if ( ! is_array( $candidate ) || count( $candidate ) > self::MAX_EXTRA_LINES ) {
			return array();
		}

		$result = array();
		foreach ( $candidate as $row ) {
			if ( ! is_array( $row ) || count( $row ) > self::MAX_WOO_ROW_FIELDS ) {
				continue;
			}

			$row_is_bounded = true;
			foreach ( $row as $field => $field_value ) {
				if (
					! is_string( $field )
					|| strlen( $field ) > self::MAX_WOO_FIELD_KEY_BYTES
					|| 1 !== preg_match( '//u', $field )
					|| ! self::is_bounded_woocommerce_scalar( $field_value )
				) {
					$row_is_bounded = false;
					break;
				}
			}
			if ( ! $row_is_bounded ) {
				continue;
			}

			// Honor both classic cart and WooCommerce Blocks visibility flags.
			if ( ! empty( $row['hidden'] ) || ! empty( $row['__experimental_woocommerce_blocks_hidden'] ) ) {
				continue;
			}

			$label = self::woocommerce_plain_text( $row['key'] ?? null, self::MAX_EXTRA_LABEL_BYTES );
			if ( null === $label || '' === $label ) {
				$label = self::woocommerce_plain_text( $row['name'] ?? null, self::MAX_EXTRA_LABEL_BYTES );
			}

			$value = self::woocommerce_plain_text( $row['display'] ?? null, self::MAX_EXTRA_VALUE_BYTES );
			if ( null === $value || '' === $value ) {
				$value = self::woocommerce_plain_text( $row['value'] ?? null, self::MAX_EXTRA_VALUE_BYTES );
			}

			if ( null === $label || '' === $label || null === $value || '' === $value ) {
				continue;
			}

			$result[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $result;
	}

	/**
	 * Accept the one edition-owned row-lock field after the public boundary.
	 *
	 * @param array<string, mixed> $authoritative Sanitized public presentation.
	 * @param mixed                $candidate     Closed collaborator result.
	 * @return array<string, mixed>
	 */
	public static function sanitize_projector_result( array $authoritative, mixed $candidate ): array {
		$result = $authoritative;
		if ( is_array( $candidate ) && is_bool( $candidate['optionalLocked'] ?? null ) ) {
			$result['optionalLocked'] = $candidate['optionalLocked'];
		}

		return $result;
	}

	/**
	 * Project one sanitized presentation map to its stable JSON object shape.
	 *
	 * PHP callbacks keep an array while they mutate the item. This final wire
	 * projection prevents the empty map from becoming a JSON list without
	 * changing any authoritative item field.
	 *
	 * @param array<string, mixed> $item Sanitized CartPops cart item.
	 * @return array<string, mixed>
	 */
	public static function for_json_transport( array $item ): array {
		$custom = $item['customPresentation'] ?? array();
		if ( ! is_array( $custom ) || ( array() !== $custom && array_is_list( $custom ) ) ) {
			$custom = array();
		}

		$item['customPresentation'] = (object) $custom;
		return $item;
	}

	/**
	 * Export only public presentation fields for a Store API cart response.
	 *
	 * @param array<int, mixed> $items Sanitized CartPops cart items.
	 * @return list<array<string, mixed>>
	 */
	public static function for_store_api( array $items ): array {
		if ( count( $items ) > self::MAX_STORE_ITEMS ) {
			return array();
		}

		$result    = array();
		$seen_keys = array();
		foreach ( $items as $item ) {
			$key = is_array( $item ) ? ( $item['key'] ?? null ) : null;
			if ( ! is_string( $key ) || ! CartLineAuthority::valid_key( $key ) ) {
				continue;
			}
			if ( isset( $seen_keys[ $key ] ) ) {
				return array();
			}
			$seen_keys[ $key ] = true;
			$authority         = CartLineAuthority::sanitize_projection( $item );
			if ( null === $authority ) {
				return array();
			}

			$projection  = array_merge( array( 'key' => $key ), $authority );
			$text_fields = array(
				'name'              => self::MAX_NAME_BYTES,
				'short_description' => self::MAX_DESCRIPTION_BYTES,
				'variationSummary'  => self::MAX_VARIATION_BYTES,
			);
			foreach ( $text_fields as $field => $max_bytes ) {
				$value = $item[ $field ] ?? null;
				if ( ! is_string( $value ) ) {
					continue;
				}

				$text = self::plain_text( $value, $max_bytes );
				if ( null !== $text && ( 'name' !== $field || '' !== $text ) ) {
					$projection[ $field ] = $text;
				}
			}

			$custom_source = $item['customPresentation'] ?? null;
			if ( $custom_source instanceof \stdClass ) {
				$custom_source = get_object_vars( $custom_source );
			}
			$extra_lines                      = self::sanitize_extra_lines( $item['extraLines'] ?? null );
			$custom                           = self::sanitize_custom_presentation( $custom_source );
			$projection['extraLines']         = $extra_lines ?? array();
			$projection['customPresentation'] = $custom ?? array();
			$result[]                         = self::for_json_transport( $projection );
		}

		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 16 );
		return false !== $encoded && strlen( $encoded ) <= self::MAX_STORE_ENCODED_BYTES
			? $result
			: array();
	}

	/**
	 * Export the exact bounded keys whose closed edition projection locks them.
	 *
	 * @param array<int, mixed> $items Sanitized CartPops cart items.
	 * @return list<string>
	 */
	public static function locked_keys_for_store_api( array $items ): array {
		if ( count( $items ) > self::MAX_STORE_ITEMS ) {
			return array();
		}

		$result    = array();
		$seen_keys = array();
		foreach ( $items as $item ) {
			$key             = is_array( $item ) ? ( $item['key'] ?? null ) : null;
			$optional_locked = is_array( $item ) ? ( $item['optionalLocked'] ?? null ) : null;
			if ( ! is_string( $key ) || ! CartLineAuthority::valid_key( $key ) ) {
				continue;
			}
			if ( ! is_bool( $optional_locked ) ) {
				return array();
			}
			if ( isset( $seen_keys[ $key ] ) ) {
				return array();
			}
			$seen_keys[ $key ] = true;
			if ( $optional_locked ) {
				$result[] = $key;
			}
		}

		return $result;
	}

	/**
	 * Normalize the built-in label/value rows as plain text.
	 *
	 * @param mixed $candidate Candidate row list.
	 * @return list<array{label: string, value: string}>|null
	 */
	private static function sanitize_extra_lines( mixed $candidate ): ?array {
		if ( ! is_array( $candidate ) || ! array_is_list( $candidate ) || count( $candidate ) > self::MAX_EXTRA_LINES ) {
			return null;
		}

		$result = array();
		foreach ( $candidate as $line ) {
			if (
				! is_array( $line )
				|| 2 !== count( $line )
				|| ! array_key_exists( 'label', $line )
				|| ! array_key_exists( 'value', $line )
				|| ! is_string( $line['label'] )
				|| ! is_string( $line['value'] )
			) {
				return null;
			}

			$label = self::plain_text( $line['label'], self::MAX_EXTRA_LABEL_BYTES );
			$value = self::plain_text( $line['value'], self::MAX_EXTRA_VALUE_BYTES );
			if ( null === $label || '' === $label || null === $value ) {
				return null;
			}

			$result[] = array(
				'label' => $label,
				'value' => $value,
			);
		}

		return $result;
	}

	/**
	 * Normalize the explicitly namespaced custom presentation map.
	 *
	 * @param mixed $candidate Candidate custom presentation.
	 * @return array<string, mixed>|null
	 */
	private static function sanitize_custom_presentation( mixed $candidate ): ?array {
		if (
			! is_array( $candidate )
			|| ( array() !== $candidate && array_is_list( $candidate ) )
			|| count( $candidate ) > self::MAX_CUSTOM_ROOT_FIELDS
		) {
			return null;
		}

		$nodes  = 0;
		$result = array();
		foreach ( $candidate as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( self::CUSTOM_KEY_PATTERN, $key ) ) {
				return null;
			}

			$valid          = true;
			$normalized     = self::normalize_json_value( $value, 1, $nodes, $valid );
			$result[ $key ] = $normalized;
			if ( ! $valid ) {
				return null;
			}
		}

		$encoded = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 16 );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_CUSTOM_ENCODED_BYTES ) {
			return null;
		}

		return $result;
	}

	/**
	 * Recursively copy only bounded JSON scalar, list, and object-map values.
	 *
	 * The depth fence also makes recursive PHP arrays fail closed without an
	 * unbounded traversal. Objects and resources are never coerced.
	 *
	 * @param mixed $candidate Candidate value.
	 * @param int   $depth     Current custom-presentation depth.
	 * @param int   $nodes     Shared node counter.
	 * @param bool  $valid     Shared validity result.
	 * @return mixed
	 */
	private static function normalize_json_value(
		mixed $candidate,
		int $depth,
		int &$nodes,
		bool &$valid
	): mixed {
		++$nodes;
		if ( $depth > self::MAX_CUSTOM_DEPTH || $nodes > self::MAX_CUSTOM_NODES ) {
			$valid = false;
			return null;
		}

		if ( null === $candidate || is_bool( $candidate ) ) {
			return $candidate;
		}
		if ( is_int( $candidate ) ) {
			if ( abs( $candidate ) > self::MAX_SAFE_INTEGER ) {
				$valid = false;
				return null;
			}
			return $candidate;
		}
		if ( is_float( $candidate ) ) {
			if ( ! is_finite( $candidate ) || abs( $candidate ) > self::MAX_SAFE_INTEGER ) {
				$valid = false;
				return null;
			}
			return $candidate;
		}
		if ( is_string( $candidate ) ) {
			$text = self::plain_text( $candidate, self::MAX_CUSTOM_STRING_BYTES );
			if ( null === $text ) {
				$valid = false;
				return null;
			}
			return $text;
		}
		if ( ! is_array( $candidate ) || count( $candidate ) > self::MAX_CUSTOM_ENTRIES ) {
			$valid = false;
			return null;
		}

		$is_list = array_is_list( $candidate );
		$result  = array();
		foreach ( $candidate as $key => $value ) {
			if (
				( $is_list && ! is_int( $key ) )
				|| ( ! $is_list && ( ! is_string( $key ) || 1 !== preg_match( self::CUSTOM_KEY_PATTERN, $key ) ) )
			) {
				$valid = false;
				return null;
			}

			$result[ $key ] = self::normalize_json_value( $value, $depth + 1, $nodes, $valid );
			if ( ! $valid ) {
				return null;
			}
		}

		return $result;
	}

	/**
	 * Normalize one bounded UTF-8 value that will only be exposed as text.
	 *
	 * @param string $value     Candidate text.
	 * @param int    $max_bytes Maximum raw and normalized bytes.
	 */
	private static function plain_text( string $value, int $max_bytes ): ?string {
		if ( strlen( $value ) > $max_bytes || 1 !== preg_match( '//u', $value ) ) {
			return null;
		}

		$text = wp_strip_all_tags( $value, true );
		$text = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		if ( null === $text ) {
			return null;
		}

		$text = trim( $text );
		return strlen( $text ) <= $max_bytes ? $text : null;
	}

	/**
	 * Determine whether a WooCommerce row value is safe to inspect.
	 *
	 * @param mixed $value Candidate row value.
	 */
	private static function is_bounded_woocommerce_scalar( mixed $value ): bool {
		if ( ! is_scalar( $value ) || ( is_float( $value ) && ! is_finite( $value ) ) ) {
			return false;
		}

		$text = (string) $value;
		return strlen( $text ) <= self::MAX_EXTRA_VALUE_BYTES && 1 === preg_match( '//u', $text );
	}

	/**
	 * Normalize one scalar WooCommerce label or value without translating it.
	 *
	 * @param mixed $value     Candidate label or value.
	 * @param int   $max_bytes Maximum normalized bytes.
	 */
	private static function woocommerce_plain_text( mixed $value, int $max_bytes ): ?string {
		if ( ! self::is_bounded_woocommerce_scalar( $value ) ) {
			return null;
		}

		$text = self::plain_text( (string) $value, $max_bytes );
		if ( null === $text ) {
			return null;
		}

		// Interactivity renders this as text, so decode WooCommerce's permitted
		// display entities instead of exposing their source (for example &times;).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if (
			strlen( $text ) > $max_bytes
			|| 1 !== preg_match( '//u', $text )
			|| 1 === preg_match( '/&#(?:[xX][0-9A-Fa-f]+|[0-9]+);/', $text )
		) {
			return null;
		}

		$text = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );
		if ( null === $text ) {
			return null;
		}

		$text = trim( $text );
		return strlen( $text ) <= $max_bytes ? $text : null;
	}
}
