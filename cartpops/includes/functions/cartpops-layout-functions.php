<?php
/**
 * Layout functions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! function_exists( 'cartpops_select2_html' ) ) {

	/**
	 * Return or display Select2 HTML
	 *
	 * @return string
	 */
	function cartpops_select2_html( $args, $echo = true ) {
		$args = wp_parse_args(
			$args,
			array(
				'class'                   => '',
				'id'                      => '',
				'name'                    => '',
				'list_type'               => '',
				'action'                  => '',
				'placeholder'             => '',
				'exclude_global_variable' => 'no',
				'custom_attributes'       => array(),
				'multiple'                => true,
				'allow_clear'             => true,
				'selected'                => true,
				'options'                 => array(),
			)
		);

		$multiple = $args['multiple'] ? 'multiple' : '';
		$name     = esc_attr( '' !== $args['name'] ? $args['name'] : $args['id'] ) . '[]';
		$options  = array_filter( cartpops_check_is_array( $args['options'] ) ? $args['options'] : array() );

		$allowed_html = array(
			'select' => array(
				'id'                            => array(),
				'class'                         => array(),
				'data-placeholder'              => array(),
				'data-allow_clear'              => array(),
				'data-exclude-global-variable'  => array(),
				'data-action'                   => array(),
				'data-nonce'                    => array(),
				'data-maximum-selection-length' => array(),
				'multiple'                      => array(),
				'name'                          => array(),
			),
			'option' => array(
				'value'    => array(),
				'selected' => array(),
			),
		);

		// Custom attribute handling.
		$custom_attributes = cartpops_format_custom_attributes( $args );
		$data_nonce        = ( 'products' == $args['list_type'] ) ? 'data-nonce="' . wp_create_nonce( 'search-products' ) . '"' : '';

		ob_start();
		?><select <?php echo esc_attr( $multiple ); ?>
			name="<?php echo esc_attr( $name ); ?>"
			id="<?php echo esc_attr( $args['id'] ); ?>"
			data-action="<?php echo esc_attr( $args['action'] ); ?>"
			data-exclude-global-variable="<?php echo esc_attr( $args['exclude_global_variable'] ); ?>"
			class="cartpops_select2_search <?php echo esc_attr( $args['class'] ); ?>"
			data-placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
		<?php echo wp_kses( implode( ' ', $custom_attributes ), $allowed_html ); ?>
		<?php echo wp_kses( $data_nonce, $allowed_html ); ?>
		<?php echo $args['allow_clear'] ? 'data-allow_clear="true"' : ''; ?> >
		<?php
		if ( is_array( $args['options'] ) ) {
			foreach ( $args['options'] as $option_id ) {
				 $option_value = '';
				switch ( $args['list_type'] ) {
					case 'post':
						$option_value = get_the_title( $option_id );
						break;
					case 'coupons':
						$option_value = get_the_title( $option_id ) . ' (#' . absint( $option_id ) . ')';
						break;
					case 'products':
						$product = wc_get_product( $option_id );
						if ( $product ) {
							$option_value = $product->get_name() . ' (#' . absint( $option_id ) . ')';
						}
						break;
					case 'customers':
								$user = get_user_by( 'id', $option_id );
						if ( $user ) {
							$option_value = $user->display_name . '(#' . absint( $user->ID ) . ' &ndash; ' . $user->user_email . ')';
						}
						break;
				}

				if ( $option_value ) {
					?>
						<option value="<?php echo esc_attr( $option_id ); ?>" <?php echo $args['selected'] ? 'selected="selected"' : ''; // WPCS: XSS ok. ?>><?php echo esc_html( $option_value ); ?></option>
					 <?php
				}
			}
		}
		?>
		</select>
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			echo wp_kses( $html, $allowed_html );
		}

		return $html;
	}
}

if ( ! function_exists( 'cartpops_format_custom_attributes' ) ) {

	/**
	 * Format Custom Attributes
	 *
	 * @return array
	 */
	function cartpops_format_custom_attributes( $value ) {
		$custom_attributes = array();

		if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
			foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
				$custom_attributes[] = esc_attr( $attribute ) . '=' . esc_attr( $attribute_value ) . '';
			}
		}

		return $custom_attributes;
	}
}

if ( ! function_exists( 'cartpops_get_datepicker_html' ) ) {

	/**
	 * Return or display Datepicker HTML
	 *
	 * @return string
	 */
	function cartpops_get_datepicker_html( $args, $echo = true ) {
		$args = wp_parse_args(
			$args,
			array(
				'class'             => '',
				'id'                => '',
				'name'              => '',
				'placeholder'       => '',
				'custom_attributes' => array(),
				'value'             => '',
				'wp_zone'           => true,
			)
		);

		$name = ( '' !== $args['name'] ) ? $args['name'] : $args['id'];

		$allowed_html = array(
			'input' => array(
				'id'          => array(),
				'type'        => array(),
				'placeholder' => array(),
				'class'       => array(),
				'value'       => array(),
				'name'        => array(),
				'min'         => array(),
				'max'         => array(),
				'style'       => array(),
			),
		);

		// Custom attribute handling.
		$custom_attributes = cartpops_format_custom_attributes( $args );
		$value             = ! empty( $args['value'] ) ? CartPops_Date_Time::get_date_object_format_datetime( $args['value'], 'date', $args['wp_zone'] ) : '';
		ob_start();
		?>
		<input type = "text"
			   id="<?php echo esc_attr( $args['id'] ); ?>"
			   value = "<?php echo esc_attr( $value ); ?>"
			   class="cartpops_datepicker <?php echo esc_attr( $args['class'] ); ?>"
			   placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
		<?php echo wp_kses( implode( ' ', $custom_attributes ), $allowed_html ); ?>
			   />

		<input type = "hidden"
			   class="cartpops_alter_datepicker_value"
			   name="<?php echo esc_attr( $name ); ?>"
			   value = "<?php echo esc_attr( $args['value'] ); ?>"
			   />
		<?php
		$html = ob_get_clean();

		if ( $echo ) {
			echo wp_kses( $html, $allowed_html );
		}

		return $html;
	}
}


