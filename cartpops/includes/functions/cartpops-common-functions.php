<?php

/**
 * Common functions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


/**
 * Check if the resource is array.
 *
 * @return bool
 */
function cartpops_check_is_array( $data ) {
	return ( is_array( $data ) && ! empty( $data ) );
}


/**
 *  Display Price based wc_price function
 *
 * @return string
 */
function cartpops_price( $price, $echo = true ) {

	if ( $echo ) {
		echo wp_kses_post( wc_price( $price ) );
	}

	return wc_price( $price );
}


/**
 * Display the product image.
 *
 * @return mixed
 */
function cartpops_render_product_image( $product, $size = 'woocommerce_thumbnail', $echo = true ) {

	if ( $echo ) {
		echo wp_kses_post( $product->get_image( $size ) );
	}

	return $product->get_image();
}

/**
 * Get the WC cart subtotal.
 *
 * @return string/float
 */
function cartpops_get_wc_cart_subtotal() {
	if ( ! is_object( WC()->cart ) ) {
		return 0;
	}

	if ( method_exists( WC()->cart, 'get_subtotal' ) ) {
		$subtotal = ( 'incl' == get_option( 'woocommerce_tax_display_cart' ) ) ? WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax() : WC()->cart->get_subtotal();
	} else {
		$subtotal = ( 'incl' == get_option( 'woocommerce_tax_display_cart' ) ) ? WC()->cart->subtotal + WC()->cart->subtotal_tax : WC()->cart->subtotal;
	}

	return $subtotal;
}

/**
 * Get the WC cart total.
 *
 * @return string/float
 */
function cartpops_get_wc_cart_total() {
	if ( ! is_object( WC()->cart ) ) {
		return 0;
	}

	if ( version_compare( WC()->version, '3.2.0', '>=' ) ) {
		$total = WC()->cart->get_total( true );
	} else {
		$total = WC()->cart->total;
	}

	return $total;
}

/**
 * Get the free upsell products in the cart.
 *
 * @return array/int
 */
function cartpops_get_upsell_products_in_cart( $count = false, $automatic = false ) {
	$upsell_products       = array();
	$upsell_products_count = 0;

	if ( is_object( WC()->cart ) ) {
		foreach ( WC()->cart->get_cart() as $key => $value ) {
			if ( ! isset( $value['cartpops_upsell_product'] ) ) {
				continue;
			}

			if ( $automatic && 'automatic' == $value['cartpops_upsell_product']['mode'] ) {
				$value['cartpops_upsell_product']['quantity'] = $value['quantity'];
				$upsell_products_count                       += $value['quantity'];

				if ( isset( $upsell_products[ $value['cartpops_upsell_product']['product_id'] ] ) ) {

					$upsell_products[ $value['cartpops_upsell_product']['product_id'] ][ $value['cartpops_upsell_product']['rule_id'] ] = $value['quantity'];
				} else {
					$upsell_products[ $value['cartpops_upsell_product']['product_id'] ] = array( $value['cartpops_upsell_product']['rule_id'] => $value['quantity'] );
				}
			} elseif ( ! $automatic && 'manual' == $value['cartpops_upsell_product']['mode'] ) {
				$value['cartpops_upsell_product']['quantity'] = $value['quantity'];
				$upsell_products_count                       += $value['quantity'];

				if ( isset( $upsell_products[ $value['cartpops_upsell_product']['product_id'] ] ) ) {

					$upsell_products[ $value['cartpops_upsell_product']['product_id'] ][ $value['cartpops_upsell_product']['rule_id'] ] = $value['quantity'];
				} else {
					$upsell_products[ $value['cartpops_upsell_product']['product_id'] ] = array( $value['cartpops_upsell_product']['rule_id'] => $value['quantity'] );
				}
			}
		}
	}

	if ( $count ) {
		return $upsell_products_count;
	}

	return $upsell_products;
}

/**
 * Get the buy product count in the cart.
 *
 * @return int
 */
function cartpops_get_buy_product_count_in_cart( $buy_product_id ) {
	$buy_product_count = 0;
	if ( ! is_object( WC()->cart ) ) {
		return $buy_product_count;
	}

	foreach ( WC()->cart->get_cart() as $key => $value ) {
		if ( isset( $value['cartpops_upsell_product'] ) ) {
			continue;
		}

		$product_id = ! empty( $value['variation_id'] ) ? $value['variation_id'] : $value['product_id'];

		if ( $product_id != $buy_product_id ) {
			continue;
		}

		$buy_product_count += $value['quantity'];
	}

	return $buy_product_count;
}

/**
 * Get the coupon upsell product count in the cart.
 *
 * @return int
 */
function cartpops_get_coupon_upsell_product_count_in_cart( $product_id, $coupon_id, $rule_id ) {
	$quantity = 0;
	if ( ! is_object( WC()->cart ) ) {
		return $quantity;
	}

	foreach ( WC()->cart->get_cart() as $key => $value ) {
		if ( ! isset( $value['cartpops_upsell_product']['mode'] ) || 'coupon' != $value['cartpops_upsell_product']['mode'] ) {
			continue;
		}

		if ( $rule_id != $value['cartpops_upsell_product']['rule_id'] ) {
			continue;
		}

		if ( $coupon_id != $value['cartpops_upsell_product']['coupon_id'] ) {
			continue;
		}

		if ( $product_id != $value['cartpops_upsell_product']['product_id'] ) {
			continue;
		}

		$quantity += $value['quantity'];
	}

	return $quantity;
}

