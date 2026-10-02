<?php
/**
 * Handles plugin text domain loading.
 *
 * @package CartPops
 */

declare( strict_types=1 );

namespace CartPops\I18n;

/**
 * Handles plugin text domain loading.
 */
final class Translator {

	/**
	 * Load the plugin text domain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'cartpops',
			false,
			dirname( plugin_basename( CARTPOPS_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register dynamic settings strings for WPML / Polylang.
	 *
	 * Fixed keys (checkout_button_text, heading, etc.) are handled
	 * automatically via wpml-config.xml. This method registers
	 * variable-length arrays that can't be declared in the XML.
	 */
	public function register_dynamic_strings(): void {
		$settings = get_option( 'cartpops_settings', array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		// Shipping meter tiers.
		foreach ( $settings['shipping_meter']['tiers'] ?? array() as $i => $tier ) {
			if ( ! empty( $tier['message_remaining'] ) ) {
				do_action( 'wpml_register_single_string', 'CartPops', "Shipping Tier {$i} - Remaining", $tier['message_remaining'] );
			}
			if ( ! empty( $tier['message_qualified'] ) ) {
				do_action( 'wpml_register_single_string', 'CartPops', "Shipping Tier {$i} - Qualified", $tier['message_qualified'] );
			}
		}

		// Notification bar items.
		foreach ( $settings['notifications']['drawer_bar_items'] ?? array() as $item ) {
			$item_id = $item['id'] ?? '';

			if ( ! $item_id ) {
				continue;
			}

			foreach ( array( 'expiry_message', 'message_text', 'spotlight_message', 'message_link_text' ) as $field ) {
				if ( ! empty( $item[ $field ] ) ) {
					do_action( 'wpml_register_single_string', 'CartPops', "Notification {$item_id} - {$field}", $item[ $field ] );
				}
			}
		}
	}
}
