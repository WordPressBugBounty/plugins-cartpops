<?php

use CartPops\Admin\Options;
/**
 * Admin Settings Class
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
    // Exit if accessed directly.
}
if ( !class_exists( 'CartPops_Settings' ) ) {
    /**
     * CartPops_Settings Class
     */
    class CartPops_Settings {
        /**
         * Setting pages.
         */
        private static $settings = array();

        /**
         * Error messages.
         */
        private static $errors = array();

        /**
         * Plugin slug.
         */
        private static $plugin_slug = 'cartpops';

        public static $settings_option_group = 'cartpops-settings';

        /**
         * Update messages.
         */
        private static $messages = array();

        /**
         * Include the settings page classes.
         */
        public static function get_settings_pages() {
            if ( !empty( self::$settings ) ) {
                return self::$settings;
            }
            include_once CARTPOPS_PATH . 'includes/abstracts/class-cartpops-settings-page.php';
            $settings = array();
            $tabs = self::settings_page_tabs();
            foreach ( $tabs as $tab_id => $tab_item ) {
                $settings[sanitize_key( $tab_id )] = (include 'tabs/' . sanitize_key( $tab_id ) . '.php');
            }
            self::$settings = apply_filters( sanitize_key( self::$plugin_slug . '_get_settings_pages' ), $settings );
            return self::$settings;
        }

        /**
         * Add a message.
         */
        public static function add_message( $text ) {
            self::$messages[] = $text;
        }

        /**
         * Add an error.
         */
        public static function add_error( $text ) {
            self::$errors[] = $text;
        }

        /**
         * Output messages + errors.
         */
        public static function show_messages() {
            if ( count( self::$errors ) > 0 ) {
                foreach ( self::$errors as $error ) {
                    self::error_message( $error );
                }
            } elseif ( count( self::$messages ) > 0 ) {
                foreach ( self::$messages as $message ) {
                    self::success_message( $message );
                }
            }
        }

        /**
         * Helper function for getting an admin asset from the images folder.
         *
         * @param string $asset Filename like example.png or example.jpg.
         * @author CartPops <help@cartpops.com>
         */
        public static function get_admin_asset( $asset ) {
            return CARTPOPS_URL . 'admin/dist/images/' . $asset;
        }

        /**
         * Show an success message.
         */
        public static function success_message( $text, $echo = true ) {
            ob_start();
            $contents = '<div id="message " class="updated inline ' . esc_html( self::$plugin_slug ) . '_save_msg"><p><strong>' . $text . '</strong></p></div>';
            ob_end_clean();
            if ( $echo ) {
                echo wp_kses_post( $contents );
            } else {
                return $contents;
            }
        }

        /**
         * Show an error message.
         */
        public static function error_message( $text, $echo = true ) {
            ob_start();
            $contents = '<div id="message" class="error inline"><p><strong>' . $text . '</strong></p></div>';
            ob_end_clean();
            if ( $echo ) {
                echo wp_kses_post( $contents );
            } else {
                return $contents;
            }
        }

        /**
         * Show an notice.
         */
        public static function notice( $text, $echo = true ) {
            ob_start();
            $contents = '<div id="message" class="notice inline"><p><strong>' . $text . '</strong></p></div>';
            ob_end_clean();
            if ( $echo ) {
                echo wp_kses_post( $contents );
            } else {
                return $contents;
            }
        }

        /**
         * Settings page tabs
         */
        public static function settings_page_tabs() {
            $menu_items['dashboard'] = array(
                'title' => __( 'Dashboard', 'cartpops' ),
            );
            $menu_items['issues'] = array(
                'title' => __( 'Master logs', 'cartpops' ),
            );
            $menu_items['settings'] = array(
                'title' => __( 'Settings', 'cartpops' ),
            );
            $menu_items['color'] = array(
                'title' => __( 'Color schemes', 'cartpops' ),
            );
            $menu_items['upsells'] = array(
                'title' => __( 'Upsells', 'cartpops' ),
            );
            return $menu_items;
        }

        /**
         * Handles the display of the settings page in admin.
         */
        public static function output() {
            global $current_section, $current_tab;
            do_action( sanitize_key( self::$plugin_slug . '_settings_start' ) );
            $tabs = cartpops_get_allowed_setting_tabs();
            /* Include admin html settings */
            include_once 'views/html-settings.php';
        }

        /**
         * Handles the display of the settings page buttons in page.
         */
        public static function output_buttons( $reset = true ) {
            /* Include admin html settings buttons */
            include_once 'views/html-settings-buttons.php';
        }

        public static function output_beacon() {
            $enabled = Options::get( 'support_chat_enable' );
            if ( 'on' === $enabled ) {
                $data = array(
                    'form_id'  => 'f1495164-3ac9-4e0c-ac46-20c0a6f6749b',
                    'identify' => wp_json_encode( self::identify_data__premium_only() ),
                    'session'  => wp_json_encode( self::get_support_data__premium_only() ),
                );
                require_once 'views/html-beacon.php';
            }
        }

        /**
         * Output admin fields.
         */
        public static function output_fields( $options ) {
            foreach ( $options as $value ) {
                if ( !isset( $value['type'] ) ) {
                    continue;
                }
                $value['id'] = ( isset( $value['id'] ) ? $value['id'] : '' );
                $value['title'] = ( isset( $value['title'] ) ? $value['title'] : '' );
                $value['class'] = ( isset( $value['class'] ) ? $value['class'] : '' );
                $value['css'] = ( isset( $value['css'] ) ? $value['css'] : '' );
                $value['default'] = Options::get_default( $value['id'] );
                $value['desc'] = ( isset( $value['desc'] ) ? $value['desc'] : '' );
                $value['desc_tip'] = ( isset( $value['desc'] ) ? $value['desc'] : false );
                $value['placeholder'] = ( isset( $value['placeholder'] ) ? $value['placeholder'] : '' );
                $value['name'] = ( isset( $value['name'] ) ? $value['name'] : $value['id'] );
                $value['without_label'] = ( isset( $value['without_label'] ) ? $value['without_label'] : false );
                $value['custom_attributes'] = ( isset( $value['custom_attributes'] ) ? $value['custom_attributes'] : '' );
                $value['help_doc_slug'] = ( isset( $value['help_doc_slug'] ) ? $value['help_doc_slug'] : 'docs/' );
                // Custom attribute handling.
                $custom_attributes = cartpops_format_custom_attributes( $value );
                // Description handling.
                $field_description = self::get_field_description( $value );
                extract( $field_description );
                // Option handling.
                $option_name = $value['id'];
                $option_value = $value['default'];
                if ( Options::get( $option_name, false ) ) {
                    $option_value = Options::get( $option_name, false );
                }
                // phpcs:disable
                // Switch based on type.
                switch ( $value['type'] ) {
                    // Section Start.
                    case 'title':
                        ?>
						<div class="cartpops-card"><!--- .cartpops-card -->
							<div class="card-content"><!--- .card-content -->
								<?php 
                        if ( !empty( $value['title'] ) ) {
                            ?>
									<div class="card-head">
										<h2>
											<?php 
                            if ( !empty( $value['icon'] ) ) {
                                ?>
												<i data-feather="<?php 
                                echo esc_attr( $value['icon'] );
                                ?>"></i>
											<?php 
                            }
                            ?>
											<span><?php 
                            echo esc_html( $value['title'] );
                            ?></span>
										</h2>
									</div>
								<?php 
                        }
                        ?>

								<?php 
                        if ( !empty( $value['desc'] ) ) {
                            ?>
									<div class="card-description">
											<p><?php 
                            echo wpautop( wptexturize( wp_kses_post( $value['desc'] ) ) );
                            ?></p>
									</div>
								<?php 
                        }
                        ?>

								<div class="card-inside"><!--- .card-inside -->
									<table class="form-table" role="presentation"><!--- .form-table -->
										<?php 
                        if ( !empty( $value['id'] ) ) {
                            ?>
											<?php 
                            do_action( 'cartpops_settings_' . sanitize_title( $value['id'] ) );
                            ?>
										<?php 
                        }
                        break;
                    // Section End.
                    case 'sectionend':
                        if ( !empty( $value['id'] ) ) {
                            do_action( 'cartpops_settings_' . sanitize_title( $value['id'] ) . '_end' );
                        }
                        ?>
									</table><!--- /.form-table -->
								</div><!--- /.card-inside -->
							</div><!--- /.card-content -->
						</div><!--- /.cartpops-card -->
						<?php 
                        if ( !empty( $value['id'] ) ) {
                            do_action( 'cartpops_settings_' . sanitize_title( $value['id'] ) . '_after' );
                        }
                        break;
                    // Standard text inputs and subtypes like 'number'.
                    case 'text':
                    case 'url':
                    case 'email':
                    case 'number':
                    case 'color':
                    case 'password':
                        $type = $value['type'];
                        if ( $value['type'] == 'color' ) {
                            $type = 'text';
                            $value['class'] .= 'cpops-color-picker';
                        }
                        ?><tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?></label>
								<?php 
                        echo $tooltip_html;
                        ?>
							</th>
							<td class="forminp forminp-<?php 
                        echo sanitize_title( $value['type'] );
                        ?>">
								<input
									name="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									id="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									type="<?php 
                        echo esc_attr( $type );
                        ?>"
									style="<?php 
                        echo esc_attr( $value['css'] );
                        ?>"
									value="<?php 
                        echo esc_attr( $option_value );
                        ?>"
									class="<?php 
                        echo esc_attr( $value['class'] );
                        ?>"
									placeholder="<?php 
                        echo esc_attr( $value['placeholder'] );
                        ?>"
									<?php 
                        echo implode( ' ', $custom_attributes );
                        ?>
									/> <?php 
                        echo $description;
                        ?>
							</td>
						</tr>
						<?php 
                        break;
                    // Textarea.
                    case 'textarea':
                        ?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?> <?php 
                        echo $tooltip_html;
                        // WPCS: XSS ok.
                        ?></label>
							</th>
							<td class="forminp forminp-<?php 
                        echo esc_attr( sanitize_title( $value['type'] ) );
                        ?>">
								<?php 
                        echo $description;
                        // WPCS: XSS ok.
                        ?>

								<textarea
									name="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									id="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									style="<?php 
                        echo esc_attr( $value['css'] );
                        ?>"
									class="<?php 
                        echo esc_attr( $value['class'] );
                        ?>"
									placeholder="<?php 
                        echo esc_attr( $value['placeholder'] );
                        ?>"
									<?php 
                        echo implode( ' ', $custom_attributes );
                        // WPCS: XSS ok.
                        ?>
									><?php 
                        echo esc_textarea( $option_value );
                        // WPCS: XSS ok.
                        ?></textarea>
							</td>
						</tr>
						<?php 
                        break;
                    // Select boxes.
                    case 'select':
                        ?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?></label>
								<?php 
                        echo $tooltip_html;
                        ?>
							</th>
							<td class="forminp forminp-<?php 
                        echo sanitize_title( $value['type'] );
                        ?>">
							<?php 
                        $attributes = array(
                            'class' => '',
                            'name'  => esc_attr( $option_name ),
                            'id'    => esc_attr( $value['id'] ),
                        );
                        if ( array_key_exists( 'attributes', $value ) ) {
                            $attributes = array_merge( $attributes, $value['attributes'] );
                        }
                        echo '<select ' . cartpops_implode_html_attributes( $attributes ) . '>';
                        foreach ( $value['options'] as $k => $v ) {
                            $selected = false;
                            $disabled = false;
                            if ( $k === $option_value ) {
                                $selected = true;
                            }
                            if ( strpos( $v, '(Pro)' ) ) {
                                if ( !fs_cartpops()->can_use_premium_code() && !fs_cartpops()->is_premium() ) {
                                    $disabled = true;
                                    /* translators: %1$s  and %2$s are replaced with a plugin option name and %3$s with an emoji. */
                                    $v = sprintf(
                                        __( '%1$s %2$s Requires upgrade %3$s', 'cartpops' ),
                                        $v,
                                        ' - ',
                                        '🔒'
                                    );
                                    $disabled = true;
                                }
                            }
                            echo '<option ' . selected( $selected, true, false ) . disabled( $disabled, true, false ) . ' value="' . esc_attr( $k ) . '">' . $v . '</option>';
                        }
                        echo '</select> ';
                        ?>
						</td>
						</tr>
						<?php 
                        break;
                    case 'multiselect':
                        ?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?></label>
								<?php 
                        echo $tooltip_html;
                        ?>
							</th>
							<td class="forminp forminp-<?php 
                        echo sanitize_title( $value['type'] );
                        ?>">
								<select
									name="<?php 
                        echo esc_attr( $value['id'] );
                        echo ( 'multiselect' === $value['type'] ? '[]' : '' );
                        ?>"
									id="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									style="<?php 
                        echo esc_attr( $value['css'] );
                        ?>"
									class="<?php 
                        echo esc_attr( $value['class'] );
                        ?>"
									<?php 
                        echo implode( ' ', $custom_attributes );
                        ?>
									<?php 
                        echo ( 'multiselect' == $value['type'] ? 'multiple="multiple"' : '' );
                        ?>
									>
									<?php 
                        foreach ( $value['options'] as $key => $val ) {
                            ?>
											<option value="<?php 
                            echo esc_attr( $key );
                            ?>"
											<?php 
                            if ( is_array( $option_value ) ) {
                                selected( in_array( $key, $option_value ), true );
                            } else {
                                selected( $option_value, $key );
                            }
                            ?>
											><?php 
                            echo $val;
                            ?></option>
											<?php 
                        }
                        ?>
								</select> <?php 
                        echo $description;
                        ?>
							</td>
						</tr>
						<?php 
                        break;
                    // Checkbox input.
                    case 'checkbox':
                        $visbility_class = array();
                        if ( !isset( $value['pro'] ) ) {
                            $value['pro'] = false;
                        }
                        if ( !isset( $value['hide_if_checked'] ) ) {
                            $value['hide_if_checked'] = false;
                        }
                        if ( !isset( $value['show_if_checked'] ) ) {
                            $value['show_if_checked'] = false;
                        }
                        if ( 'yes' == $value['hide_if_checked'] || 'yes' == $value['show_if_checked'] ) {
                            $visbility_class[] = 'hidden_option';
                        }
                        if ( 'option' == $value['hide_if_checked'] ) {
                            $visbility_class[] = 'hide_options_if_checked';
                        }
                        if ( 'option' == $value['show_if_checked'] ) {
                            $visbility_class[] = 'show_options_if_checked';
                        }
                        $disabled = ( true === $value['pro'] && !fs_cartpops()->can_use_premium_code() && !fs_cartpops()->is_premium() ? true : false );
                        if ( !isset( $value['checkboxgroup'] ) || 'start' == $value['checkboxgroup'] ) {
                            ?>
								<tr valign="top" data-group="true" class="<?php 
                            echo esc_attr( implode( ' ', $visbility_class ) );
                            ?>">
									<th scope="row" class="titledesc"><?php 
                            echo esc_html( $value['title'] );
                            echo $tooltip_html;
                            ?></th>
									<td class="forminp forminp-checkbox">
										<fieldset>
							<?php 
                        } else {
                            ?>
								<fieldset class="<?php 
                            echo esc_attr( implode( ' ', $visbility_class ) );
                            ?>">

							<?php 
                        }
                        if ( !empty( $value['title'] ) ) {
                            ?>
								<legend class="screen-reader-text"><span><?php 
                            echo esc_html( $value['title'] );
                            ?></span></legend>
							<?php 
                        }
                        ?>

							<label for="<?php 
                        echo $value['id'];
                        ?>" class="switch" data-paid="<?php 
                        echo ( true === $disabled ? esc_attr( 'true' ) : esc_attr( 'false' ) );
                        ?>" >
								<input
									aria-role="checkbox"
									name="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									id="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"

									type="checkbox"
									class="<?php 
                        echo esc_attr( ( isset( $value['class'] ) ? $value['class'] : '' ) );
                        ?>"
									value="1"
									<?php 
                        checked( $option_value, 'on' );
                        ?>
									<?php 
                        disabled( $disabled, true );
                        ?>
									<?php 
                        echo implode( ' ', $custom_attributes );
                        ?>
								/>
								<span class="slider round"></span>


							</label>
							<?php 
                        echo $description;
                        // WPCS: XSS ok.
                        ?>
						<?php 
                        if ( !isset( $value['checkboxgroup'] ) || 'end' == $value['checkboxgroup'] ) {
                            ?>
										</fieldset>
									</td>
								</tr>
							<?php 
                        } else {
                            ?>
								</fieldset>
							<?php 
                        }
                        break;
                    case 'radio':
                        ?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        echo $tooltip_html;
                        ?></label>
							</th>
							<td class="forminp forminp-<?php 
                        echo sanitize_title( $value['type'] );
                        ?>">
								<fieldset>
									<?php 
                        echo $description;
                        ?>
									<ul>
									<?php 
                        foreach ( $value['options'] as $key => $val ) {
                            ?>
											<li>
												<label><input
													name="<?php 
                            echo esc_attr( $value['id'] );
                            ?>"
													value="<?php 
                            echo $key;
                            ?>"
													type="radio"
													style="<?php 
                            echo esc_attr( $value['css'] );
                            ?>"
													class="<?php 
                            echo esc_attr( $value['class'] );
                            ?>"
												<?php 
                            echo implode( ' ', $custom_attributes );
                            ?>
												<?php 
                            checked( $key, $option_value );
                            ?>
													/> <?php 
                            echo $val['title'];
                            ?></label>
											</li>
											<?php 
                        }
                        ?>
									</ul>
								</fieldset>
							</td>
						</tr>
						<?php 
                        break;
                    case 'radio-images':
                        ?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?></label>
								<?php 
                        echo $tooltip_html;
                        ?>
							</th>

							<td class="forminp forminp-<?php 
                        echo sanitize_title( $value['type'] );
                        ?>">
								<fieldset>
									<?php 
                        echo $description;
                        ?>
									<ul class="cpops-image-choices-field">
										<?php 
                        foreach ( $value['options'] as $key => $options ) {
                            $checked = ( $key === $option_value ? true : false );
                            $disabled = ( true === $options['pro'] && !fs_cartpops()->can_use_premium_code() && !fs_cartpops()->is_premium() ? true : false );
                            ?>
												<li
													data-paid="<?php 
                            echo ( true === $disabled ? esc_attr( 'true' ) : esc_attr( 'false' ) );
                            ?>"
													class="cpops-image-choices-field__choice <?php 
                            echo ( true === $checked ? esc_attr( 'cpops-image-choices-field__choice-selected' ) : '' );
                            ?>"
													>
													<input
														type="radio"
														name="<?php 
                            echo esc_attr( $value['id'] );
                            ?>"
														value="<?php 
                            echo esc_attr( $key );
                            ?>"
														id="<?php 
                            echo esc_attr( $value['id'] . '_' . $key );
                            ?>"
													<?php 
                            checked( $key, $option_value );
                            ?>
													<?php 
                            disabled( $disabled, true );
                            ?>
													/>
													<label for="<?php 
                            echo esc_attr( $value['id'] . '_' . $key );
                            ?>" id="<?php 
                            echo esc_attr( $value['id'] . '_' . $key );
                            ?>">
														<span class="cpops-image-choices-field__choice-image-wrap" style="background-image:url('<?php 
                            echo esc_attr( $options['image'] );
                            ?>')">
															<img alt="" class="cpops-image-choices-field__choice-image" src="'<?php 
                            echo esc_attr( $options['image'] );
                            ?>'">
														</span>
														<span class="cpops-image-choices-field__choice-text"><?php 
                            echo esc_attr( $options['title'] );
                            ?></span>
													</label>
												</li>
												<?php 
                        }
                        ?>
									</ul>
								</fieldset>
							</td>

						</tr>
						<?php 
                        break;
                    // Radio inputs
                    case 'radio':
                        ?>
						<tr valign="top">
							<th scope="row" class="titledesc">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?></label>
								<?php 
                        echo $tooltip_html;
                        ?>
							</th>
							<td class="forminp forminp-<?php 
                        echo sanitize_title( $value['type'] );
                        ?>">
								<fieldset>
									<?php 
                        echo $description;
                        ?>
									<ul>
									<?php 
                        foreach ( $value['options'] as $key => $val ) {
                            ?>
											<li>
												<label><input
													name="<?php 
                            echo esc_attr( $value['id'] );
                            ?>"
													value="<?php 
                            echo $key;
                            ?>"
													type="radio"
													style="<?php 
                            echo esc_attr( $value['css'] );
                            ?>"
													class="<?php 
                            echo esc_attr( $value['class'] );
                            ?>"
												<?php 
                            echo implode( ' ', $custom_attributes );
                            ?>
												<?php 
                            checked( $key, $option_value );
                            ?>
													/> <?php 
                            echo $val;
                            ?></label>
											</li>
											<?php 
                        }
                        ?>
									</ul>
								</fieldset>
							</td>
						</tr>
						<?php 
                        break;
                    // Subtitle.
                    case 'subtitle':
                        ?>
						<tr valign="top" >
							<th scope="row" colspan="2">
								<?php 
                        echo esc_html( $value['title'] );
                        echo wp_kses_post( $tooltip_html );
                        ?>
								<p><?php 
                        echo wp_kses_post( $description );
                        ?></p>
							</th>
						</tr>
						<?php 
                        break;
                    // Buttons.
                    case 'button':
                        ?>
						<tr valign="top">
							<?php 
                        if ( !$value['without_label'] ) {
                            ?>
								<th scope="row">
									<label for="<?php 
                            echo esc_attr( $value['id'] );
                            ?>"><?php 
                            echo esc_html( $value['title'] );
                            ?></label><?php 
                            echo wp_kses_post( $tooltip_html );
                            ?>
								</th>
							<?php 
                        }
                        ?>
							<td>
								<button
									id="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"
									type="<?php 
                        echo esc_attr( $value['type'] );
                        ?>"
									class="<?php 
                        echo esc_attr( $value['class'] );
                        ?>"
									<?php 
                        echo wp_kses_post( implode( ' ', $custom_attributes ) );
                        ?>
									><?php 
                        echo esc_html( $value['default'] );
                        ?> </button>
									<?php 
                        echo wp_kses_post( $description );
                        ?>
							</td>
						</tr>
						<?php 
                        break;
                    case 'ajaxmultiselect':
                        ?>
						<tr valign="top">
							<th scope="row">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        echo $tooltip_html;
                        ?></label>
							</th>
							<td>
								<?php 
                        $value['options'] = $option_value;
                        cartpops_select2_html( $value );
                        echo wp_kses_post( $description );
                        ?>
							</td>
						</tr>
						<?php 
                        break;
                    case 'datepicker':
                        $value['value'] = $option_value;
                        if ( !isset( $value['datepickergroup'] ) || 'start' == $value['datepickergroup'] ) {
                            ?>
							<tr valign="top">
								<th scope="row">
									<label for="<?php 
                            echo esc_attr( $value['id'] );
                            ?>"><?php 
                            echo esc_html( $value['title'] );
                            ?></label><?php 
                            echo wp_kses_post( $tooltip_html );
                            ?>
								</th>
								<td>
									<fieldset>
										<?php 
                        }
                        echo ( isset( $value['label'] ) ? esc_html( $value['label'] ) : '' );
                        cartpops_get_datepicker_html( $value );
                        echo wp_kses_post( $description );
                        if ( !isset( $value['datepickergroup'] ) || 'end' == $value['datepickergroup'] ) {
                            ?>
									</fieldset>
								</td>
							</tr>
						<?php 
                        }
                        ?>
						<?php 
                        break;
                    case 'wpeditor':
                        ?>
						<tr valign="top">
							<th scope="row">
								<label for="<?php 
                        echo esc_attr( $value['id'] );
                        ?>"><?php 
                        echo esc_html( $value['title'] );
                        ?></label><?php 
                        echo wp_kses_post( $tooltip_html );
                        ?>
							</th>
							<td>
								<?php 
                        wp_editor( $option_value, $value['id'], array(
                            'media_buttons' => false,
                            'editor_class'  => esc_attr( $value['class'] ),
                        ) );
                        echo wp_kses_post( $description );
                        ?>
							</td>
						</tr>
						<?php 
                        break;
                    // Default: run an action.
                    default:
                        do_action( 'cartpops_admin_field_' . $value['type'], $value );
                        break;
                }
                // phpcs:enable
            }
        }

        /**
         * Save admin fields.
         *
         * Loops through the woocommerce options array and outputs each field.
         *
         * @param array $options Options array to output.
         * @param array $data    Optional. Data to use for saving. Defaults to $_POST.
         * @return bool
         */
        public static function save_fields( $options, $data = null ) {
            if ( is_null( $data ) ) {
                $data = $_POST;
                // WPCS: input var okay, CSRF ok.
            }
            if ( empty( $data ) ) {
                return false;
            }
            // Options to update will be stored here and saved later.
            $update_options = array();
            $autoload_options = array();
            // Loop options and get values to save.
            foreach ( $options as $option ) {
                if ( !isset( $option['id'] ) || !isset( $option['type'] ) || isset( $option['is_option'] ) && false === $option['is_option'] ) {
                    continue;
                }
                $default = Options::get_default( $option['id'] );
                // Get posted value.
                if ( strstr( $option['id'], '[' ) ) {
                    parse_str( $option['id'], $option_name_array );
                    $option_name = current( array_keys( $option_name_array ) );
                    $setting_name = key( $option_name_array[$option_name] );
                    $raw_value = ( isset( $data[$option_name][$setting_name] ) ? wp_unslash( $data[$option_name][$setting_name] ) : null );
                } else {
                    $option_name = $option['id'];
                    $setting_name = '';
                    $raw_value = ( isset( $data[$option['id']] ) ? wp_unslash( $data[$option['id']] ) : null );
                }
                // Format the value based on option type.
                switch ( $option['type'] ) {
                    case 'checkbox':
                        $value = ( '1' === $raw_value || 'on' === $raw_value ? 'on' : 'off' );
                        break;
                    case 'textarea':
                        if ( 'cartpops_custom_js' === $option_name || 'cartpops_custom_css' === $option_name ) {
                            $value = $raw_value;
                        } else {
                            $value = wp_kses_post( trim( $raw_value ) );
                        }
                        break;
                    case 'multiselect':
                    case 'ajaxmultiselect':
                        $value = array_filter( (array) $raw_value );
                        break;
                    case 'color':
                        $value = $raw_value;
                        break;
                    case 'wpeditor':
                        $value = $raw_value;
                        break;
                    case 'datepicker':
                        $value = wc_clean( $raw_value );
                        break;
                    case 'select':
                        $allowed_values = ( empty( $option['options'] ) ? array() : array_map( 'strval', array_keys( $option['options'] ) ) );
                        if ( empty( $default ) && empty( $allowed_values ) ) {
                            $value = null;
                            break;
                        }
                        $default = ( empty( $default ) ? $allowed_values[0] : $default );
                        $value = ( in_array( $raw_value, $allowed_values, true ) ? $raw_value : $default );
                        break;
                    case 'radio-images':
                        $allowed_values = ( empty( $option['options'] ) ? array() : array_map( 'strval', array_keys( $option['options'] ) ) );
                        if ( empty( $default ) && empty( $allowed_values ) ) {
                            $value = null;
                            break;
                        }
                        $default = ( empty( $default ) ? $allowed_values[0] : $default );
                        $value = ( in_array( $raw_value, $allowed_values, true ) ? $raw_value : $default );
                        break;
                    default:
                        $value = wc_clean( $raw_value );
                        break;
                }
                /**
                 * Sanitize the value of an option.
                 *
                 * @since 2.4.0
                 */
                $value = apply_filters(
                    'cartpops_admin_settings_sanitize_option',
                    $value,
                    $option,
                    $raw_value
                );
                /**
                 * Sanitize the value of an option by option name.
                 *
                 * @since 2.4.0
                 */
                $value = apply_filters(
                    "cartpops_admin_settings_sanitize_option_{$option_name}",
                    $value,
                    $option,
                    $raw_value
                );
                if ( is_null( $value ) ) {
                    continue;
                }
                // Check if option is an array and handle that differently to single values.
                if ( $option_name && $setting_name ) {
                    if ( !isset( $update_options[$option_name] ) ) {
                        $update_options[$option_name] = get_option( $option_name, array() );
                    }
                    if ( !is_array( $update_options[$option_name] ) ) {
                        $update_options[$option_name] = array();
                    }
                    $update_options[$option_name][$setting_name] = $value;
                } else {
                    $update_options[$option_name] = $value;
                }
                $autoload_options[$option_name] = ( isset( $option['autoload'] ) ? (bool) $option['autoload'] : true );
            }
            // Save all options in our array.
            foreach ( $update_options as $name => $value ) {
                update_option( $name, $value, ( $autoload_options[$name] ? 'on' : 'off' ) );
            }
            return true;
        }

        /**
         * Reset admin fields.
         */
        public static function reset_fields( $options ) {
            if ( !is_array( $options ) ) {
                return false;
            }
            // Loop options and get values to reset.
            foreach ( $options as $option ) {
                if ( !isset( $option['id'] ) || !isset( $option['type'] ) ) {
                    continue;
                }
                update_option( $option['id'], Options::get_default( $option['id'] ) );
            }
            return true;
        }

        /**
         * Helper function to get the formated description and tip HTML for a
         * given form field. Plugins can call this when implementing their own custom
         * settings types.
         *
         * @param array $value The form field value array
         * @returns array The description and tip as a 2 element array
         */
        public static function get_field_description( $value ) {
            $description = '';
            $tooltip_html = '';
            if ( !empty( $value['desc'] ) ) {
                $tooltip_html = $value['desc'];
                $description = '';
            } elseif ( false === $value['desc_tip'] ) {
                $description = $value['desc'];
                $tooltip_html = '';
            } elseif ( empty( $value['desc'] ) ) {
                $tooltip_html = $value['title'];
            }
            if ( $description ) {
                $description = '<p style="margin-top:0">' . wp_kses_post( $description ) . '</p>';
            }
            if ( $tooltip_html ) {
                $tooltip_html = self::cartpops_help_tip( $tooltip_html, $value['id'], $value['help_doc_slug'] );
            }
            return array(
                'description'  => $description,
                'tooltip_html' => $tooltip_html,
            );
        }

        /**
         * Display a CartPops help tip.
         *
         * @since  2.5.0
         *
         * @param  string $tip        Help tip text.
         * @param  bool   $allow_html Allow sanitized HTML if true or escape.
         * @return string
         */
        public static function cartpops_help_tip( $tip, $id, $help_doc_slug ) {
            $help_doc_link = cartpops_outbound_url( $help_doc_slug, 'Tooltip', 'Documentation' );
            $html = '<a data-template="tooltip-' . $id . '" href="' . esc_url( $help_doc_link ) . '" title="' . __( 'View Documentation', 'cartpops' ) . '" class="cpops-tooltip" target="_blank" rel=”noopener noreferrer”>?</a>';
            $html .= '<div style="display:none" id="tooltip-' . $id . '">';
            /* translators: %1$s is replaced with an icon */
            $html .= sprintf( esc_html( $tip ) . ' %s', '<span style="text-decoration:underline">' . sprintf( __( 'Click %1$s to go to the documentation', 'cartpops' ), '[?]' ) . '</span>' );
            $html .= '</div>';
            return $html;
        }

        /**
         * Get upgrade card.
         *
         * @author CartPops <help@cartpops.com>
         */
        public static function get_upgrade_card() {
            include 'views/html-card-upgrade.php';
        }

    }

}