/**
 * Get the free upsell products count in the cart.
 *
 * @return integer
 */
function cartpops_get_free_upsell_products_count_in_cart( $exclude_bogo = false ) {
	$free_upsell_products_count = 0;
	if ( ! is_object( WC()->cart ) ) {
		return $free_upsell_products_count;
	}

	foreach ( WC()->cart->get_cart() as $key => $value ) {
		if ( ! isset( $value['cartpops_upsell_product'] ) ) {
			continue;
		}

		if ( $exclude_bogo && ( ! isset( $value['cartpops_upsell_product']['mode'] ) || 'bogo' == $value['cartpops_upsell_product']['mode'] ) ) {
			continue;
		}

		$value['cartpops_upsell_product']['quantity'] = $value['quantity'];
		$free_upsell_products_count                  += $value['quantity'];
	}

	return $free_upsell_products_count;
}

/**
 * Get the rule products count in Cart
 *
 * @return int
 */
function cartpops_get_rule_products_count_in_cart( $rule_id ) {
	$count = 0;
	if ( ! is_object( WC()->cart ) ) {
		return $count;
	}

	foreach ( WC()->cart->get_cart() as $key => $value ) {
		if ( ! isset( $value['cartpops_upsell_product'] ) ) {
			continue;
		}

		if ( $value['cartpops_upsell_product']['rule_id'] != $rule_id ) {
			continue;
		}

		$count += $value['quantity'];
	}

	return $count;
}

/**
 * Get the cart item count from the cart.
 *
 * @return int
 */
function cartpops_get_cart_item_count( $exclude_upsell = true ) {
	$count = 0;
	if ( ! is_object( WC()->cart ) ) {
		return $count;
	}

	foreach ( WC()->cart->get_cart() as $key => $value ) {
		if ( isset( $value['cartpops_upsell_product'] ) && $exclude_upsell ) {
			continue;
		}

		$count ++;
	}

	return $count;
}

/**
 * Get the category subtotal from the cart.
 *
 * @return float
 */
function cartpops_get_wc_cart_category_subtotal( $category_ids ) {
	$cart_total = 0;
	if ( ! cartpops_check_is_array( $category_ids ) ) {
		return $cart_total;
	}

	if ( ! is_object( WC()->cart ) ) {
		return $cart_total;
	}

	$tax_display_cart = get_option( 'woocommerce_tax_display_cart' );
	foreach ( WC()->cart->get_cart() as $key => $value ) {

		if ( isset( $value['cartpops_upsell_product'] ) ) {
			continue;
		}

		$product_categories = get_the_terms( $value['product_id'], 'product_cat' );
		if ( ! cartpops_check_is_array( $product_categories ) ) {
			continue;
		}

		foreach ( $product_categories as $product_category ) {
			if ( in_array( $product_category->term_id, $category_ids ) ) {
				$cart_total += ( 'incl' == $tax_display_cart ) ? $value['line_subtotal'] + $value['line_subtotal_tax'] : $value['line_subtotal'];
				break;
			}
		}
	}

	return $cart_total;
}

/**
 * Get the product count in the cart.
 *
 * @return int
 */
function cartpops_get_product_count_in_cart( $product_id ) {
	$product_count = 0;
	if ( ! is_object( WC()->cart ) ) {
		return $product_count;
	}

	foreach ( WC()->cart->get_cart() as $key => $value ) {

		$cart_product_id = ! empty( $value['variation_id'] ) ? $value['variation_id'] : $value['product_id'];

		if ( $cart_product_id != $product_id ) {
			continue;
		}

		$product_count += $value['quantity'];
	}

	return $product_count;
}

/**
 * Get the user address meta(s).
 *
 * @return array
 */
function cartpops_get_address_metas( $flag ) {

	$address_metas = array(
		'first_name',
		'last_name',
		'company',
		'address_1',
		'address_2',
		'city',
		'country',
		'postcode',
		'state',
	);

	return 'billing' == $flag ? array_merge( $address_metas, array( 'email', 'phone' ) ) : $address_metas;
}

/**
 * Get the user address.
 *
 * @return array
 */
function cartpops_get_address( $user_id, $flag ) {
	$billing_metas = cartpops_get_address_metas( $flag );

	foreach ( $billing_metas as $each_meta ) {
		$billing_address[ $each_meta ] = get_user_meta( $user_id, $flag . '_' . $each_meta, true );
	}

	return $billing_address;
}

/**
 * Get the upsells per page column count.
 *
 * @return int
 */
function cartpops_get_free_upsells_per_page_column_count() {
	// To avoid pagination if the table pagination is disabled.
	$display_table_pagination = get_option( 'cartpops_settings_upsell_display_table_pagination' );
	if ( '2' == $display_table_pagination ) {
		return 10000;
	}

	$per_page = get_option( 'cartpops_settings_free_upsell_per_page_column_count', 4 );

	if ( ! $per_page ) {
		return 4;
	}

	return $per_page;
}

/**
 * Get the carousel options.
 *
 * @return array
 */
