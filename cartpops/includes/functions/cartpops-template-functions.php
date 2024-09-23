<?php

/**
 * Template functions.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The plugin path
 *
 * @param string $dir
 * @param string $file
 *
 * @return    string    The plugin path.
 * @since     1.0.0
 */
function cartpops_plugin_path( $dir = null, $file = null ) {
	$path = CARTPOPS_PATH;
	if ( ! empty( $dir ) ) {
		$path .= $dir . '/';
	}
	if ( ! empty( $file ) ) {
		$path .= $file;
	}
	return $path;
}

/**
 * Get plugin template
 *
 * @param string $slug
 *
 * @return    string    The template
 * @since     1.0.0
 */
function cartpops_get_plugin_template( $slug ) {
	$plugin_path = cartpops_plugin_path();
	$template    = null;

	// Get default slug.php.
	if ( file_exists( $plugin_path . "templates/{$slug}.php" ) ) {
		$template = $plugin_path . "templates/{$slug}.php";
	}

	return $template;
}

/**
 * The plugin theme templates path
 *
 * @return    string    The plugin theme templates path.
 * @since     1.0.0
 */
function cartpops_template_path() {
	return apply_filters( 'cartpops_template_path', CARTPOPS_PREFIX . '/' );
}

/**
 * Get plugin template within active theme
 *
 * @param string $slug
 *
 * @return    string    The template
 * @since     1.0.0
 */
function cartpops_get_theme_template( $slug ) {
	// Look inside theme folders.
	$template_path = cartpops_template_path();

	$template = locate_template( array( $template_path . "{$slug}.php" ) );

	return $template;
}

/**
 * Get plugin template. Look within the plugin and active theme
 *
 * @param string $slug
 * @param array  $args
 * @param bool   $return
 * @param bool   $locate_only
 *
 * @return    string    The template
 * @since     1.0.0
 */
function cartpops_get_template( $slug, $args = array(), $return = false, $locate_only = false ) {

	$template = null;

	if ( ! CARTPOPS_TEMPLATE_DEBUG_MODE ) {
		$template = cartpops_get_theme_template( $slug );
	}

	if ( empty( $template ) ) {
		$template = cartpops_get_plugin_template( $slug );
	}

	// Allow 3rd party plugins to filter template file from their plugin.
	$template = apply_filters( 'cartpops_template', $template, $slug );

	if ( $locate_only ) {
		return $template;
	}

	if ( $template ) {
		extract( $args ); // phpcs:ignore

		if ( $return ) {
			ob_start();
			require $template;
			return ob_get_clean();
		} else {
			require $template;
			return '';
		}
	}

}

if ( ! function_exists( 'cartpops_get_pagination_classes' ) ) {

	/**
	 * Get the pagination classes.
	 *
	 * @return array
	 */
	function cartpops_get_pagination_classes( $page_no, $current_page ) {
		$classes = array( 'cartpops_pagination', 'cartpops_pagination_' . $page_no );

		if ( $current_page == $page_no ) {
			$classes[] = 'current';
		}

		return apply_filters( 'cartpops_pagination_classes', $classes, $page_no, $current_page );
	}
}

if ( ! function_exists( 'cartpops_get_pagination_number' ) ) {

	/**
	 * Get the pagination number.
	 *
	 * @return string
	 */
	function cartpops_get_pagination_number( $start, $page_count, $current_page ) {
		$page_no = false;
		if ( $current_page <= $page_count && $start <= $page_count ) {
			$page_no = $start;
		} elseif ( $current_page > $page_count ) {
			$overall_count = $current_page - $page_count + $start;
			if ( $overall_count <= $current_page ) {
				$page_no = $overall_count;
			}
		}

		return apply_filters( 'cartpops_pagination_number', $page_no, $start, $page_count, $current_page );
	}
}

if ( ! function_exists( 'cartpops_get_upsell_product_heading_label' ) ) {

	/**
	 * Get the label for upsell product heading.
	 *
	 * @return string.
	 * */
	function cartpops_get_upsell_product_heading_label() {

		return apply_filters( 'cartpops_upsell_product_heading_label', get_option( 'cartpops_settings_product_upsell_heading_label' ) );
	}
}

if ( ! function_exists( 'cartpops_get_upsell_product_add_to_cart_button_label' ) ) {

	/**
	 * Get the label for upsell product add to cart button.
	 *
	 * @return string.
	 * */
	function cartpops_get_upsell_product_add_to_cart_button_label() {

		return apply_filters( 'cartpops_upsell_product_add_to_cart_button_label', get_option( 'cartpops_settings_product_upsell_add_to_cart_button_label' ) );
	}
}

if ( ! function_exists( 'cartpops_get_upsell_product_dropdown_default_value_label' ) ) {

	/**
	 * Get the label for upsell product dropdown default value.
	 *
	 * @return string.
	 * */
	function cartpops_get_upsell_product_dropdown_default_value_label() {

		return apply_filters( 'cartpops_upsell_product_dropdown_default_value_label', get_option( 'cartpops_settings_product_upsell_dropdown_default_option_label', 'Please select a Upsell' ) );
	}
}

if ( ! function_exists( 'cartpops_get_dropdown_upsell_product_name' ) ) {

	/**
	 * Get the dropdown upsell product name.
	 *
	 * @return string.
	 * */
	function cartpops_get_dropdown_upsell_product_name( $product_id, $product = false ) {
		if ( ! is_object( $product ) ) {
			$product = wc_get_product( $product_id );
		}

		return apply_filters( 'cartpops_get_dropdown_upsell_product_name', $product->get_name(), $product );
	}
}

if ( ! function_exists( 'cartpops_show_dropdown_add_to_button' ) ) {

	/**
	 * Show the dropdown add to cart button.
	 *
	 * @return bool.
	 * */
	function cartpops_show_dropdown_add_to_button() {

		return apply_filters( 'cartpops_show_dropdown_add_to_button', '2' != get_option( 'cartpops_settings_dropdown_add_to_cart_behaviour' ) );
	}
}

if ( ! function_exists( 'cartpops_render_product_name' ) ) {

	/**
	 * Display the upsell product name in table.
	 *
	 * @return string
	 */
	function cartpops_render_product_name( $product, $echo = true ) {

		$product_name = $product->get_name();

		if ( '2' == get_option( 'cartpops_settings_upsell_display_product_linkable', '1' ) ) {
			$product_name = "<a href='" . get_permalink( $product->get_id() ) . "'>" . esc_html( $product_name ) . '</a>';
		}

		$product_name = apply_filters( 'cartpops_upsell_product_name', $product_name, $product );

		if ( $echo ) {
			echo wp_kses_post( $product_name );
		}

		return $product_name;
	}
}
