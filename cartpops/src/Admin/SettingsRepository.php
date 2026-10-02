<?php
/**
 * Manages all CartPops settings in a single wp_option.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\Admin;

use CartPops\Compatibility\LegacyPoweredByLink;
use CartPops\Recommendations\RecommendationButtonPresentation;
use CartPops\Settings\SecondaryActionSettings;

/**
 * Manages all CartPops settings in a single wp_option.
 */
final class SettingsRepository {

	/** Maximum V1 floating-launcher page exclusions retained as live behavior. */
	public const MAX_HIDDEN_PAGE_IDS = 500;

	private const OPTION_KEY                 = 'cartpops_settings';
	private const MAX_TEXT_LENGTH            = 1000;
	private const MAX_IDENTIFIER_LENGTH      = 128;
	private const MAX_URL_LENGTH             = 2048;
	private const MAX_GENERIC_LIST_ITEMS     = 50;
	private const MAX_NOTIFICATION_ITEMS     = 100;
	private const MAX_RULE_CONDITIONS        = 20;
	private const MAX_RULE_VALUES            = 100;
	private const MAX_SHIPPING_TIERS         = 50;
	private const MAX_BUNDLE_UPSELLS         = 100;
	private const MAX_BUNDLE_COMPANIONS      = 50;
	private const MAX_SMART_ADDONS           = 100;
	private const MAX_CUSTOM_RECOMMENDATIONS = 4;
	private const MAX_CUSTOM_CSS_LENGTH      = 100000;
	private const MAX_MONEY_VALUE            = 1000000000.0;
	private const MAX_DECIMAL_PRECISION      = 6;
	private const DEFAULT_SMART_ADDON_PRICE  = 5.0;
	private const MINI_CART_MODES            = array( 'replace', 'standalone' );

	/**
	 * Resolve the canonical trigger while preserving retired alias behavior.
	 *
	 * @param mixed $value Candidate trigger.
	 */
	public static function normalize_trigger( mixed $value ): string {
		return match ( $value ) {
			'add_to_cart', 'both', 'drawer', 'popup', 'bar' => 'add_to_cart',
			'launcher', 'manual', 'none' => 'launcher',
			default => 'launcher',
		};
	}

	/**
	 * Resolve the exact Mini Cart mode understood by persistence and runtime.
	 *
	 * @param mixed $value Candidate mode.
	 */
	public static function normalize_mini_cart_mode( mixed $value ): string {
		return is_string( $value ) && in_array( $value, self::MINI_CART_MODES, true )
			? $value
			: 'replace';
	}

	/**
	 * In-memory cache of the merged settings array.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Blog identity owning the in-memory settings cache.
	 *
	 * @var int|null
	 */
	private ?int $settings_blog_id = null;

	/** Return a deterministic cache identity in WordPress and isolated tests. */
	private function current_blog_id(): int {
		if ( ! function_exists( 'get_current_blog_id' ) ) {
			return 0;
		}

		try {
			return get_current_blog_id();
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * Drop cached bytes whenever the active site no longer owns them.
	 *
	 * @param int $blog_id Captured current blog identity.
	 */
	private function reconcile_cache_blog( int $blog_id ): void {
		if ( null !== $this->settings_blog_id && $blog_id !== $this->settings_blog_id ) {
			$this->settings         = null;
			$this->settings_blog_id = null;
		}
	}

	/**
	 * Refuse to publish or persist data after an unexpected context switch.
	 *
	 * @param int $blog_id Captured current blog identity.
	 * @throws \RuntimeException When WordPress changes the active blog.
	 */
	private function assert_blog_context( int $blog_id ): void {
		if ( $blog_id !== $this->current_blog_id() ) {
			$this->settings         = null;
			$this->settings_blog_id = null;
			throw new \RuntimeException( 'CartPops settings blog context changed during the operation.' );
		}
	}

	/**
	 * Persist one exact current-blog value behind a final pre-write fence.
	 *
	 * @param int                  $blog_id  Captured current blog identity.
	 * @param array<string, mixed> $settings Exact sanitized settings to persist.
	 * @throws \RuntimeException When storage is not durable or the blog context changes.
	 */
	private function persist_for_blog( int $blog_id, array $settings ): void {
		$context_changed = false;
		$observe_switch  = static function () use ( &$context_changed ): void {
			$context_changed = true;
		};
		$assert_stable   = function () use ( $blog_id, &$context_changed ): void {
			if ( ! $context_changed && $blog_id === $this->current_blog_id() ) {
				return;
			}

			$this->settings         = null;
			$this->settings_blog_id = null;
			throw new \RuntimeException( 'CartPops settings blog context changed during persistence.' );
		};
		$filter_exact    = static function ( mixed $value ) use ( $assert_stable ): mixed {
			$assert_stable();
			return $value;
		};
		$filter_general  = static function ( mixed $value, string $option ) use ( $assert_stable ): mixed {
			if ( self::OPTION_KEY === $option ) {
				$assert_stable();
			}
			return $value;
		};
		$before_update   = static function ( string $option ) use ( $assert_stable ): void {
			if ( self::OPTION_KEY === $option ) {
				$assert_stable();
			}
		};
		$before_add      = static function ( string $option ) use ( $assert_stable ): void {
			if ( self::OPTION_KEY === $option ) {
				$assert_stable();
			}
		};
		$hooks_installed = false;

		if (
			function_exists( 'add_action' )
			&& function_exists( 'remove_action' )
			&& function_exists( 'add_filter' )
			&& function_exists( 'remove_filter' )
		) {
			$hooks_installed = add_action( 'switch_blog', $observe_switch, PHP_INT_MIN, 0 )
				&& add_filter( 'pre_update_option_' . self::OPTION_KEY, $filter_exact, PHP_INT_MAX, 1 )
				&& add_filter( 'pre_update_option', $filter_general, PHP_INT_MAX, 2 )
				&& add_action( 'update_option', $before_update, PHP_INT_MAX, 1 )
				&& add_action( 'add_option', $before_add, PHP_INT_MAX, 1 );

			if ( ! $hooks_installed ) {
				remove_action( 'switch_blog', $observe_switch, PHP_INT_MIN );
				remove_filter( 'pre_update_option_' . self::OPTION_KEY, $filter_exact, PHP_INT_MAX );
				remove_filter( 'pre_update_option', $filter_general, PHP_INT_MAX );
				remove_action( 'update_option', $before_update, PHP_INT_MAX );
				remove_action( 'add_option', $before_add, PHP_INT_MAX );
				throw new \RuntimeException( 'CartPops could not install its settings blog-context fence.' );
			}
		}

		try {
			$assert_stable();
			update_option( self::OPTION_KEY, $settings, true );
			$assert_stable();

			if ( get_option( self::OPTION_KEY, null ) !== $settings ) {
				throw new \RuntimeException( 'CartPops settings could not be persisted.' );
			}
			$assert_stable();
		} finally {
			if ( $hooks_installed ) {
				remove_action( 'switch_blog', $observe_switch, PHP_INT_MIN );
				remove_filter( 'pre_update_option_' . self::OPTION_KEY, $filter_exact, PHP_INT_MAX );
				remove_filter( 'pre_update_option', $filter_general, PHP_INT_MAX );
				remove_action( 'update_option', $before_update, PHP_INT_MAX );
				remove_action( 'add_option', $before_add, PHP_INT_MAX );
			}
		}
	}

	/**
	 * Get a setting value.
	 *
	 * @param  string $key           Setting key in dot notation.
	 * @param  mixed  $default_value Value to return when the key is not set.
	 * @return mixed
	 */
	public function get( string $key, mixed $default_value = null ): mixed {
		$settings = $this->all();
		$keys     = explode( '.', $key );
		$value    = $settings;

		foreach ( $keys as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default_value ?? $this->get_default( $key );
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Parse a boolean without PHP's surprising non-empty-string coercion.
	 *
	 * @param mixed $value Candidate value.
	 */
	private function parse_boolean( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 0 === $value || '0' === $value ) {
			return false;
		}
		if ( 1 === $value || '1' === $value ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( strlen( $value ) > 16 ) {
			return null;
		}

		return match ( strtolower( trim( $value ) ) ) {
			'true', 'yes', 'on' => true,
			'false', 'no', 'off' => false,
			default => null,
		};
	}

	/**
	 * Parse a canonical base-10 integer (scientific notation is rejected).
	 *
	 * @param mixed $value Candidate value.
	 */
	private function parse_integer( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 20 || 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return null;
		}

		$parsed = filter_var( $value, FILTER_VALIDATE_INT );
		return false === $parsed ? null : $parsed;
	}

	/**
	 * Parse a finite canonical decimal (scientific notation is rejected).
	 *
	 * @param mixed $value Candidate value.
	 */
	private function parse_decimal( mixed $value ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (float) $value;
			return is_finite( $value ) ? $value : null;
		}
		if (
			! is_string( $value )
			|| strlen( $value ) > 64
			|| 1 !== preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value )
		) {
			return null;
		}

		$value = (float) $value;
		return is_finite( $value ) ? $value : null;
	}

	/**
	 * Canonicalize a finite decimal at the store's supported monetary precision.
	 *
	 * @param mixed $value Candidate value.
	 * @return int|float|null
	 */
	private function parse_canonical_decimal( mixed $value ): int|float|null {
		$number = $this->parse_decimal( $value );
		if ( null === $number ) {
			return null;
		}

		$precision = $this->price_decimals();
		$number    = round( $number, $precision, PHP_ROUND_HALF_UP );
		if ( 0.0 === $number ) {
			return 0;
		}

		return floor( $number ) === $number ? (int) $number : $number;
	}

	/** Resolve WooCommerce's bounded, supported currency precision. */
	private function price_decimals(): int {
		$precision = function_exists( 'wc_get_price_decimals' )
			? wc_get_price_decimals()
			: self::MAX_DECIMAL_PRECISION;

		return max( 0, min( self::MAX_DECIMAL_PRECISION, $precision ) );
	}

	/** Return the smallest fee WooCommerce can charge at the store precision. */
	private function minimum_smart_addon_price(): float {
		return 10 ** ( -1 * $this->price_decimals() );
	}

	/**
	 * Canonicalize the paid Smart Add-on price without persisting a value that
	 * the shopper runtime will omit after WooCommerce currency rounding.
	 *
	 * @param mixed $value Candidate price.
	 */
	private function sanitize_smart_addon_price( mixed $value ): float {
		$minimum = $this->minimum_smart_addon_price();
		$number  = $this->parse_decimal( $value );

		if (
			null === $number
			|| $number < $minimum
			|| $number > self::MAX_MONEY_VALUE
		) {
			return $this->default_smart_addon_price( $minimum );
		}

		$canonical = $this->parse_canonical_decimal( $number );
		if (
			null === $canonical
			|| ! $this->numeric_value_allowed( 'smart_addons.addons.price', $canonical )
		) {
			return $this->default_smart_addon_price( $minimum );
		}

		return (float) $canonical;
	}

	/**
	 * Return the existing default price at store precision, or one minor unit.
	 *
	 * @param float $minimum Smallest chargeable store-currency amount.
	 */
	private function default_smart_addon_price( float $minimum ): float {
		$default = round(
			self::DEFAULT_SMART_ADDON_PRICE,
			$this->price_decimals(),
			PHP_ROUND_HALF_UP
		);

		return is_finite( $default )
			&& $default >= $minimum
			&& $default <= self::MAX_MONEY_VALUE
			? $default
			: $minimum;
	}

	/**
	 * Parse a percentage independently of the store currency precision.
	 *
	 * @param mixed $value Candidate percentage.
	 */
	private function parse_percentage_decimal( mixed $value ): int|float|null {
		if ( is_int( $value ) ) {
			$number = (float) $value;
		} elseif ( is_float( $value ) ) {
			if ( ! is_finite( $value ) || abs( $value - round( $value, 6, PHP_ROUND_HALF_UP ) ) > 0.000000001 ) {
				return null;
			}
			$number = $value;
		} elseif (
			! is_string( $value )
			|| strlen( $value ) > 16
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,6})?$/D', $value )
		) {
			return null;
		} else {
			$number = (float) $value;
		}