function cartpops_get_carousel_options() {

	// Declare values.
	$nav            = ( 'yes' == get_option( 'cartpops_settings_carousel_navigation' ) ) ? true : false;
	$auto_play      = ( 'yes' == get_option( 'cartpops_settings_carousel_auto_play' ) ) ? true : false;
	$pagination     = ( 'yes' == get_option( 'cartpops_settings_carousel_pagination' ) ) ? true : false;
	$nav_prev_text  = get_option( 'cartpops_settings_carousel_navigation_prevoius_text' );
	$nav_next_text  = get_option( 'cartpops_settings_carousel_navigation_next_text' );
	$per_page       = get_option( 'cartpops_settings_carousel_upsell_per_page', 3 );
	$item_margin    = get_option( 'cartpops_settings_carousel_item_margin' );
	$item_per_slide = get_option( 'cartpops_settings_carousel_item_per_slide' );
	$slide_speed    = get_option( 'cartpops_settings_carousel_slide_speed' );

	$nav_prev_text  = ( empty( $nav_prev_text ) ) ? '<' : $nav_prev_text;
	$nav_next_text  = ( empty( $nav_next_text ) ) ? '<' : $nav_next_text;
	$per_page       = ( empty( $per_page ) ) ? '3' : $per_page;
	$item_margin    = ( empty( $item_margin ) ) ? '10' : $item_margin;
	$item_per_slide = ( empty( $item_per_slide ) ) ? '1' : $item_per_slide;
	$slide_speed    = ( empty( $slide_speed ) ) ? '5000' : $slide_speed;

	return array(
		'per_page'       => $per_page,
		'item_margin'    => $item_margin,
		'nav'            => json_encode( $nav ),
		'nav_prev_text'  => $nav_prev_text,
		'nav_next_text'  => $nav_next_text,
		'pagination'     => json_encode( $pagination ),
		'item_per_slide' => $item_per_slide,
		'slide_speed'    => $slide_speed,
		'auto_play'      => json_encode( $auto_play ),
	);
}


/**
 * Get the rule translated string.
 *
 * @return mixed
 */
function cartpops_get_rule_translated_string( $option_name, $value, $language = null ) {
	return apply_filters( 'cartpops_rule_translate_string', $value, $option_name, $language );
}

/**
 * Get the product object by product id.
 *
 * @return object/bool
 */
function cartpops_get_product( $product_id ) {
	if ( ! apply_filters( 'cartpops_is_valid_product', true, $product_id ) ) {
		return false;
	}

	return apply_filters( 'cartpops_get_product', wc_get_product( $product_id ), $product_id );
}


function cartpops_implode_html_attributes( $raw_attributes ) {
	$attributes = array();
	foreach ( $raw_attributes as $name => $value ) {
		$attributes[] = esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
	}
	return implode( ' ', $attributes );
}

/**
 * Get an outbound URL to WooPop.scom
 *
 * @param string $slug Any slug, defaults to homepage.
 * @param string $source Supply the utm_source, defaults to plugin dashboard.
 * @param string $campaign Supply the utm_campaign, defaults to upgrade to pro.
 * @return string
 */
function cartpops_outbound_url( $slug = '', $source = 'Plugin Dashboard', $campaign = 'Upgrade to Pro', $medium = 'CartPops Plugin Dashboard' ) {
	$url        = 'https://cartpops.com/';
	$parameters = array();

	if ( $slug ) {
		$url = $url . $slug;
	}

	$args = array(
		'utm_source'   => esc_attr( $source ),
		'utm_campaign' => esc_attr( $campaign ),
		'utm_medium'   => esc_attr( $medium ),
	);

	$args = urlencode_deep( $args );

	$url = add_query_arg( $args, $url );

	return $url;
}

