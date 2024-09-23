<?php

/**
 * Master Log Tab
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( class_exists( 'CartPops_Dashboard_Tab' ) ) {
	return new CartPops_Dashboard_Tab();
}

/**
 * CartPops_Dashboard_Tab.
 */
class CartPops_Dashboard_Tab extends CartPops_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'dashboard';
		$this->label = esc_html__( 'Dashboard', 'cartpops' );
		$this->icon  = CartPops_Settings::get_admin_asset( 'menu-item-dashboard.svg' );

		parent::__construct();
	}

	/**
	 * Get sections.
	 */
	public function get_sections() {
		$sections = array(
			'dashboard' => esc_html__( 'Dashboard', 'cartpops' ),
		);

		return apply_filters( $this->plugin_slug . '_get_sections_' . $this->id, $sections );
	}

	/**
	 * Color scheme section array.
	 */
	public function dashboard_section_array() {

		$section_fields = array();

		$section_fields[] = array(
			'type'  => 'title',
			'title' => esc_html__( 'Quick actions', 'cartpops' ),
			'icon'  => 'sliders',
			'id'    => 'cartpops_quick_actions_options',
		);

		$section_fields[] = array(
			'title'    => esc_html__( 'Enable CartPops', 'cartpops' ),
			'type'     => 'checkbox',
			'desc'     => __( 'If you disable this, the plugin will stop working on the front-end of your website. This is useful if you temporarily want to disable CartPops without deactivating the entire plugin.', 'cartpops' ),
			'desc_tip' => true,
			'id'       => $this->get_option_key( 'plugin_enable', false ),
		);

		$section_fields[] = array(
			'title' => esc_html__( 'Enable support widget', 'cartpops' ),
			'type'  => 'checkbox',
			'id'    => $this->get_option_key( 'support_chat_enable', false ),
		);

		$section_fields[] = array(
			'title' => esc_html__( 'Delete plugin settings upon deactivation?', 'cartpops' ),
			'type'  => 'checkbox',
			'desc'  => __( 'This wlll delete all plugins settings upon deactivation. Use with caution!', 'cartpops' ),
			'id'    => $this->get_option_key( 'plugin_reset', false ),
		);

		$section_fields[] = array(
			'type' => 'sectionend',
			'id'   => 'cartpops_quick_actions_options',
		);

		return $section_fields;
	}

	/**
	 * Output sidebar.
	 */
	public function output_sidebar() {

		$this->changelog();
		CartPops_Settings::get_upgrade_card();
	}

	/**
	 * Build changelog markup.
	 *
	 * @author CartPops <help@cartpops.com>
	 */
	private function changelog() {
		$readme_file = CARTPOPS_PATH . 'README.txt';

		$parsedown = new CartPops_Parsedown();

		$changelog = '';

		$data = file_get_contents( $readme_file ); // phpcs:ignore.

		if ( ! empty( $data ) ) {
			$data = explode( '== Changelog ==', $data );
			if ( ! empty( $data[1] ) ) {

				$changelog = $data[1];
				$changelog = preg_replace(
					array(
						'/\[\/\/\]\: \# fs_.+?_only_begin/',
						'/\[\/\/\]\: \# fs_.+?_only_end/',
					),
					'',
					$changelog
				);

				$changelog = $parsedown->text( $changelog );

				$changelog = preg_replace(
					array(
						'/\<strong\>(.+?)\<\/strong>\:(.+?)\n/i',
						'/\<p\>/',
						'/\<\/p\>/',
					),
					array(
						'<span class="changelog-category $1">$1</span><span class="changelog-description">$2</span>',
						'',
						'',
					),
					$changelog
				);

			}
		}

		if ( ! empty( $changelog ) ) {
			?>
			<div class="cartpops-card">
				<div class="card-content">
					<div class="card-head">
						<h2><i data-feather="edit"></i> Changelog</h2>
					</div>
					<div class="card-inside">
						<div class="cpops-changelog"><?php echo wp_kses( $changelog, wp_kses_allowed_html( 'post' ) ); ?></div>
					</div>
				</div>
			</div>
			<?php
		}
	}
}

return new CartPops_Dashboard_Tab();