		if ( ! is_finite( $number ) || $number < 0 || $number > 100 ) {
			return null;
		}

		return floor( $number ) === $number ? (int) $number : $number;
	}

	/**
	 * Whether the setting is monetary even when its historical default is int.
	 *
	 * @param string $path Dot path.
	 */
	private function is_money_path( string $path ): bool {
		return in_array(
			$path,
			array(
				'shipping_meter.threshold',
				'shipping_meter.tiers.threshold',
				'shipping_meter.tiers.reward_value',
				'smart_addons.addons.price',
			),
			true
		);
	}

	/**
	 * Enforce the bounds of numeric settings exposed by the admin UI.
	 *
	 * @param string    $path  Dot path.
	 * @param int|float $value Parsed numeric value.
	 */
	private function numeric_value_allowed( string $path, int|float $value ): bool {
		if ( 'smart_addons.addons.price' === $path ) {
			return is_finite( (float) $value )
				&& $value >= $this->minimum_smart_addon_price()
				&& $value <= self::MAX_MONEY_VALUE;
		}

		$ranges = array(
			'drawer.width_desktop'              => array( 50, 1400 ),
			'drawer.width_mobile'               => array( 50, 100 ),
			'drawer.animation_duration'         => array( 50, 800 ),
			'launcher.size'                     => array( 40, 80 ),
			'launcher.offset_x'                 => array( 0, 80 ),
			'launcher.offset_y'                 => array( 0, 80 ),
			'design.border_radius'              => array( 0, 32 ),
			'design.button_border_radius'       => array( 0, 32 ),
			'design.overlay_opacity'            => array( 0, 100 ),
			'recommendations.limit'             => array( 1, 8 ),
			'bundle_builder.trigger_radius'     => array( 0, 24 ),
			'shipping_meter.threshold'          => array( 0, self::MAX_MONEY_VALUE ),
			'shipping_meter.tiers.threshold'    => array( 0, self::MAX_MONEY_VALUE ),
			'shipping_meter.tiers.reward_value' => array( 0, self::MAX_MONEY_VALUE ),
		);
		$range  = $ranges[ $path ] ?? array( 0, self::MAX_MONEY_VALUE );

		return is_finite( (float) $value ) && $value >= $range[0] && $value <= $range[1];
	}

	/**
	 * Enforce closed customer-facing enums while retaining supported historical values.
	 *
	 * @param string $path  Dot path.
	 * @param string $value Sanitized string.
	 */
	private function enum_value_allowed( string $path, string $value ): bool {
		$enums = array(
			'general.trigger'                   => array( 'add_to_cart', 'launcher' ),
			'drawer.position'                   => array( 'left', 'right' ),
			'drawer.animation'                  => array( 'none', 'fade', 'slide' ),
			'drawer.totals_breakdown'           => array( 'hidden', 'body', 'footer' ),
			'drawer.product_name_display'       => array( 'two_lines', 'single_line', 'full' ),
			'drawer.quantity_style'             => array( 'default', 'compact', 'none', 'rounded', 'minimal' ),
			'launcher.icon'                     => array( 'cart', 'bag', 'basket' ),
			'launcher.menu.icon'                => array( 'cart', 'bag', 'basket' ),
			'launcher.menu.indicator'           => array( 'none', 'bubble', 'plain' ),
			'launcher.position'                 => array( 'bottom_left', 'bottom_right' ),
			'design.preset'                     => array( 'default', 'minimal', 'bold', 'ocean' ),
			'design.dark_mode'                  => array( 'auto', 'light', 'dark' ),
			'recommendations.strategy'          => array( 'upsell', 'cross_sell', 'custom' ),
			'recommendations.fallback'          => array( 'none', 'random', 'upsell', 'cross_sell' ),
			'recommendations.layout'            => array( 'horizontal', 'vertical', 'grid', 'list' ),
			'recommendations.button_type'       => array( 'icon', 'text', 'text_icon' ),
			'secondary_action.mode'             => array( 'none', 'continue_shopping', 'view_cart', 'custom_url' ),
			'smart_addons.addons.icon'          => array( 'shield', 'gift', 'star', 'heart', 'truck', 'sparkles' ),
			'smart_addons.addons.default_state' => array( 'off', 'on' ),
			'integrations.blocksy.icon_style'   => array( 'cart', 'cart-2', 'bag', 'bag-2', 'basket', 'basket-2' ),
		);

		return ! isset( $enums[ $path ] ) || in_array( $value, $enums[ $path ], true );
	}

	/**
	 * Get the maximum byte length for a text field.
	 *
	 * @param string $path Dot path.
	 */
	private function string_limit( string $path ): int {
		if ( str_ends_with( $path, '.id' ) || str_ends_with( $path, '.icon' ) ) {
			return self::MAX_IDENTIFIER_LENGTH;
		}
		if ( str_ends_with( $path, '_url' ) ) {
			return self::MAX_URL_LENGTH;
		}

		return self::MAX_TEXT_LENGTH;
	}

	/**
	 * Limit a string without splitting a multibyte character where possible.
	 *
	 * @param string $value  Value.
	 * @param int    $length Maximum bytes.
	 */
	private function limit_string( string $value, int $length ): string {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		return function_exists( 'mb_strcut' )
			? mb_strcut( $value, 0, $length, 'UTF-8' )
			: substr( $value, 0, $length );
	}

	/**
	 * Accept only complete, bounded hex/rgb/rgba CSS color values.
	 *
	 * @param string $value Candidate color.
	 */
	private function sanitize_css_color( string $value ): ?string {
		$value = trim( $value );
		if ( strlen( $value ) > 64 ) {
			return null;
		}
		$hex = sanitize_hex_color( $value );
		if ( null !== $hex ) {
			return $hex;
		}
		if ( 1 === preg_match( '/^rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)$/iD', $value, $matches ) ) {
			$channels = array_map( 'intval', array_slice( $matches, 1, 3 ) );
			if ( max( $channels ) > 255 ) {
				return null;
			}
			return sprintf( 'rgb(%d, %d, %d)', ...$channels );
		}
		if ( 1 !== preg_match( '/^rgba\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*((?:0(?:\.[0-9]+)?|1(?:\.0+)?|\.[0-9]+))\s*\)$/iD', $value, $matches ) ) {
			return null;
		}
		$channels = array_map( 'intval', array_slice( $matches, 1, 3 ) );
		$alpha    = (float) $matches[4];
		if ( max( $channels ) > 255 || ! is_finite( $alpha ) || $alpha < 0 || $alpha > 1 ) {
			return null;
		}

		$alpha_string = rtrim( rtrim( number_format( $alpha, 6, '.', '' ), '0' ), '.' );
		return sprintf( 'rgba(%d, %d, %d, %s)', $channels[0], $channels[1], $channels[2], $alpha_string );
	}

	/**
	 * Accept a relative site path or an explicit HTTP(S) URL without credentials.
	 *
	 * @param string $value Candidate URL.
	 */
	private function sanitize_supported_url( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) > self::MAX_URL_LENGTH || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) ) {
			return null;
		}
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			return $value;
		}

		if (
			1 !== preg_match( '~^https?://([^/?#]+)(?:[/?#]|$)~iD', $value, $matches )
			|| str_contains( $matches[1], '@' )
			|| false === filter_var( $value, FILTER_VALIDATE_URL )
		) {
			return null;
		}

		return $value;
	}

	/**
	 * Set a setting value.
	 *
	 * @param string $key   Setting key in dot notation.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, mixed $value ): void {
		$blog_id  = $this->current_blog_id();
		$settings = $this->all();
		$this->assert_blog_context( $blog_id );
		$keys    = explode( '.', $key );
		$current = &$settings;

		foreach ( array_slice( $keys, 0, -1 ) as $segment ) {
			if ( ! isset( $current[ $segment ] ) || ! is_array( $current[ $segment ] ) ) {
				$current[ $segment ] = array();
			}
			$current = &$current[ $segment ];
		}

		$current[ end( $keys ) ] = $value;
		$this->settings          = $settings;
		$this->settings_blog_id  = $blog_id;
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$blog_id = $this->current_blog_id();
		$this->reconcile_cache_blog( $blog_id );
		if ( null === $this->settings ) {
			$stored = get_option( self::OPTION_KEY, array() );
			$this->assert_blog_context( $blog_id );
			$stored = is_array( $stored ) ? $stored : array();

			// Treat persisted settings as untrusted input. This also protects
			// historical migrations that retained free-form lists because their
			// defaults were empty and therefore provided no nested shape.
			$stored = $this->sanitize( $stored );
			$this->assert_blog_context( $blog_id );
			$this->settings         = $this->merge_patch( $this->defaults(), $stored );
			$this->settings_blog_id = $blog_id;
		}
		return $this->settings;
	}

	/**
	 * Save current settings to the database.
	 *
	 * @throws \RuntimeException When the exact sanitized settings are not durable.
	 */
	public function save(): void {
		$blog_id  = $this->current_blog_id();
		$settings = $this->sanitize( $this->all() );
		$this->assert_blog_context( $blog_id );
		$this->settings         = $this->merge_patch( $this->defaults(), $settings );
		$this->settings_blog_id = $blog_id;
		$this->persist_for_blog( $blog_id, $this->settings );
	}

	/**
	 * Update multiple settings at once and save.
	 *
	 * @param array<string, mixed> $values Settings to merge and save.
	 * @throws \Throwable When validation or durable storage fails.
	 */
	public function update( array $values ): void {
		$blog_id = $this->current_blog_id();
		$before  = $this->all();
		$this->assert_blog_context( $blog_id );
		$patch = $this->sanitize_patch( $values );
		$this->assert_blog_context( $blog_id );
		$this->settings         = $this->merge_patch( $before, $patch );
		$this->settings_blog_id = $blog_id;
		try {
			$this->save();
		} catch ( \Throwable $error ) {
			if ( $blog_id === $this->current_blog_id() ) {
				$this->settings         = $before;
				$this->settings_blog_id = $blog_id;
			} else {
				$this->settings         = null;
				$this->settings_blog_id = null;
			}
			throw $error;
		}
	}

	/**
	 * Reset all settings to defaults.
	 */
	public function reset(): void {
		$blog_id        = $this->current_blog_id();
		$this->settings = $this->defaults();
		$this->assert_blog_context( $blog_id );
		$this->settings_blog_id = $blog_id;
		$this->save();
	}

	/**
	 * Get a single default value using dot notation.
	 *
	 * @param  string $key Setting key in dot notation.
	 * @return mixed
	 */
	private function get_default( string $key ): mixed {
		$defaults = $this->defaults();
		$keys     = explode( '.', $key );
		$value    = $defaults;

		foreach ( $keys as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Default settings for the entire plugin.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'general'             => array(
				'enabled'                 => true,
				'trigger'                 => 'add_to_cart',
				'product_page_ajax_add'   => true,
				'powered_by'              => false,
				'powered_by_partner_code' => '',
				'mini_cart_mode'          => 'replace',
			),
			'drawer'              => array(
				'position'                 => 'right',
				'width_desktop'            => 480,
				'width_mobile'             => 100,
				'animation'                => 'slide',
				'animation_duration'       => 300,
				'show_undo'                => true,
				'show_coupon'              => true,
				'product_name_display'     => 'two_lines',
				'totals_breakdown'         => 'footer',
				'show_subtotal'            => true,
				'show_discount'            => true,
				'show_shipping'            => true,
				'show_tax'                 => true,
				'show_total'               => true,
				'checkout_button_text'     => '',
				'header_title'             => '',
				'added_to_cart_message'    => '',
				'coupon_title'             => '',
				'coupon_input_placeholder' => '',
				'coupon_button_text'       => '',
				'subtotal_label'           => '',
				'discount_label'           => '',
				'total_label'              => '',
				'empty_button_text'        => '',
				'empty_title'              => '',
				'empty_subtitle'           => '',
				'quantity_style'           => 'default',
			),
			'launcher'            => array(
				'enabled'              => true,
				'icon'                 => 'cart',
				'position'             => 'bottom_right',
				'hidden_page_ids'      => array(),
				'show_count'           => true,
				'show_total'           => false,
				'hide_empty'           => false,
				'hide_indicator_empty' => true,
				'size'                 => 56,
				'offset_x'             => 24,
				'offset_y'             => 24,
				'colors'               => array(
					'background' => '#6f23e1',
					'icon'       => '#ffffff',
					'badge_bg'   => '#ef4444',
					'badge_text' => '#ffffff',
				),
				'inline_colors'        => array(
					'background' => 'rgba(255, 255, 255, 0)',
					'text'       => '#000000',
					'badge_bg'   => '#705aef',
					'badge_text' => '#ffffff',
				),
				'menu'                 => array(
					'icon'                 => 'bag',
					'indicator'            => 'bubble',
					'hide_indicator_empty' => false,
					'show_total'           => false,
				),
			),
			'design'              => array(
				'preset'               => 'default',
				'border_radius'        => 8,
				'dark_mode'            => 'auto',
				'overlay_color'        => '#000000',
				'overlay_opacity'      => 50,
				'button_border_radius' => 8,
				'colors'               => array(
					'primary'               => '#6f23e1',
					'primary_text'          => '#ffffff',
					'secondary'             => '#f5f5f7',
					'secondary_text'        => '#1a1a2e',
					'background'            => '#ffffff',
					'surface'               => '#f9fafb',
					'text_primary'          => '#1a1a2e',
					'text_secondary'        => '#6b7280',
					'text_tertiary'         => '#9ca3af',
					'border'                => '#e5e7eb',
					'input_bg'              => '#f9fafb',
					'input_border'          => '#d1d5db',
					'input_text'            => '#1a1a2e',
					'button_primary_bg'     => '#6f23e1',
					'button_primary_text'   => '#ffffff',
					'button_secondary_bg'   => '#f5f5f7',
					'button_secondary_text' => '#1a1a2e',
					'quantity_button_bg'    => '#f9fafb',
					'quantity_button_text'  => '#6b7280',
					'quantity_input_bg'     => '#ffffff',
					'quantity_input_border' => '#e5e7eb',
					'quantity_input_text'   => '#1a1a2e',
					'recs_button_bg'        => '#6f23e1',
					'recs_button_text'      => '#ffffff',
					'recs_background'       => '#ffffff',
					'recs_border'           => '#e5e7eb',
					'recs_text'             => '#1a1a2e',
					'sale'                  => '#dc2626',
					'success'               => '#22c55e',
					'danger'                => '#ef4444',
					'overlay'               => 'rgba(0, 0, 0, 0.5)',
				),
				'colors_dark'          => array(
					'background'     => '#1a1a2e',
					'surface'        => '#252542',
					'text_primary'   => '#f9fafb',
					'text_secondary' => '#d1d5db',
					'border'         => '#6b7280',
					'input_bg'       => '#252542',
					'input_border'   => '#6b7280',
					'overlay'        => 'rgba(0, 0, 0, 0.7)',
				),
			),
			'shipping_meter'      => array(
				'enabled'           => false,
				'threshold'         => 0,
				'message_remaining' => 'Add {{amount}} more for free shipping',
				'message_qualified' => 'You have qualified for free shipping!',
				'show_in_drawer'    => true,
				'show_icon'         => true,
				'show_truck'        => true,
				'show_celebration'  => true,
				'tiers'             => array(
					array(
						'enabled'           => false,
						'threshold'         => 75,
						'reward_type'       => 'percentage_discount',
						'reward_value'      => 10,
						'show_celebration'  => true,
						'message_remaining' => 'Add {{amount}} more for {{reward}}',
						'message_qualified' => 'You unlocked {{reward}}!',
					),
					array(
						'enabled'           => false,
						'threshold'         => 100,
						'reward_type'       => 'free_gift',
						'reward_value'      => 0,
						'show_celebration'  => true,
						'message_remaining' => 'Add {{amount}} more for a free gift',
						'message_qualified' => 'You earned a free gift!',
					),
				),
				'colors'            => array(
					'bar_start'                  => '#6f23e1',
					'bar_end'                    => '#9b59f0',
					'background'                 => '#f9fafb',
					'text'                       => '#1a1a2e',
					'icon_background'            => '#f3eefa',
					'icon_color'                 => '#6f23e1',
					'truck_background'           => '#ffffff',
					'truck_color'                => '#6f23e1',
					'bar_qualified_start'        => '#22c55e',
					'bar_qualified_end'          => '#4ade80',
					'background_qualified'       => '#ecfdf5',
					'text_qualified'             => '#15803d',
					'icon_qualified_background'  => '#dcfce7',
					'icon_qualified_color'       => '#22c55e',
					'truck_qualified_background' => '#22c55e',
					'truck_qualified_color'      => '#ffffff',
					'tier_reached'               => '#22c55e',
					'tier_icon'                  => '#6b7280',
				),
			),
			'shipping_calculator' => array(
				'enabled' => false,
			),
			'secondary_action'    => array(
				'mode'        => SecondaryActionSettings::DEFAULT_MODE,
				'custom_url'  => '',
				'custom_text' => '',
			),
			'recommendations'     => array(
				'enabled'            => false,
				'strategy'           => 'upsell',
				'fallback'           => 'random',
				'custom_product_ids' => array(),
				'limit'              => 4,
				'heading'            => 'You may also like',
				'layout'             => 'horizontal',
				'button_type'        => RecommendationButtonPresentation::DEFAULT_MODE,
				'button_text'        => RecommendationButtonPresentation::DEFAULT_TEXT,
			),
			'notifications'       => array(
				// Drawer Notification Bar — global kill switch.
				'drawer_bar_enabled' => false,

				// Notification rules (array, priority = order, first match wins).
				'drawer_bar_items'   => array(),

				// Shared colors (used when item.colors is null).
				'drawer_bar_colors'  => array(
					'background'  => '#fffbeb',
					'text'        => '#92400e',
					'icon'        => '#f59e0b',
					'accent'      => '#d97706',
					'button_bg'   => '#f59e0b',
					'button_text' => '#ffffff',
				),
			),
			'smart_addons'        => array(
				'addons' => array(
					'shipping_protection' => array(
						'enabled'       => false,
						'label'         => 'Shipping protection',
						'description'   => 'Protect your order from damage or loss',
						'price'         => self::DEFAULT_SMART_ADDON_PRICE,
						'icon_enabled'  => true,
						'icon'          => 'shield',
						'default_state' => 'off',
					),
				),
			),
			'bundle_builder'      => array(
				'enabled'        => false,
				'heading'        => 'Frequently bought together',
				'trigger_bg'     => '#f9fafb',
				'trigger_text'   => '#6b7280',
				'trigger_border' => '#e5e7eb',
				'trigger_radius' => 8,
				'upsells'        => array(),
			),
			'advanced'            => array(
				'custom_css'              => '',
				'force_fragments_refresh' => false,
			),
			'integrations'        => array(
				'blocksy' => array(
					'replace_icon' => false,
					'icon_style'   => 'cart',
					'show_count'   => true,
					'open_drawer'  => true,
					'icon_color'   => '',
					'badge_bg'     => '',
					'badge_text'   => '',
				),
			),
		);
	}

	/**
	 * Sanitize an incoming settings array against the known defaults() shape.
	 *
	 * Only top-level groups are filtered by the caller; this method recursively
	 * sanitizes the values inside each group. Scalar values are coerced to the
	 * type of their matching default. Free-form array structures with a known
	 * shape (notification rule items, shipping tiers) are sanitized per-field
	 * and unknown keys are stripped. Color values are run through
	 * sanitize_hex_color() with a fallback to the default.
	 *
	 * @param array<string, mixed> $settings Raw settings (top-level keys already filtered).
	 * @return array<string, mixed>
	 */
	public function sanitize( array $settings ): array {
		$defaults = $this->defaults();
		$clean    = array();

		foreach ( $settings as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			$clean[ $key ] = $this->sanitize_group( $key, $value, $defaults[ $key ] );
		}

		return $clean;
	}

	/**
	 * Sanitize a sparse admin update without materializing omitted defaults.
	 *
	 * Full persisted/imported shapes still use sanitize(); update() uses this
	 * projection so changing one nested field cannot reset sibling V1/V2
	 * customizations.
	 *
	 * @param array<string, mixed> $settings Sparse settings patch.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When a submitted container has an invalid shape.
	 */
	private function sanitize_patch( array $settings ): array {
		$defaults   = $this->defaults();
		$recognized = false;
		foreach ( $settings as $group => $raw_value ) {
			if ( ! array_key_exists( $group, $defaults ) ) {
				continue;
			}
			if ( ! $this->patch_shape_is_valid( $raw_value, $defaults[ $group ], (string) $group ) ) {
				throw new \InvalidArgumentException( 'CartPops settings have an invalid nested shape.' );
			}
			$recognized = $this->patch_has_recognized_value( $raw_value, $defaults[ $group ], (string) $group ) || $recognized;
		}
		if ( ! $recognized ) {
			throw new \InvalidArgumentException( 'CartPops settings contain no recognized value.' );
		}

		$sanitized = $this->sanitize( $settings );
		$clean     = array();
		foreach ( $settings as $group => $raw_value ) {
			if ( ! array_key_exists( $group, $sanitized ) ) {
				continue;
			}
			$clean[ $group ] = $this->project_sanitized_patch( $raw_value, $sanitized[ $group ], (string) $group );
		}

		return $clean;
	}

	/**
	 * Whether a sparse patch contains at least one known leaf or an explicitly
	 * submitted atomic collection. Unknown-only objects are rejected rather than
	 * being converted into a successful no-op write.
	 *
	 * @param mixed  $raw           Raw patch value.
	 * @param mixed  $default_value Default shape value.
	 * @param string $path          Dot path.
	 */
	private function patch_has_recognized_value( mixed $raw, mixed $default_value, string $path ): bool {
		if ( ! is_array( $default_value ) ) {
			return true;
		}
		if ( ! is_array( $raw ) ) {
			return false;
		}
		if ( $this->patch_collection_is_atomic( $path ) || array_is_list( $default_value ) ) {
			return true;
		}

		foreach ( $raw as $key => $value ) {
			if (
				is_string( $key )
				&& array_key_exists( $key, $default_value )
				&& $this->patch_has_recognized_value( $value, $default_value[ $key ], $path . '.' . $key )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reject malformed patch containers before defaults can overwrite siblings.
	 *
	 * @param mixed  $raw     Raw patch value.
	 * @param mixed  $default_value Default shape value.
	 * @param string $path    Dot path.
	 */
	private function patch_shape_is_valid( mixed $raw, mixed $default_value, string $path ): bool {
		if ( ! is_array( $default_value ) ) {
			return is_scalar( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return false;
		}
		if ( $this->patch_collection_is_atomic( $path ) ) {
			return $this->atomic_patch_is_valid( $path, $raw );
		}
		if ( array_is_list( $default_value ) ) {
			return true;
		}
		if ( array() !== $raw && array_is_list( $raw ) ) {
			return false;
		}

		$recognized = false;
		foreach ( $raw as $key => $value ) {
			if ( is_string( $key ) && array_key_exists( $key, $default_value ) ) {
				$recognized = true;
				if ( ! $this->patch_shape_is_valid( $value, $default_value[ $key ], $path . '.' . $key ) ) {
					return false;
				}
			}
		}

		return $recognized;
	}

	/**
	 * Validate an explicitly replacing collection as one indivisible value.
	 *
	 * @param string               $path Collection path.
	 * @param array<string, mixed> $raw  Submitted collection.
	 */
	private function atomic_patch_is_valid( string $path, array $raw ): bool {
		if ( 'smart_addons.addons' === $path ) {
			return $this->smart_addon_patch_is_valid( $raw );
		}
		if ( ! array_is_list( $raw ) ) {
			return false;
		}
		if ( 'recommendations.custom_product_ids' === $path ) {
			return count( $raw ) <= self::MAX_CUSTOM_RECOMMENDATIONS
				&& count( $this->sanitize_custom_recommendation_ids( $raw ) ) === count( $raw );
		}
		if ( 'launcher.hidden_page_ids' === $path ) {
			return count( $raw ) <= self::MAX_HIDDEN_PAGE_IDS
				&& count( $this->sanitize_hidden_page_ids( $raw ) ) === count( $raw );
		}

		if ( 'bundle_builder.upsells' === $path ) {
			return $this->bundle_patch_is_valid( $raw );
		}
		if ( 'notifications.drawer_bar_items' === $path ) {
			if ( count( $raw ) > self::MAX_NOTIFICATION_ITEMS ) {
				return false;
			}
			$ids = array();
			foreach ( $raw as $item ) {
				$clean = is_array( $item ) ? $this->sanitize_notification_item( $item ) : null;
				if ( null === $clean || array() === $clean ) {
					return false;
				}
				$id = $clean['id'] ?? '';
				if ( is_string( $id ) && '' !== $id ) {
					if ( isset( $ids[ $id ] ) ) {
						return false;
					}
					$ids[ $id ] = true;
				}
			}
			return true;
		}
		if ( 'shipping_meter.tiers' === $path ) {
			if ( count( $raw ) > self::MAX_SHIPPING_TIERS ) {
				return false;
			}
			$shape = $this->defaults()['shipping_meter']['tiers'][0] ?? array();
			foreach ( $raw as $tier ) {
				if ( ! is_array( $tier ) || ! $this->patch_shape_is_valid( $tier, $shape, $path . '.entry' ) ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}

	/**
	 * Validate the bundle offer list without dropping or truncating an entry.
	 *
	 * @param array<int, mixed> $raw Submitted offers.
	 */
	private function bundle_patch_is_valid( array $raw ): bool {
		if ( count( $raw ) > self::MAX_BUNDLE_UPSELLS ) {
			return false;
		}
		$ids      = array();
		$triggers = array();
		foreach ( $raw as $upsell ) {
			if (
				! is_array( $upsell )
				|| ! is_string( $upsell['id'] ?? '' )
				|| strlen( $upsell['id'] ?? '' ) > self::MAX_IDENTIFIER_LENGTH
			) {
				return false;
			}
			$clean = is_array( $upsell ) ? $this->sanitize_upsell( $upsell ) : null;
			if ( null === $clean ) {
				return false;
			}
			$id      = (string) $clean['id'];
			$trigger = (int) $clean['trigger_product_id'];
			if ( isset( $triggers[ $trigger ] ) || ( '' !== $id && isset( $ids[ $id ] ) ) ) {
				return false;
			}
			$triggers[ $trigger ] = true;
			if ( '' !== $id ) {
				$ids[ $id ] = true;
			}
		}

		return true;
	}

	/**
	 * Validate keyed add-ons without canonical slug collisions or partial drops.
	 *
	 * @param array<string, mixed> $raw Submitted add-ons.
	 */
	private function smart_addon_patch_is_valid( array $raw ): bool {
		if ( count( $raw ) > self::MAX_SMART_ADDONS ) {
			return false;
		}
		$shape = $this->defaults()['smart_addons']['addons']['shipping_protection'] ?? array();
		$slugs = array();
		foreach ( $raw as $slug => $addon ) {
			if ( ! is_string( $slug ) || ! is_array( $addon ) ) {
				return false;
			}
			$clean_slug = sanitize_key( $this->limit_string( $slug, 64 ) );
			if (
				'' === $clean_slug
				|| isset( $slugs[ $clean_slug ] )
				|| ! $this->patch_shape_is_valid( $addon, $shape, 'smart_addons.addons.entry' )
			) {
				return false;
			}
			$slugs[ $clean_slug ] = true;
		}

		return true;
	}

	/**
	 * Whether submitting this collection deliberately replaces it as a unit.
	 *
	 * @param string $path Dot path.
	 */
	private function patch_collection_is_atomic( string $path ): bool {
		return in_array(
			$path,
			array(
				'launcher.hidden_page_ids',
				'recommendations.custom_product_ids',
				'notifications.drawer_bar_items',
				'shipping_meter.tiers',
				'bundle_builder.upsells',
				'smart_addons.addons',
			),
			true
		);
	}

	/**
	 * Project a fully sanitized group back onto the exact submitted key shape.
	 *
	 * Bespoke collections are atomic replacement values once their parent key is
	 * submitted; ordinary associative groups recurse and preserve omitted keys.
	 *
	 * @param mixed  $raw       Raw submitted value.
	 * @param mixed  $sanitized Sanitized value.
	 * @param string $path      Dot path.
	 * @return mixed
	 */
	private function project_sanitized_patch( mixed $raw, mixed $sanitized, string $path ): mixed {
		if ( ! is_array( $raw ) || ! is_array( $sanitized ) ) {
			return $sanitized;
		}

		if ( $this->patch_collection_is_atomic( $path ) || array_is_list( $sanitized ) ) {
			return $sanitized;
		}
		if ( array() === $raw ) {
			return array();
		}

		$clean = array();
		foreach ( $raw as $key => $raw_value ) {
			if ( ! is_string( $key ) || ! array_key_exists( $key, $sanitized ) ) {
				continue;
			}
			$clean[ $key ] = $this->project_sanitized_patch(
				$raw_value,
				$sanitized[ $key ],
				$path . '.' . $key
			);
		}

		return $clean;
	}

	/**
	 * Sanitize a single top-level settings group.
	 *
	 * @param string $group   Top-level group key (e.g. 'design', 'notifications').
	 * @param mixed  $value   Raw value for the group.
	 * @param mixed  $default_value Default value for the group.
	 * @return mixed
	 */
	private function sanitize_group( string $group, mixed $value, mixed $default_value ): mixed {
		// Known free-form list structures get bespoke per-item sanitization.
		if ( 'notifications' === $group && is_array( $value ) ) {
			return $this->sanitize_notifications( $value, is_array( $default_value ) ? $default_value : array() );
		}

		if ( 'shipping_meter' === $group && is_array( $value ) ) {
			return $this->sanitize_shipping_meter( $value, is_array( $default_value ) ? $default_value : array() );
		}

		if ( 'bundle_builder' === $group && is_array( $value ) ) {
			return $this->sanitize_bundle_builder( $value, is_array( $default_value ) ? $default_value : array() );
		}

		if ( 'smart_addons' === $group && is_array( $value ) ) {
			return $this->sanitize_smart_addons( $value, is_array( $default_value ) ? $default_value : array() );
		}

		if ( 'advanced' === $group && is_array( $value ) ) {
			return $this->sanitize_advanced( $value, is_array( $default_value ) ? $default_value : array() );
		}

		return $this->sanitize_value( $group, $value, $default_value );
	}

	/**
	 * Recursively sanitize a value against the type of its default.
	 *
	 * @param string $path    Dot path for field-specific validation.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $default_value Default value, used to infer the target type.
	 * @return mixed
	 */
	private function sanitize_value( string $path, mixed $value, mixed $default_value ): mixed {
		$key = (string) substr( $path, (int) strrpos( '.' . $path, '.' ) );

		if ( 'general.powered_by_partner_code' === $path ) {
			return LegacyPoweredByLink::normalize_partner_code( $value ) ?? '';
		}

		if ( 'general.trigger' === $path ) {
			return self::normalize_trigger( $value );
		}

		if ( 'general.mini_cart_mode' === $path ) {
			return self::normalize_mini_cart_mode( $value );
		}

		if ( 'integrations.blocksy.icon_style' === $path ) {
			if (
				! is_string( $value )
				|| strlen( $value ) > $this->string_limit( $path )
				|| ! $this->enum_value_allowed( $path, $value )
			) {
				return $default_value;
			}

			return $value;
		}

		if ( 'recommendations.custom_product_ids' === $path ) {
			return is_array( $value )
				? $this->sanitize_custom_recommendation_ids( $value )
				: $default_value;
		}

		if ( 'recommendations.button_type' === $path ) {
			return RecommendationButtonPresentation::normalize_mode( $value );
		}

		if ( 'recommendations.button_text' === $path ) {
			return RecommendationButtonPresentation::sanitize_text( $value );
		}

		if ( 'secondary_action.mode' === $path ) {
			return SecondaryActionSettings::normalize_mode( $value );
		}

		if ( 'secondary_action.custom_url' === $path ) {
			return SecondaryActionSettings::sanitize_custom_url( $value );
		}

		if ( 'secondary_action.custom_text' === $path ) {
			return SecondaryActionSettings::sanitize_custom_text( $value );
		}

		if ( 'launcher.hidden_page_ids' === $path ) {
			return is_array( $value )
				? $this->sanitize_hidden_page_ids( $value )
				: $default_value;
		}

		if ( 'design.overlay_opacity' === $path ) {
			return $this->parse_percentage_decimal( $value ) ?? $default_value;
		}

		if ( 'design.overlay_color' === $path ) {
			$hex = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
			if ( ! is_string( $hex ) ) {
				return $default_value;
			}
			return 4 === strlen( $hex )
				? sprintf( '#%1$s%1$s%2$s%2$s%3$s%3$s', $hex[1], $hex[2], $hex[3] )
				: $hex;
		}

		if ( 'smart_addons.addons.price' === $path ) {
			return $this->sanitize_smart_addon_price( $value );
		}

		// Color values: keys containing 'color' or default starting with '#'.
		if ( $this->is_color_key( $key, $default_value ) ) {
			$color = is_string( $value ) ? $this->sanitize_css_color( $value ) : null;
			return $color ?? ( is_string( $default_value ) ? $default_value : '' );
		}

		if ( is_bool( $default_value ) ) {
			return $this->parse_boolean( $value ) ?? $default_value;
		}

		if ( $this->is_money_path( $path ) ) {
			$number = $this->parse_canonical_decimal( $value );
			if ( null === $number || ! $this->numeric_value_allowed( $path, $number ) ) {
				return $default_value;
			}
			return is_float( $default_value ) ? (float) $number : $number;
		}

		if ( is_int( $default_value ) ) {
			$integer = $this->parse_integer( $value );
			if ( null === $integer || ! $this->numeric_value_allowed( $path, $integer ) ) {
				return $default_value;
			}
			return $integer;
		}

		if ( is_float( $default_value ) ) {
			$number = $this->parse_canonical_decimal( $value );
			if ( null === $number || ! $this->numeric_value_allowed( $path, $number ) ) {
				return $default_value;
			}
			return $number;
		}

		if ( is_string( $default_value ) ) {
			if ( ! is_scalar( $value ) ) {
				return $default_value;
			}
			if ( str_ends_with( $path, '_url' ) ) {
				return $this->sanitize_supported_url( (string) $value ) ?? $default_value;
			}
			$limit  = $this->string_limit( $path );
			$string = sanitize_text_field( $this->limit_string( (string) $value, $limit ) );
			if ( ! $this->enum_value_allowed( $path, $string ) ) {
				return $default_value;
			}
			return $this->limit_string( $string, $limit );
		}

		// Array defaults.
		if ( is_array( $default_value ) ) {
			if ( ! is_array( $value ) ) {
				return $default_value;
			}

			// Sequential lists: sanitize each scalar entry.
			if ( array_is_list( $default_value ) ) {
				$items = array_slice( array_values( $value ), 0, self::MAX_GENERIC_LIST_ITEMS );
				$items = array_values(
					array_filter(
						array_map(
							fn( mixed $item ): string => is_scalar( $item )
								? $this->limit_string( sanitize_text_field( (string) $item ), self::MAX_IDENTIFIER_LENGTH )
								: '',
							$items
						),
						static fn( string $item ): bool => '' !== $item
					)
				);

				return $items;
			}

			// Associative array with a known shape: recurse per known key.
			$clean = array();
			foreach ( $default_value as $sub_key => $sub_default ) {
				if ( array_key_exists( $sub_key, $value ) ) {
					$clean[ $sub_key ] = $this->sanitize_value( $path . '.' . $sub_key, $value[ $sub_key ], $sub_default );
				} else {
					$clean[ $sub_key ] = $sub_default;
				}
			}
			return $clean;
		}

		// No default to infer from: fall back to text sanitization for scalars.
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return $value;
	}

	/**
	 * Canonicalize the ordered Pro custom-product selection.
	 *
	 * Full stored documents are treated as untrusted and repaired by dropping
	 * malformed or duplicate identifiers. Sparse REST patches are validated as
	 * an atomic collection before this sanitizer runs, so an invalid submission
	 * cannot partially replace the durable selection.
	 *
	 * @param array<mixed> $value Candidate product identifiers.
	 * @return int[] Ordered unique positive product identifiers.
	 */
	private function sanitize_custom_recommendation_ids( array $value ): array {
		$clean = array();
		$seen  = array();
		foreach ( array_values( $value ) as $product_id ) {
			$parsed = $this->parse_integer( $product_id );
			if ( null === $parsed || $parsed < 1 || isset( $seen[ $parsed ] ) ) {
				continue;
			}
			$seen[ $parsed ] = true;
			$clean[]         = $parsed;
			if ( count( $clean ) >= self::MAX_CUSTOM_RECOMMENDATIONS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Canonicalize the V1-compatible floating-launcher page exclusions.
	 *
	 * @param array<mixed> $value Candidate page identifiers.
	 * @return int[] Ordered unique positive page identifiers.
	 */
	private function sanitize_hidden_page_ids( array $value ): array {
		$clean = array();
		$seen  = array();
		foreach ( array_values( $value ) as $page_id ) {
			$parsed = $this->parse_integer( $page_id );
			if ( null === $parsed || $parsed < 1 || isset( $seen[ $parsed ] ) ) {
				continue;
			}
			$seen[ $parsed ] = true;
			$clean[]         = $parsed;
			if ( count( $clean ) >= self::MAX_HIDDEN_PAGE_IDS ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Whether a key/default pair represents a color value.
	 *
	 * @param  string $key           Setting key.
	 * @param  mixed  $default_value Default value used to infer the type.
	 * @return bool
	 */
	private function is_color_key( string $key, mixed $default_value ): bool {
		// Container keys like `colors`/`colors_dark` hold maps of color values,
		// not a single color — let them recurse via the array branch instead.
		if ( is_array( $default_value ) ) {
			return false;
		}

		if ( false !== strpos( $key, 'color' ) || in_array( $key, array( 'badge_bg', 'badge_text' ), true ) ) {
			return true;
		}

		return is_string( $default_value )
			&& '' !== $default_value
			&& ( '#' === $default_value[0] || str_starts_with( strtolower( ltrim( $default_value ) ), 'rgb' ) );
	}

	/**
	 * Sanitize the 'notifications' group, including the free-form rule items.
	 *
	 * @param array<string, mixed> $value   Raw notifications settings.
	 * @param array<string, mixed> $default_value Default notifications settings.
	 * @return array<string, mixed>
	 */
	private function sanitize_notifications( array $value, array $default_value ): array {
		$clean = array();

		foreach ( $default_value as $key => $sub_default ) {
			if ( 'drawer_bar_items' === $key ) {
				$items = array();
				if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
					foreach ( array_slice( array_values( $value[ $key ] ), 0, self::MAX_NOTIFICATION_ITEMS ) as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$sanitized = $this->sanitize_notification_item( $item );
						if ( null !== $sanitized ) {
							$items[] = $sanitized;
						}
					}
				}
				$clean[ $key ] = isset( $value[ $key ] ) && is_array( $value[ $key ] ) ? $items : $sub_default;
				continue;
			}

			$clean[ $key ] = array_key_exists( $key, $value )
				? $this->sanitize_value( 'notifications.' . $key, $value[ $key ], $sub_default )
				: $sub_default;
		}

		return $clean;
	}

	/**
	 * Sanitize a single notification rule item against its known fields.
	 *
	 * Unknown keys are stripped. The free-form rule conditions array is
	 * sanitized generically since condition values vary by type.
	 *
	 * @param array<string, mixed> $item Raw notification item.
	 * @return array<string, mixed>|null
	 */
	private function sanitize_notification_item( array $item ): ?array {
		if (
			( array_key_exists( 'mode', $item ) && ! is_scalar( $item['mode'] ) )
			|| ( array_key_exists( 'rules', $item ) && ! is_array( $item['rules'] ) )
		) {
			return null;
		}
		if ( isset( $item['mode'] ) && ! in_array( (string) $item['mode'], array( 'expiry', 'message', 'spotlight' ), true ) ) {
			return null;
		}

		$clean = array();

		$text_fields = array(
			'id',
			'label',
			'mode',
			'expiry_icon',
			'expiry_message',
			'expiry_action',
			'message_icon',
			'message_text',
			'message_link_text',
			'message_link_url',
			'spotlight_message',
		);
		foreach ( $text_fields as $field ) {
			if ( isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
				$limit = $this->string_limit( 'notifications.drawer_bar_items.' . $field );
				if ( 'message_link_url' === $field ) {
					$url = $this->sanitize_supported_url( (string) $item[ $field ] );
					if ( null === $url ) {
						continue;
					}
					$clean[ $field ] = $url;
					continue;
				}
				$clean[ $field ] = $this->limit_string(
					sanitize_text_field( $this->limit_string( (string) $item[ $field ], $limit ) ),
					$limit
				);
			}
		}
		if ( isset( $clean['mode'] ) && ! in_array( $clean['mode'], array( 'expiry', 'message', 'spotlight' ), true ) ) {
			return null;
		}
		$notification_enums = array(
			'expiry_icon'   => array( 'clock', 'none' ),
			'expiry_action' => array( 'clear', 'redirect' ),
			'message_icon'  => array( 'info', 'gift', 'truck', 'tag', 'star', 'none' ),
		);
		foreach ( $notification_enums as $field => $allowed ) {
			if ( isset( $clean[ $field ] ) && ! in_array( $clean[ $field ], $allowed, true ) ) {
				unset( $clean[ $field ] );
			}
		}

		$bool_fields = array( 'enabled', 'dismissible', 'show_when_empty' );
		foreach ( $bool_fields as $field ) {
			if ( array_key_exists( $field, $item ) ) {
				$parsed          = $this->parse_boolean( $item[ $field ] );
				$clean[ $field ] = $parsed ?? false;
			}
		}

		if ( array_key_exists( 'expiry_duration', $item ) ) {
			$duration = $this->parse_integer( $item['expiry_duration'] );
			if ( null !== $duration && $duration >= 5 && $duration <= 60 ) {
				$clean['expiry_duration'] = $duration;
			}
		}
		if ( array_key_exists( 'spotlight_product_id', $item ) ) {
			$product_id = $this->parse_integer( $item['spotlight_product_id'] );
			if ( null !== $product_id && $product_id > 0 ) {
				$clean['spotlight_product_id'] = $product_id;
			}
		}
		if ( array_key_exists( 'spotlight_discount', $item ) ) {
			$discount = $this->parse_integer( $item['spotlight_discount'] );
			if ( null !== $discount && $discount >= 1 && $discount <= 50 ) {
				$clean['spotlight_discount'] = $discount;
			}
		}
		if ( 'spotlight' === ( $clean['mode'] ?? '' ) ) {
			$product_id = (int) ( $clean['spotlight_product_id'] ?? 0 );
			$discount   = (int) ( $clean['spotlight_discount'] ?? 0 );
			if ( $product_id <= 0 || $discount < 1 || $discount > 50 ) {
				return null;
			}
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
					return null;
				}
			}
		}

		// Per-item color overrides.
		if ( isset( $item['colors'] ) && is_array( $item['colors'] ) ) {
			$clean['colors'] = $this->sanitize_color_map( $item['colors'] );
		}

		// Rule conditions (free-form values per condition type).
		if ( isset( $item['rules'] ) ) {
			$clean['rules'] = $this->sanitize_rules( $item['rules'] );
		}

		return $clean;
	}

	/**
	 * Sanitize a rules array (enabled flag, match mode, condition list).
	 *
	 * @param array<string, mixed> $rules Raw rules array.
	 * @return array<string, mixed>
	 */
	private function sanitize_rules( array $rules ): array {
		$enabled = $this->parse_boolean( $rules['enabled'] ?? false ) ?? false;
		$match   = $rules['match'] ?? 'all';
		if ( ! is_string( $match ) || ! in_array( $match, array( 'all', 'any' ), true ) ) {
			return $this->disabled_rules();
		}
		$clean = array(
			'enabled' => $enabled,
			'match'   => $match,
		);

		$conditions = array();
		if ( ! array_key_exists( 'conditions', $rules ) ) {
			return $enabled ? $this->disabled_rules() : $clean + array( 'conditions' => array() );
		}
		$raw_conditions = $rules['conditions'];
		if ( ! is_array( $raw_conditions ) || count( $raw_conditions ) > self::MAX_RULE_CONDITIONS ) {
			return $this->disabled_rules();
		}
		foreach ( $raw_conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				return $this->disabled_rules();
			}

			$clean_condition = $this->sanitize_rule_condition( $condition );
			if ( null === $clean_condition ) {
				return $this->disabled_rules();
			}
			$conditions[] = $clean_condition;
		}
		$clean['conditions'] = $conditions;

		return $clean;
	}

	/**
	 * Canonical fail-closed rule shape.
	 *
	 * @return array{enabled: false, match: string, conditions: array{}}
	 */
	private function disabled_rules(): array {
		return array(
			'enabled'    => false,
			'match'      => 'all',
			'conditions' => array(),
		);
	}

	/**
	 * Canonicalize a current params-based or historical top-level condition.
	 *
	 * @param array<string, mixed> $condition Raw condition.
	 * @return array{type: string, params: array<string, mixed>}|null
	 */
	private function sanitize_rule_condition( array $condition ): ?array {
		$type     = isset( $condition['type'] ) && is_scalar( $condition['type'] )
			? sanitize_key( $this->limit_string( (string) $condition['type'], 64 ) )
			: '';
		$params   = is_array( $condition['params'] ?? null ) ? $condition['params'] : array();
		$operator = $params['operator'] ?? ( $condition['operator'] ?? null );
		if ( ! is_scalar( $operator ) ) {
			return null;
		}
		$operator = sanitize_key( $this->limit_string( (string) $operator, 32 ) );

		if ( in_array( $type, array( 'cart_total', 'cart_item_count' ), true ) ) {
			$value = $params['value'] ?? ( $condition['value'] ?? null );
			if ( ! in_array( $operator, array( 'gte', 'lte', 'gt', 'lt', 'eq' ), true ) ) {
				return null;
			}
			$value = 'cart_item_count' === $type ? $this->parse_integer( $value ) : $this->parse_decimal( $value );
			if ( null === $value || $value < 0 || $value > self::MAX_MONEY_VALUE ) {
				return null;
			}

			return array(
				'type'   => $type,
				'params' => array(
					'operator' => $operator,
					'value'    => $value,
				),
			);
		}

		if ( in_array( $type, array( 'cart_contains_product', 'cart_contains_category' ), true ) ) {
			$key    = 'cart_contains_product' === $type ? 'product_ids' : 'category_ids';
			$values = $params[ $key ] ?? ( $condition['value'] ?? null );
			if ( ! in_array( $operator, array( 'any', 'all', 'none' ), true ) || ( ! is_array( $values ) && ! is_scalar( $values ) ) ) {
				return null;
			}
			$values = array_values( is_array( $values ) ? $values : array( $values ) );
			if ( count( $values ) > self::MAX_RULE_VALUES ) {
				return null;
			}
			$parsed = array();
			foreach ( $values as $value ) {
				$id = $this->parse_integer( $value );
				if ( null === $id || $id <= 0 ) {
					return null;
				}
				$parsed[] = $id;
			}
			$values = array_values( array_unique( $parsed ) );
			if ( array() === $values ) {
				return null;
			}

			return array(
				'type'   => $type,
				'params' => array(
					'operator' => $operator,
					$key       => $values,
				),
			);
		}

		if ( 'user_role' === $type ) {
			$roles = $params['roles'] ?? ( $condition['value'] ?? null );
			if ( ! in_array( $operator, array( 'any', 'none' ), true ) || ( ! is_array( $roles ) && ! is_scalar( $roles ) ) ) {
				return null;
			}
			$roles = array_values( is_array( $roles ) ? $roles : array( $roles ) );
			if ( count( $roles ) > self::MAX_RULE_VALUES ) {
				return null;
			}
			$roles = array_values(
				array_unique(
					array_filter(
						array_map(
							fn( mixed $role ): string => is_scalar( $role )
								? sanitize_key( $this->limit_string( (string) $role, 64 ) )
								: '',
							$roles
						)
					)
				)
			);
			if ( array() === $roles ) {
				return null;
			}

			return array(
				'type'   => $type,
				'params' => array(
					'operator' => $operator,
					'roles'    => $roles,
				),
			);
		}

		return null;
	}

	/**
	 * Sanitize the 'shipping_meter' group, including the free-form tiers list.
	 *
	 * @param array<string, mixed> $value   Raw shipping meter settings.
	 * @param array<string, mixed> $default_value Default shipping meter settings.
	 * @return array<string, mixed>
	 */
	private function sanitize_shipping_meter( array $value, array $default_value ): array {
		$clean      = array();
		$tier_shape = $default_value['tiers'][0] ?? array();

		foreach ( $default_value as $key => $sub_default ) {
			if ( 'tiers' === $key ) {
				$clean[ $key ] = isset( $value[ $key ] ) && is_array( $value[ $key ] )
					? array_values(
						array_map(
							fn( $tier ) => $this->sanitize_shipping_tier( (array) $tier, $tier_shape ),
							array_slice( array_values( array_filter( $value[ $key ], 'is_array' ) ), 0, self::MAX_SHIPPING_TIERS )
						)
					)
					: $sub_default;
				continue;
			}

			$clean[ $key ] = array_key_exists( $key, $value )
				? $this->sanitize_value( 'shipping_meter.' . $key, $value[ $key ], $sub_default )
				: $sub_default;
		}

		return $clean;
	}

	/**
	 * Sanitize a single shipping tier against the known tier shape.
	 *
	 * @param array<string, mixed> $tier  Raw tier.
	 * @param array<string, mixed> $shape Known tier field shape (from defaults).
	 * @return array<string, mixed>
	 */
	private function sanitize_shipping_tier( array $tier, array $shape ): array {
		$clean = array();

		foreach ( $shape as $field => $field_default ) {
			if ( array_key_exists( $field, $tier ) ) {
				$clean[ $field ] = $this->sanitize_value( 'shipping_meter.tiers.' . $field, $tier[ $field ], $field_default );
			} else {
				$clean[ $field ] = $field_default;
			}
		}

		$reward_type = isset( $tier['reward_type'] ) && is_string( $tier['reward_type'] )
			? sanitize_key( $tier['reward_type'] )
			: (string) ( $shape['reward_type'] ?? 'percentage_discount' );
		if ( ! in_array( $reward_type, array( 'percentage_discount', 'fixed_discount', 'free_gift', 'free_shipping' ), true ) ) {
			$reward_type = (string) ( $shape['reward_type'] ?? 'percentage_discount' );
		}
		$clean['reward_type'] = $reward_type;

		$reward_value = $tier['reward_value'] ?? ( $shape['reward_value'] ?? 0 );
		if ( 'free_gift' === $reward_type ) {
			$parsed_reward = $this->parse_integer( $reward_value );
			$reward_value  = null !== $parsed_reward && $parsed_reward > 0 ? $parsed_reward : 0;
		} elseif ( 'free_shipping' === $reward_type ) {
			$reward_value = 0;
		} else {
			$parsed_reward = $this->parse_canonical_decimal( $reward_value );
			$maximum       = 'percentage_discount' === $reward_type ? 100.0 : self::MAX_MONEY_VALUE;
			$reward_value  = null !== $parsed_reward && $parsed_reward >= 0 && $parsed_reward <= $maximum
				? $parsed_reward
				: (float) ( $shape['reward_value'] ?? 0 );
			if ( floor( $reward_value ) === $reward_value ) {
				$reward_value = (int) $reward_value;
			}
		}
		$clean['reward_value'] = $reward_value;

		return $clean;
	}

	/**
	 * Sanitize the 'bundle_builder' group, including the free-form upsells list.
	 *
	 * @param array<string, mixed> $value   Raw bundle builder settings.
	 * @param array<string, mixed> $default_value Default bundle builder settings.
	 * @return array<string, mixed>
	 */
	private function sanitize_bundle_builder( array $value, array $default_value ): array {
		$clean = array();

		foreach ( $default_value as $key => $sub_default ) {
			if ( 'upsells' === $key ) {
				$upsells = array();
				if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
					foreach ( array_slice( array_values( $value[ $key ] ), 0, self::MAX_BUNDLE_UPSELLS ) as $upsell ) {
						if ( ! is_array( $upsell ) ) {
							continue;
						}
						$sanitized = $this->sanitize_upsell( $upsell );
						if ( null !== $sanitized ) {
							$upsells[] = $sanitized;
						}
					}
				}
				$clean[ $key ] = isset( $value[ $key ] ) && is_array( $value[ $key ] ) ? $upsells : $sub_default;
				continue;
			}

			$clean[ $key ] = array_key_exists( $key, $value )
				? $this->sanitize_value( 'bundle_builder.' . $key, $value[ $key ], $sub_default )
				: $sub_default;
		}

		return $clean;
	}

	/**
	 * Sanitize a single bundle upsell config and its companions.
	 *
	 * @param array<string, mixed> $upsell Raw upsell.
	 * @return array<string, mixed>|null
	 */
	private function sanitize_upsell( array $upsell ): ?array {
		$trigger   = $this->parse_integer( $upsell['trigger_product_id'] ?? null );
		$discount  = $this->parse_percentage_decimal( $upsell['discount'] ?? 0 );
		$config_id = is_string( $upsell['id'] ?? '' )
			? $this->limit_string( $upsell['id'] ?? '', self::MAX_IDENTIFIER_LENGTH )
			: null;
		if (
			null === $trigger
			|| $trigger <= 0
			|| null === $discount
			|| $discount < 0
			|| $discount > 100
			|| ! is_array( $upsell['companions'] ?? null )
			|| count( $upsell['companions'] ) > self::MAX_BUNDLE_COMPANIONS
			|| ! is_string( $config_id )
			|| ( '' !== $config_id && 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $config_id ) )
			|| ( '' !== $config_id && is_numeric( $config_id ) && null === $this->parse_integer( $config_id ) )
		) {
			return null;
		}

		$clean = array(
			'id'                 => $config_id,
			'trigger_product_id' => $trigger,
			'discount'           => floor( $discount ) === $discount ? (int) $discount : $discount,
		);

		$companions = array();
		foreach ( $upsell['companions'] as $companion ) {
			if ( ! is_array( $companion ) ) {
				return null;
			}
			$product_id = $this->parse_integer( $companion['product_id'] ?? null );
			if (
				null === $product_id
				|| $product_id <= 0
				|| $product_id === $trigger
				|| in_array( $product_id, $companions, true )
			) {
				return null;
			}
			$companions[] = $product_id;
		}
		if ( array() === $companions ) {
			return null;
		}
		$clean['companions'] = array_map(
			static fn( int $product_id ): array => array( 'product_id' => $product_id ),
			$companions
		);

		return $clean;
	}

	/**
	 * Sanitize the 'smart_addons' group (keyed addon definitions).
	 *
	 * @param array<string, mixed> $value   Raw smart addons settings.
	 * @param array<string, mixed> $default_value Default smart addons settings.
	 * @return array<string, mixed>
	 */
	private function sanitize_smart_addons( array $value, array $default_value ): array {
		$addon_shape = $default_value['addons']['shipping_protection'] ?? array();
		$addons      = array();
		$collisions  = array();
		$raw_addons  = is_array( $value['addons'] ?? null ) ? $value['addons'] : array();

		foreach ( array_slice( $raw_addons, 0, self::MAX_SMART_ADDONS, true ) as $slug => $addon ) {
			if ( ! is_array( $addon ) ) {
				continue;
			}
			$clean_slug = sanitize_key( $this->limit_string( (string) $slug, 64 ) );
			if ( '' === $clean_slug || isset( $collisions[ $clean_slug ] ) ) {
				continue;
			}
			if ( isset( $addons[ $clean_slug ] ) ) {
				unset( $addons[ $clean_slug ] );
				$collisions[ $clean_slug ] = true;
				continue;
			}
			$clean = array();
			foreach ( $addon_shape as $field => $field_default ) {
				if ( array_key_exists( $field, $addon ) ) {
					$clean[ $field ] = $this->sanitize_value( 'smart_addons.addons.' . $field, $addon[ $field ], $field_default );
				} else {
					$clean[ $field ] = $field_default;
				}
			}
			$addons[ $clean_slug ] = $clean;
		}

		return array( 'addons' => $addons );
	}

	/**
	 * Sanitize the 'advanced' group, with special handling for custom_css.
	 *
	 * The custom_css value must not be passed through sanitize_text_field()
	 * (which would destroy the CSS). Instead strip all HTML tags and remove any
	 * closing </style>-like sequences that could break out of the inline
	 * <style> block.
	 *
	 * @param array<string, mixed> $value   Raw advanced settings.
	 * @param array<string, mixed> $default_value Default advanced settings.
	 * @return array<string, mixed>
	 */
	private function sanitize_advanced( array $value, array $default_value ): array {
		$clean = array();

		foreach ( $default_value as $key => $sub_default ) {
			if ( 'custom_css' === $key ) {
				$clean[ $key ] = isset( $value[ $key ] ) && is_string( $value[ $key ] )
					? $this->sanitize_custom_css( $value[ $key ] )
					: $sub_default;
				continue;
			}

			$clean[ $key ] = array_key_exists( $key, $value )
				? $this->sanitize_value( 'advanced.' . $key, $value[ $key ], $sub_default )
				: $sub_default;
		}

		return $clean;
	}

	/**
	 * Sanitize a free-form color map (e.g. per-item color overrides).
	 *
	 * @param array<string, mixed> $colors Raw color map.
	 * @return array<string, string>
	 */
	private function sanitize_color_map( array $colors ): array {
		$clean = array();

		foreach ( array_slice( $colors, 0, 32, true ) as $key => $color ) {
			$clean_key = $this->limit_string( sanitize_key( (string) $key ), 64 );
			if ( ! is_string( $color ) ) {
				continue;
			}
			$sanitized = $this->sanitize_css_color( $color );
			if ( '' !== $clean_key && null !== $sanitized ) {
				$clean[ $clean_key ] = $sanitized;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize a custom CSS string for safe storage and output.
	 *
	 * Strips all HTML tags and neutralises any closing </style> sequences so
	 * the value cannot break out of the inline <style> element it is printed in.
	 *
	 * @param  string $css Raw CSS string.
	 * @return string
	 */
	public function sanitize_custom_css( string $css ): string {
		$css = $this->limit_string( $css, self::MAX_CUSTOM_CSS_LENGTH );
		$css = wp_strip_all_tags( $css );

		// Defence in depth: remove anything resembling a </style> close tag,
		// including obfuscated variants, even after tag stripping.
		$css = preg_replace( '#<\s*/?\s*style[^>]*>?#i', '', $css );

		return is_string( $css ) ? $this->limit_string( trim( $css ), self::MAX_CUSTOM_CSS_LENGTH ) : '';
	}

	/**
	 * Merge a sparse update while replacing declared collection values as units.
	 *
	 * @param array<string, mixed> $base  Existing settings.
	 * @param array<string, mixed> $patch Sanitized sparse patch.
	 * @param string               $path  Current dot path.
	 * @return array<string, mixed>
	 */
	private function merge_patch( array $base, array $patch, string $path = '' ): array {
		foreach ( $patch as $key => $value ) {
			$child_path = '' === $path ? (string) $key : $path . '.' . $key;
			if ( $this->patch_collection_is_atomic( $child_path ) ) {
				$base[ $key ] = $value;
				continue;
			}
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) ) {
				$base[ $key ] = $this->merge_patch( $base[ $key ], $value, $child_path );
				continue;
			}
			$base[ $key ] = $value;
		}

		return $base;
	}
}