function cartpops_get_emoji_flag( $country_code ) {
	$emoji_flags = array();

	$emoji_flags['AD'] = "\u{1F1E6}\u{1F1E9}";
	$emoji_flags['AE'] = "\u{1F1E6}\u{1F1EA}";
	$emoji_flags['AF'] = "\u{1F1E6}\u{1F1EB}";
	$emoji_flags['AG'] = "\u{1F1E6}\u{1F1EC}";
	$emoji_flags['AI'] = "\u{1F1E6}\u{1F1EE}";
	$emoji_flags['AL'] = "\u{1F1E6}\u{1F1F1}";
	$emoji_flags['AM'] = "\u{1F1E6}\u{1F1F2}";
	$emoji_flags['AO'] = "\u{1F1E6}\u{1F1F4}";
	$emoji_flags['AQ'] = "\u{1F1E6}\u{1F1F6}";
	$emoji_flags['AR'] = "\u{1F1E6}\u{1F1F7}";
	$emoji_flags['AS'] = "\u{1F1E6}\u{1F1F8}";
	$emoji_flags['AT'] = "\u{1F1E6}\u{1F1F9}";
	$emoji_flags['AU'] = "\u{1F1E6}\u{1F1FA}";
	$emoji_flags['AW'] = "\u{1F1E6}\u{1F1FC}";
	$emoji_flags['AX'] = "\u{1F1E6}\u{1F1FD}";
	$emoji_flags['AZ'] = "\u{1F1E6}\u{1F1FF}";
	$emoji_flags['BA'] = "\u{1F1E7}\u{1F1E6}";
	$emoji_flags['BB'] = "\u{1F1E7}\u{1F1E7}";
	$emoji_flags['BD'] = "\u{1F1E7}\u{1F1E9}";
	$emoji_flags['BE'] = "\u{1F1E7}\u{1F1EA}";
	$emoji_flags['BF'] = "\u{1F1E7}\u{1F1EB}";
	$emoji_flags['BG'] = "\u{1F1E7}\u{1F1EC}";
	$emoji_flags['BH'] = "\u{1F1E7}\u{1F1ED}";
	$emoji_flags['BI'] = "\u{1F1E7}\u{1F1EE}";
	$emoji_flags['BJ'] = "\u{1F1E7}\u{1F1EF}";
	$emoji_flags['BL'] = "\u{1F1E7}\u{1F1F1}";
	$emoji_flags['BM'] = "\u{1F1E7}\u{1F1F2}";
	$emoji_flags['BN'] = "\u{1F1E7}\u{1F1F3}";
	$emoji_flags['BO'] = "\u{1F1E7}\u{1F1F4}";
	$emoji_flags['BQ'] = "\u{1F1E7}\u{1F1F6}";
	$emoji_flags['BR'] = "\u{1F1E7}\u{1F1F7}";
	$emoji_flags['BS'] = "\u{1F1E7}\u{1F1F8}";
	$emoji_flags['BT'] = "\u{1F1E7}\u{1F1F9}";
	$emoji_flags['BV'] = "\u{1F1E7}\u{1F1FB}";
	$emoji_flags['BW'] = "\u{1F1E7}\u{1F1FC}";
	$emoji_flags['BY'] = "\u{1F1E7}\u{1F1FE}";
	$emoji_flags['BZ'] = "\u{1F1E7}\u{1F1FF}";
	$emoji_flags['CA'] = "\u{1F1E8}\u{1F1E6}";
	$emoji_flags['CC'] = "\u{1F1E8}\u{1F1E8}";
	$emoji_flags['CD'] = "\u{1F1E8}\u{1F1E9}";
	$emoji_flags['CF'] = "\u{1F1E8}\u{1F1EB}";
	$emoji_flags['CG'] = "\u{1F1E8}\u{1F1EC}";
	$emoji_flags['CH'] = "\u{1F1E8}\u{1F1ED}";
	$emoji_flags['CI'] = "\u{1F1E8}\u{1F1EE}";
	$emoji_flags['CK'] = "\u{1F1E8}\u{1F1F0}";
	$emoji_flags['CL'] = "\u{1F1E8}\u{1F1F1}";
	$emoji_flags['CM'] = "\u{1F1E8}\u{1F1F2}";
	$emoji_flags['CN'] = "\u{1F1E8}\u{1F1F3}";
	$emoji_flags['CO'] = "\u{1F1E8}\u{1F1F4}";
	$emoji_flags['CR'] = "\u{1F1E8}\u{1F1F7}";
	$emoji_flags['CU'] = "\u{1F1E8}\u{1F1FA}";
	$emoji_flags['CV'] = "\u{1F1E8}\u{1F1FB}";
	$emoji_flags['CW'] = "\u{1F1E8}\u{1F1FC}";
	$emoji_flags['CX'] = "\u{1F1E8}\u{1F1FD}";
	$emoji_flags['CY'] = "\u{1F1E8}\u{1F1FE}";
	$emoji_flags['CZ'] = "\u{1F1E8}\u{1F1FF}";
	$emoji_flags['DE'] = "\u{1F1E9}\u{1F1EA}";
	$emoji_flags['DG'] = "\u{1F1E9}\u{1F1EC}";
	$emoji_flags['DJ'] = "\u{1F1E9}\u{1F1EF}";
	$emoji_flags['DK'] = "\u{1F1E9}\u{1F1F0}";
	$emoji_flags['DM'] = "\u{1F1E9}\u{1F1F2}";
	$emoji_flags['DO'] = "\u{1F1E9}\u{1F1F4}";
	$emoji_flags['DZ'] = "\u{1F1E9}\u{1F1FF}";
	$emoji_flags['EC'] = "\u{1F1EA}\u{1F1E8}";
	$emoji_flags['EE'] = "\u{1F1EA}\u{1F1EA}";
	$emoji_flags['EG'] = "\u{1F1EA}\u{1F1EC}";
	$emoji_flags['EH'] = "\u{1F1EA}\u{1F1ED}";
	$emoji_flags['ER'] = "\u{1F1EA}\u{1F1F7}";
	$emoji_flags['ES'] = "\u{1F1EA}\u{1F1F8}";
	$emoji_flags['ET'] = "\u{1F1EA}\u{1F1F9}";
	$emoji_flags['FI'] = "\u{1F1EB}\u{1F1EE}";
	$emoji_flags['FJ'] = "\u{1F1EB}\u{1F1EF}";
	$emoji_flags['FK'] = "\u{1F1EB}\u{1F1F0}";
	$emoji_flags['FM'] = "\u{1F1EB}\u{1F1F2}";
	$emoji_flags['FO'] = "\u{1F1EB}\u{1F1F4}";
	$emoji_flags['FR'] = "\u{1F1EB}\u{1F1F7}";
	$emoji_flags['GA'] = "\u{1F1EC}\u{1F1E6}";
	$emoji_flags['GB'] = "\u{1F1EC}\u{1F1E7}";
	$emoji_flags['GD'] = "\u{1F1EC}\u{1F1E9}";
	$emoji_flags['GE'] = "\u{1F1EC}\u{1F1EA}";
	$emoji_flags['GF'] = "\u{1F1EC}\u{1F1EB}";
	$emoji_flags['GG'] = "\u{1F1EC}\u{1F1EC}";
	$emoji_flags['GH'] = "\u{1F1EC}\u{1F1ED}";
	$emoji_flags['GI'] = "\u{1F1EC}\u{1F1EE}";
	$emoji_flags['GL'] = "\u{1F1EC}\u{1F1F1}";
	$emoji_flags['GM'] = "\u{1F1EC}\u{1F1F2}";
	$emoji_flags['GN'] = "\u{1F1EC}\u{1F1F3}";
	$emoji_flags['GP'] = "\u{1F1EC}\u{1F1F5}";
	$emoji_flags['GQ'] = "\u{1F1EC}\u{1F1F6}";
	$emoji_flags['GR'] = "\u{1F1EC}\u{1F1F7}";
	$emoji_flags['GS'] = "\u{1F1EC}\u{1F1F8}";
	$emoji_flags['GT'] = "\u{1F1EC}\u{1F1F9}";
	$emoji_flags['GU'] = "\u{1F1EC}\u{1F1FA}";
	$emoji_flags['GW'] = "\u{1F1EC}\u{1F1FC}";
	$emoji_flags['GY'] = "\u{1F1EC}\u{1F1FE}";
	$emoji_flags['HK'] = "\u{1F1ED}\u{1F1F0}";
	$emoji_flags['HM'] = "\u{1F1ED}\u{1F1F2}";
	$emoji_flags['HN'] = "\u{1F1ED}\u{1F1F3}";
	$emoji_flags['HR'] = "\u{1F1ED}\u{1F1F7}";
	$emoji_flags['HT'] = "\u{1F1ED}\u{1F1F9}";
	$emoji_flags['HU'] = "\u{1F1ED}\u{1F1FA}";
	$emoji_flags['ID'] = "\u{1F1EE}\u{1F1E9}";
	$emoji_flags['IE'] = "\u{1F1EE}\u{1F1EA}";
	$emoji_flags['IL'] = "\u{1F1EE}\u{1F1F1}";
	$emoji_flags['IM'] = "\u{1F1EE}\u{1F1F2}";
	$emoji_flags['IN'] = "\u{1F1EE}\u{1F1F3}";
	$emoji_flags['IO'] = "\u{1F1EE}\u{1F1F4}";
	$emoji_flags['IQ'] = "\u{1F1EE}\u{1F1F6}";
	$emoji_flags['IR'] = "\u{1F1EE}\u{1F1F7}";
	$emoji_flags['IS'] = "\u{1F1EE}\u{1F1F8}";
	$emoji_flags['IT'] = "\u{1F1EE}\u{1F1F9}";
	$emoji_flags['JE'] = "\u{1F1EF}\u{1F1EA}";
	$emoji_flags['JM'] = "\u{1F1EF}\u{1F1F2}";
	$emoji_flags['JO'] = "\u{1F1EF}\u{1F1F4}";
	$emoji_flags['JP'] = "\u{1F1EF}\u{1F1F5}";
	$emoji_flags['KE'] = "\u{1F1F0}\u{1F1EA}";
	$emoji_flags['KG'] = "\u{1F1F0}\u{1F1EC}";
	$emoji_flags['KH'] = "\u{1F1F0}\u{1F1ED}";
	$emoji_flags['KI'] = "\u{1F1F0}\u{1F1EE}";
	$emoji_flags['KM'] = "\u{1F1F0}\u{1F1F2}";
	$emoji_flags['KN'] = "\u{1F1F0}\u{1F1F3}";
	$emoji_flags['KP'] = "\u{1F1F0}\u{1F1F5}";
	$emoji_flags['KR'] = "\u{1F1F0}\u{1F1F7}";
	$emoji_flags['KW'] = "\u{1F1F0}\u{1F1FC}";
	$emoji_flags['KY'] = "\u{1F1F0}\u{1F1FE}";
	$emoji_flags['KZ'] = "\u{1F1F0}\u{1F1FF}";
	$emoji_flags['LA'] = "\u{1F1F1}\u{1F1E6}";
	$emoji_flags['LB'] = "\u{1F1F1}\u{1F1E7}";
	$emoji_flags['LC'] = "\u{1F1F1}\u{1F1E8}";
	$emoji_flags['LI'] = "\u{1F1F1}\u{1F1EE}";
	$emoji_flags['LK'] = "\u{1F1F1}\u{1F1F0}";
	$emoji_flags['LR'] = "\u{1F1F1}\u{1F1F7}";
	$emoji_flags['LS'] = "\u{1F1F1}\u{1F1F8}";
	$emoji_flags['LT'] = "\u{1F1F1}\u{1F1F9}";
	$emoji_flags['LU'] = "\u{1F1F1}\u{1F1FA}";
	$emoji_flags['LV'] = "\u{1F1F1}\u{1F1FB}";
	$emoji_flags['LY'] = "\u{1F1F1}\u{1F1FE}";
	$emoji_flags['MA'] = "\u{1F1F2}\u{1F1E6}";
	$emoji_flags['MC'] = "\u{1F1F2}\u{1F1E8}";
	$emoji_flags['MD'] = "\u{1F1F2}\u{1F1E9}";
	$emoji_flags['ME'] = "\u{1F1F2}\u{1F1EA}";
	$emoji_flags['MF'] = "\u{1F1F2}\u{1F1EB}";
	$emoji_flags['MG'] = "\u{1F1F2}\u{1F1EC}";
	$emoji_flags['MH'] = "\u{1F1F2}\u{1F1ED}";
	$emoji_flags['MK'] = "\u{1F1F2}\u{1F1F0}";
	$emoji_flags['ML'] = "\u{1F1F2}\u{1F1F1}";
	$emoji_flags['MM'] = "\u{1F1F2}\u{1F1F2}";
	$emoji_flags['MN'] = "\u{1F1F2}\u{1F1F3}";
	$emoji_flags['MO'] = "\u{1F1F2}\u{1F1F4}";
	$emoji_flags['MP'] = "\u{1F1F2}\u{1F1F5}";
	$emoji_flags['MQ'] = "\u{1F1F2}\u{1F1F6}";
	$emoji_flags['MR'] = "\u{1F1F2}\u{1F1F7}";
	$emoji_flags['MS'] = "\u{1F1F2}\u{1F1F8}";
	$emoji_flags['MT'] = "\u{1F1F2}\u{1F1F9}";
	$emoji_flags['MU'] = "\u{1F1F2}\u{1F1FA}";
	$emoji_flags['MV'] = "\u{1F1F2}\u{1F1FB}";
	$emoji_flags['MW'] = "\u{1F1F2}\u{1F1FC}";
	$emoji_flags['MX'] = "\u{1F1F2}\u{1F1FD}";
	$emoji_flags['MY'] = "\u{1F1F2}\u{1F1FE}";
	$emoji_flags['MZ'] = "\u{1F1F2}\u{1F1FF}";
	$emoji_flags['NA'] = "\u{1F1F3}\u{1F1E6}";
	$emoji_flags['NC'] = "\u{1F1F3}\u{1F1E8}";
	$emoji_flags['NE'] = "\u{1F1F3}\u{1F1EA}";
	$emoji_flags['NF'] = "\u{1F1F3}\u{1F1EB}";
	$emoji_flags['NG'] = "\u{1F1F3}\u{1F1EC}";
	$emoji_flags['NI'] = "\u{1F1F3}\u{1F1EE}";
	$emoji_flags['NL'] = "\u{1F1F3}\u{1F1F1}";
	$emoji_flags['NO'] = "\u{1F1F3}\u{1F1F4}";
	$emoji_flags['NP'] = "\u{1F1F3}\u{1F1F5}";
	$emoji_flags['NR'] = "\u{1F1F3}\u{1F1F7}";
	$emoji_flags['NU'] = "\u{1F1F3}\u{1F1FA}";
	$emoji_flags['NZ'] = "\u{1F1F3}\u{1F1FF}";
	$emoji_flags['OM'] = "\u{1F1F4}\u{1F1F2}";
	$emoji_flags['PA'] = "\u{1F1F5}\u{1F1E6}";
	$emoji_flags['PE'] = "\u{1F1F5}\u{1F1EA}";
	$emoji_flags['PF'] = "\u{1F1F5}\u{1F1EB}";
	$emoji_flags['PG'] = "\u{1F1F5}\u{1F1EC}";
	$emoji_flags['PH'] = "\u{1F1F5}\u{1F1ED}";
	$emoji_flags['PK'] = "\u{1F1F5}\u{1F1F0}";
	$emoji_flags['PL'] = "\u{1F1F5}\u{1F1F1}";
	$emoji_flags['PM'] = "\u{1F1F5}\u{1F1F2}";
	$emoji_flags['PN'] = "\u{1F1F5}\u{1F1F3}";
	$emoji_flags['PR'] = "\u{1F1F5}\u{1F1F7}";
	$emoji_flags['PS'] = "\u{1F1F5}\u{1F1F8}";
	$emoji_flags['PT'] = "\u{1F1F5}\u{1F1F9}";
	$emoji_flags['PW'] = "\u{1F1F5}\u{1F1FC}";
	$emoji_flags['PY'] = "\u{1F1F5}\u{1F1FE}";
	$emoji_flags['QA'] = "\u{1F1F6}\u{1F1E6}";
	$emoji_flags['RE'] = "\u{1F1F7}\u{1F1EA}";
	$emoji_flags['RO'] = "\u{1F1F7}\u{1F1F4}";
	$emoji_flags['RS'] = "\u{1F1F7}\u{1F1F8}";
	$emoji_flags['RU'] = "\u{1F1F7}\u{1F1FA}";
	$emoji_flags['RW'] = "\u{1F1F7}\u{1F1FC}";
	$emoji_flags['SA'] = "\u{1F1F8}\u{1F1E6}";
	$emoji_flags['SB'] = "\u{1F1F8}\u{1F1E7}";
	$emoji_flags['SC'] = "\u{1F1F8}\u{1F1E8}";
	$emoji_flags['SD'] = "\u{1F1F8}\u{1F1E9}";
	$emoji_flags['SE'] = "\u{1F1F8}\u{1F1EA}";
	$emoji_flags['SG'] = "\u{1F1F8}\u{1F1EC}";
	$emoji_flags['SH'] = "\u{1F1F8}\u{1F1ED}";
	$emoji_flags['SI'] = "\u{1F1F8}\u{1F1EE}";
	$emoji_flags['SJ'] = "\u{1F1F8}\u{1F1EF}";
	$emoji_flags['SK'] = "\u{1F1F8}\u{1F1F0}";
	$emoji_flags['SL'] = "\u{1F1F8}\u{1F1F1}";
	$emoji_flags['SM'] = "\u{1F1F8}\u{1F1F2}";
	$emoji_flags['SN'] = "\u{1F1F8}\u{1F1F3}";
	$emoji_flags['SO'] = "\u{1F1F8}\u{1F1F4}";
	$emoji_flags['SR'] = "\u{1F1F8}\u{1F1F7}";
	$emoji_flags['SS'] = "\u{1F1F8}\u{1F1F8}";
	$emoji_flags['ST'] = "\u{1F1F8}\u{1F1F9}";
	$emoji_flags['SV'] = "\u{1F1F8}\u{1F1FB}";
	$emoji_flags['SX'] = "\u{1F1F8}\u{1F1FD}";
	$emoji_flags['SY'] = "\u{1F1F8}\u{1F1FE}";
	$emoji_flags['SZ'] = "\u{1F1F8}\u{1F1FF}";
	$emoji_flags['TC'] = "\u{1F1F9}\u{1F1E8}";
	$emoji_flags['TD'] = "\u{1F1F9}\u{1F1E9}";
	$emoji_flags['TF'] = "\u{1F1F9}\u{1F1EB}";
	$emoji_flags['TG'] = "\u{1F1F9}\u{1F1EC}";
	$emoji_flags['TH'] = "\u{1F1F9}\u{1F1ED}";
	$emoji_flags['TJ'] = "\u{1F1F9}\u{1F1EF}";
	$emoji_flags['TK'] = "\u{1F1F9}\u{1F1F0}";
	$emoji_flags['TL'] = "\u{1F1F9}\u{1F1F1}";
	$emoji_flags['TM'] = "\u{1F1F9}\u{1F1F2}";
	$emoji_flags['TN'] = "\u{1F1F9}\u{1F1F3}";
	$emoji_flags['TO'] = "\u{1F1F9}\u{1F1F4}";
	$emoji_flags['TR'] = "\u{1F1F9}\u{1F1F7}";
	$emoji_flags['TT'] = "\u{1F1F9}\u{1F1F9}";
	$emoji_flags['TV'] = "\u{1F1F9}\u{1F1FB}";
	$emoji_flags['TW'] = "\u{1F1F9}\u{1F1FC}";
	$emoji_flags['TZ'] = "\u{1F1F9}\u{1F1FF}";
	$emoji_flags['UA'] = "\u{1F1FA}\u{1F1E6}";
	$emoji_flags['UG'] = "\u{1F1FA}\u{1F1EC}";
	$emoji_flags['UM'] = "\u{1F1FA}\u{1F1F2}";
	$emoji_flags['US'] = "\u{1F1FA}\u{1F1F8}";
	$emoji_flags['UY'] = "\u{1F1FA}\u{1F1FE}";
	$emoji_flags['UZ'] = "\u{1F1FA}\u{1F1FF}";
	$emoji_flags['VA'] = "\u{1F1FB}\u{1F1E6}";
	$emoji_flags['VC'] = "\u{1F1FB}\u{1F1E8}";
	$emoji_flags['VE'] = "\u{1F1FB}\u{1F1EA}";
	$emoji_flags['VG'] = "\u{1F1FB}\u{1F1EC}";
	$emoji_flags['VI'] = "\u{1F1FB}\u{1F1EE}";
	$emoji_flags['VN'] = "\u{1F1FB}\u{1F1F3}";
	$emoji_flags['VU'] = "\u{1F1FB}\u{1F1FA}";
	$emoji_flags['WF'] = "\u{1F1FC}\u{1F1EB}";
	$emoji_flags['WS'] = "\u{1F1FC}\u{1F1F8}";
	$emoji_flags['XK'] = "\u{1F1FD}\u{1F1F0}";
	$emoji_flags['YE'] = "\u{1F1FE}\u{1F1EA}";
	$emoji_flags['YT'] = "\u{1F1FE}\u{1F1F9}";
	$emoji_flags['ZA'] = "\u{1F1FF}\u{1F1E6}";
	$emoji_flags['ZM'] = "\u{1F1FF}\u{1F1F2}";
	$emoji_flags['ZW'] = "\u{1F1FF}\u{1F1FC}";

	foreach ( $emoji_flags as $key => $value ) {
		if ( $country_code === $key ) {
			return $value;
		}
	}
}


