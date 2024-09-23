<?php

use CartPops\Admin\Options;

/**
 * Fired during plugin deactivation
 *
 * @link       https://cartpops.com
 * @since      1.0.0
 *
 * @package    CartPops
 * @subpackage CartPops/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    CartPops
 * @subpackage CartPops/includes
 * @author     CartPops <help@cartpops.com>
 */
class CartPops_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
			self::remove_options();
	}

	/**
	 * Removes all plugin options.
	 *
	 * @author CartPops <help@cartpops.com>
	 */
	public static function remove_options() {
		$delete_options = Options::get( 'plugin_reset' );

		if ( 'on' === $delete_options ) {
			global $wpdb;

			$plugin_options = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'cartpops_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $plugin_options as $option ) {
				delete_option( $option->option_name );
			}
		}
	}
}
