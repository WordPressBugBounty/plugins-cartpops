<?php
/* Admin HTML Settings */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>

<div id="cartpops-admin-panel">
	<form id="<?php echo esc_attr( self::$plugin_slug ); ?>_settings_form" method = "post" enctype = "multipart/form-data">
		<div class="cpops-wrap">
			<div class="menu">
				<div class="head">
					<div class="logo">
						<img src="<?php echo esc_url( self::get_admin_asset( 'logo.png' ) ); ?>" alt="CartPops logo" />
					</div>
					<div class="title">
						<?php
						$plugin_type = ( fs_cartpops()->is__premium_only() ) ? 'Premium' : 'Free';
						printf( '<span class="cpops-version">%s %s - %s</span>', esc_attr( $plugin_type ), esc_attr__( 'Version', 'cartpops' ), esc_attr( CARTPOPS_VERSION ) );
						?>
					</div>
				</div>
				<ul class="cartpops-menu-items">
					<?php
					foreach ( $tabs as $menu_id => $menu_item ) {
						if ( ! $menu_item['hide_page'] ) {
							printf(
								'<a class="%1$s" href="%2$s" data-tab="%3$s">%4$s</a>',
								'',
								esc_url( cartpops_get_settings_page_url( array( 'tab' => $menu_id ) ) ),
								$menu_id,
								sprintf(
									'<li class="%1$s"><img src="%2$s" />%3$s %4$s</li>',
									$current_tab === $menu_id ? 'active' : '',
									$menu_item['icon'],
									$menu_item['label'],
									( $menu_item['has_issues'] ) ? '<i class="cartpops-has-margin-left-sm" style="color:red" data-feather="alert-circle"></i>' : ''
								)
							);
						}
					}
					?>
					<li class="seperator"></li>
					<a rel="noopener noreferrer" target="_blank" href="<?php echo esc_attr( cartpops_outbound_url( 'docs', 'Sidebar', 'Documentation' ) ); ?>">
						<li class="">
							<img src="<?php echo esc_attr( self::get_admin_asset( 'question.svg' ) ); ?>">Visit help center
						</li>
					</a>
				</ul>
				<div class="foot">
					<?php if ( fs_cartpops()->is_not_paying() ) : ?>
						<a href="<?php echo esc_attr( cartpops_outbound_url() ); ?>" class="cpops-button upgrade-button animated-button cartpops-fullwidth">Upgrade to Pro</a>
					<?php endif; ?>
				</div>
			</div><!-- /.menu --->
			<div class="content">
				<div class="settings-hero">
					<?php foreach ( $tabs as $tab => $key ) { ?>
						<span class="<?php echo esc_html( self::$plugin_slug ); ?>_tab_heading <?php echo ( $current_tab === $tab ) ? 'cartpops-active' : 'cartpops-hide'; ?>">
							<?php echo esc_html( $key['label'] ); ?>
						</span>
					<?php } ?>
				</div><!-- /.settings-hero --->
				<div class="inner-content">
					<div class="cartpops-row">
						<div class="<?php echo esc_attr( self::$plugin_slug ); ?>_tab_content cartpops_<?php echo esc_attr( $current_tab ); ?>_tab_content_wrapper cartpops-col settings-col">
							<?php
							/* Display Sections */
							do_action( sanitize_key( self::$plugin_slug . '_sections_' . $current_tab ) );
							?>
							<div class="<?php echo esc_attr( self::$plugin_slug ); ?>_tab_inner_content cartpops_<?php echo esc_attr( $current_tab ); ?>_tab_inner_content">
								<?php
								do_action( sanitize_key( self::$plugin_slug . '_before_tab_sections' ) );

								/* Display Error or Warning Messages */
								self::show_messages();

								/* Display Tab Content */
								do_action( sanitize_key( self::$plugin_slug . '_settings_' . $current_tab ) );

								/* Display Reset and Save Button */
								do_action( sanitize_key( self::$plugin_slug . '_settings_buttons_' . $current_tab ) );

								/* Extra fields after setting button */
								do_action( sanitize_key( self::$plugin_slug . '_after_setting_buttons_' . $current_tab ) );
								?>
							</div>
						</div>
						<?php
						ob_start();
						do_action( sanitize_key( self::$plugin_slug . '_inside_sidebar_' . $current_tab ) );
						$sidebar = ob_get_contents();
						ob_end_clean();

						?>
						<?php if ( ! empty( $sidebar ) ) : ?>
							<div class="cartpops-col sidebar-col">
									<?php echo wp_kses_post( $sidebar ); ?>
							</div>
						<?php endif; ?>
					</div><!-- /.row --->
				</div><!-- /.inner-content --->
			</div><!-- /.content --->
		</div><!-- /.wrap --->
	</form>
	<?php
	do_action( sanitize_key( self::$plugin_slug . '_' . $current_tab . '_' . $current_section . '_setting_end' ) );
	do_action( sanitize_key( self::$plugin_slug . '_settings_end' ) );
	?>
</div>
<?php