/**
 * Explode earray
 *
 * @param strinig $glue A seperator.
 * @param array   $array An array to be exploded.
 * @param string  $symbol A symbol betweeen the key values.
 * @author CartPops <help@cartpops.com>
 */
function cartpops_mapped_implode( $glue, $array, $symbol = '=' ) {
	return implode(
		$glue,
		array_map(
			function( $k, $v ) use ( $symbol ) {
				if ( ! is_array( $v ) ) {
					return $k . $symbol . $v;
				}
			},
			array_keys( $array ),
			array_values( $array )
		)
	);
}


/**
 * Returns the price of an item in the cart.
 *
 * @param object $product
 * @param string $cart_item
 * @param string $cart_item_key
 *
 * @return mixed|void
 */
function cartpops_cart_item_price( &$product, &$cart_item, $cart_item_key ) {
	$price                 = '';
	$onsale                = $product->is_on_sale();
	$slashed_price         = $product->get_price_html();
	$display               = apply_filters( 'cartpops_cart_item_price_display', 'subtotal' );
	$quantity              = cartpops_get_quantity_from_cart_item( $cart_item, $cart_item_key );
	$product_regular_price = get_post_meta( $product->get_id(), '_regular_price', true );
	$product_sale_price    = get_post_meta( $product->get_id(), '_sale_price', true );

	if ( ! empty( $product_sale_price ) ) {
		$percentage = ( ( $product_regular_price - $product_sale_price ) * 100 ) / $product_regular_price;
	}

	$price .= apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // PHPCS: XSS ok.

	if ( $onsale ) {
		$price .= '<small class="">' . __( 'You save', 'cartpops' ) . ' ' . round( $percentage ) . '%</small>';
	}

	return $price;
}

