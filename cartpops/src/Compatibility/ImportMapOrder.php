<?php
/**
 * Keeps WordPress' import map ahead of the first module load on classic themes.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Compatibility;

/**
 * Moves WordPress' footer import map in front of an earlier module load.
 *
 * Classic themes print the `wp-importmap` script in the footer. A module
 * script or module preload that a theme, page builder or embed prints earlier
 * in the page (a captcha in a footer newsletter form, for example) starts
 * module loading first. Browsers that enforce the import map order, such as
 * Firefox, then ignore the whole map, so every `@wordpress/interactivity`
 * import fails and the cart drawer never starts. Firefox also rejects a second
 * import map, so the only safe repair is to move the one complete map.
 *
 * The transform changes nothing unless exactly one `wp-importmap` exists and a
 * module script or module preload precedes it; it never edits the map itself.
 */
final class ImportMapOrder {
	private const MAP_ID = 'wp-importmap';

	/**
	 * Elements whose content is never parsed as tags (or never runs), so a
	 * module tag inside them cannot start module loading.
	 */
	private const INERT_CONTENT_ELEMENTS = array(
		'iframe',
		'noembed',
		'noframes',
		'noscript',
		'script',
		'style',
		'template',
		'textarea',
		'title',
		'xmp',
	);

	/**
	 * Output buffer callback.
	 *
	 * @param mixed $buffer Buffered response.
	 * @return mixed Reordered HTML, or the original value for anything else.
	 */
	public static function filter_buffer( mixed $buffer ): mixed {
		if ( ! is_string( $buffer ) ) {
			return $buffer;
		}

		return self::reorder( $buffer );
	}

	/**
	 * Move the import map in front of the first module script or preload.
	 *
	 * @param string $html Complete HTML document.
	 * @return string The document, unchanged unless the map was out of order.
	 */
	public static function reorder( string $html ): string {
		if ( '' === $html || 1 !== preg_match_all( '/\bid\s*=\s*["\']?' . self::MAP_ID . '["\'\s>]/i', $html ) ) {
			return $html;
		}

		$early_at = null;
		$position = 0;
		$length   = strlen( $html );

		while ( $position < $length ) {
			$open = strpos( $html, '<', $position );
			if ( false === $open ) {
				return $html;
			}

			if ( 0 === substr_compare( $html, '<!--', $open, 4 ) ) {
				$comment_end = strpos( $html, '-->', $open + 4 );
				if ( false === $comment_end ) {
					return $html;
				}
				$position = $comment_end + 3;
				continue;
			}

			if ( 1 !== preg_match( '/\G<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $html, $tag, 0, $open ) ) {
				$position = $open + 1;
				continue;
			}

			$name       = strtolower( $tag[1] );
			$attributes = self::attributes( $tag[2] );
			$tag_end    = $open + strlen( $tag[0] );

			if ( 'script' === $name ) {
				$type = strtolower( trim( $attributes['type'] ?? '' ) );
				if ( 'module' === $type ) {
					$early_at ??= $open;
				} elseif ( 'importmap' === $type && self::MAP_ID === ( $attributes['id'] ?? null ) ) {
					return null === $early_at ? $html : self::move_map( $html, $open, $tag_end, $early_at );
				}
			} elseif ( 'link' === $name && self::is_module_preload( $attributes['rel'] ?? '' ) ) {
				$early_at ??= $open;
			}

			if ( in_array( $name, self::INERT_CONTENT_ELEMENTS, true ) ) {
				$close = self::find_closing_tag( $html, $name, $tag_end );
				if ( null === $close ) {
					return $html;
				}
				$position = $close;
				continue;
			}

			$position = $tag_end;
		}

		return $html;
	}

	/**
	 * Move the complete map element to the first module load.
	 *
	 * @param string $html      Document.
	 * @param int    $map_start Offset of the map's opening tag.
	 * @param int    $map_body  Offset just after the map's opening tag.
	 * @param int    $early_at  Offset of the first module script or preload.
	 * @return string Reordered document, or the original for a malformed map.
	 */
	private static function move_map( string $html, int $map_start, int $map_body, int $early_at ): string {
		$map_end = self::find_closing_tag( $html, 'script', $map_body );
		if ( null === $map_end ) {
			return $html;
		}

		return substr( $html, 0, $early_at )
			. substr( $html, $map_start, $map_end - $map_start )
			. substr( $html, $early_at, $map_start - $early_at )
			. substr( $html, $map_end );
	}

	/**
	 * Find the offset just past an element's closing tag.
	 *
	 * @param string $html   Document.
	 * @param string $name   Lowercase element name.
	 * @param int    $offset Offset where the element's content starts.
	 * @return int|null Offset after the closing tag, or null when unclosed.
	 */
	private static function find_closing_tag( string $html, string $name, int $offset ): ?int {
		if ( 1 !== preg_match( '#</' . preg_quote( $name, '#' ) . '(?=[\s/>])[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			return null;
		}

		return $match[0][1] + strlen( $match[0][0] );
	}

	/**
	 * Parse a tag's attributes; the first occurrence of a name wins, as in HTML.
	 *
	 * @param string $source Attribute source between the tag name and `>`.
	 * @return array<string, string> Lowercase attribute names and raw values.
	 */
	private static function attributes( string $source ): array {
		$attributes = array();
		preg_match_all( '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $source, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );
			if ( ! array_key_exists( $name, $attributes ) ) {
				$attributes[ $name ] = $match[2] ?? '';
				if ( '' === $attributes[ $name ] ) {
					$attributes[ $name ] = ( $match[3] ?? '' ) . ( $match[4] ?? '' );
				}
			}
		}

		return $attributes;
	}

	/**
	 * Whether a `rel` value contains the modulepreload keyword.
	 *
	 * @param string $rel Raw rel value.
	 */
	private static function is_module_preload( string $rel ): bool {
		$tokens = preg_split( '/\s+/', strtolower( trim( $rel ) ) );

		return is_array( $tokens ) && in_array( 'modulepreload', $tokens, true );
	}
}