/**
 * Returns the product image.
 *
 * @param object $product
 * @param string $cart_item
 * @param string $cart_item_key
 *
 * @return mixed|void
 */
function cartpops_cart_item_thumbnail( &$product, &$cart_item, $cart_item_key ) {
	return apply_filters(
		'woocommerce_cart_item_thumbnail',
		$product->get_image(),
		$cart_item,
		$cart_item_key
	);
}

/**
 * Returns the quantity from the cart item.
 *
 * @param string $cart_item
 * @param string $cart_item_key
 *
 * @return string
 */
function cartpops_get_quantity_from_cart_item( &$cart_item, $cart_item_key ) {
	return $cart_item['quantity'];
}

/**
 * Returns the cart item.
 *
 * @param array  $cart_item
 * @param string $cart_item_key
 *
 * @return WC_Product
 */
function cartpops_cart_item_product( &$cart_item, $cart_item_key ) {
	return apply_filters(
		'woocommerce_cart_item_product',
		$cart_item['data'],
		$cart_item,
		$cart_item_key
	);
}

/**
 * Helper fuction to get the product name.
 *
 * @param  object $cart_item     Single cart item.
 * @param  string $cart_item_key Single cart key.
 * @return string
 */
function cartpops_cart_item_product_name( $cart_item, $cart_item_key ) {
	$html              = '';
	$_product          = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
	$product_id        = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
	$product_permalink = apply_filters( 'woocommercee_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );

	$html .= '<div class="cpops-cart-item__product--link" data-id="' . $product_id . '">';

	if ( ! $product_permalink ) {
		$html .= wp_kses_post(
			apply_filters(
				'woocommerce_cart_item_name',
				$_product->get_name(),
				$cart_item,
				$cart_item_key
			) . '&nbsp;'
		);
	} else {
		$html .= wp_kses_post(
			apply_filters(
				'woocommerce_cart_item_name',
				sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $_product->get_name() ),
				$cart_item,
				$cart_item_key
			)
		);
	}

	$html .= '</div>';

	$html .= cartpops_after_cart_item_name( $cart_item, $cart_item_key );

	// Backorder notification.
	if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
        $html .= wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'cartpops' ) . '</p>', $product_id ) ); // phpcs:ignore
	}

	return $html;

}

/**
 * Returns the neccessary hooks and data after the product name in a collapsable section (or not).
 *
 * @param  object $cart_item     WooCommerce cart item object.
 * @param  string $cart_item_key WooCommerce cart item key.
 * @author CartPops <help@cartpops.com>
 */
function cartpops_after_cart_item_name( &$cart_item, $cart_item_key ) {
	$collapsible           = '';
	$html                  = '';
	$cart_item_data        = wc_get_formatted_cart_item_data( $cart_item );
	$_product              = $cart_item['data'];
	$slashed_price         = $_product->get_price_html();
	$show_details_collapse = apply_filters( 'cartpops_after_cart_item_name_hook_collapsible', true );

	if ( $show_details_collapse ) {
		ob_start();
		do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
	} else {
		do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
	}

	if ( apply_filters( 'cartpops_after_cart_item_name_price', false ) ) {
		$html .= '<div class="cpops-cart-item__product--single-price">' . $slashed_price . '</div>';
	}

	// Gets and formats a list of cart item data + variations for display on the frontend.
	if ( $show_details_collapse && $cart_item_data ) {
		$collapsible .= '<h5 class="">' . __( 'Product details', 'cartpops' ) . '</h5>';
		$collapsible .= $cart_item_data;

		$html .= '<span class="cpops-collapse-btn-link" data-cpops-toggle="collapse" data-cpops-target=".cpops-collapse-' . esc_attr( $cart_item_key ) . '" role="button" aria-expanded="false">' . __( 'View details', 'cartpops' ) . '</span>';
		$html .= '<div class="cpops-cart-item__product--data cpops-collapse cpops-collapse-' . esc_attr( $cart_item_key ) . '">';
        $html .= $collapsible; // phpcs:ignore
		$html .= '</div>';

		$collapsible .= ob_get_clean();
	}

	return $html;
}

function cartpops_set_cart_constant() {
	wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
}
