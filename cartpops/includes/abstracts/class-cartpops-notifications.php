<?php

/**
 * Abstract Notifications Class
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CartPops_Notifications' ) ) {

	/**
	 * CartPops_Notifications Class
	 */
	class CartPops_Notifications {

		/*
		* ID
		*/

		protected $id;

		/*
		* Subject
		*/
		protected $subject = '';

		/*
		* Message
		*/
		protected $message = '';

		/*
		* Template HTML
		*/
		protected $template_html;

		/*
		* Data
		*/
		protected $data = array();

		/*
		* Placeholders
		*/
		protected $placeholders = array();

		/*
		* Plugin slug
		*/
		protected $plugin_slug = 'cartpops';

		/**
		 * Class Constructor
		 */
		public function __construct() {
			$this->enabled = $this->get_enabled();

			if ( empty( $this->placeholders ) ) {
				$this->placeholders = array(
					'{site_name}' => $this->get_blogname(),
				);
			}
		}

		/*
		* Get id
		*/

		public function get_id() {
			return $this->id;
		}

		/**
		 * Get Enabled.
		 */
		public function get_enabled() {

			return 'no';
		}

		/*
		* is enabled
		*/

		public function is_enabled() {

			return 'yes' === $this->enabled;
		}

		/*
		* Default Subject
		*/

		public function get_default_subject() {

			return '';
		}

		/*
		* Default Message
		*/

		public function get_default_message() {

			return '';
		}

		/**
		 * Get subject.
		 */
		public function get_subject() {

			return $this->format_string( $this->get_default_subject() );
		}

		/**
		 * Get Message.
		 */
		public function get_message() {
			$string = $this->format_string( $this->get_default_message() );
			$string = wpautop( $string );
			$string = $this->email_inline_style( $string );

			return $string;
		}

		/**
		 * Email Inline Style
		 */
		public function email_inline_style( $content ) {
			if ( ! $this->custom_css() || ! $content ) {
				return $content;
			}

			$emogrifier_class = '\\Pelago\\Emogrifier';
			if ( ! class_exists( $emogrifier_class ) ) {
				include_once dirname( WC_PLUGIN_FILE ) . '/includes/libraries/class-emogrifier.php';
			}

			$emogrifier = new $emogrifier_class( $content, $this->custom_css() );

			return $emogrifier->emogrify();
		}

		/**
		 * Get formatted Message
		 */
		public function get_formatted_message() {

			if ( get_option( 'cartpops_settings_email_template_type', '2' ) == '2' ) {
				ob_start();
				wc_get_template( 'emails/email-header.php', array( 'email_heading' => $this->get_subject() ) );
				echo esc_textarea( $this->get_message() );
				wc_get_template( 'emails/email-footer.php' );
				$message = ob_get_clean();
			} else {
				$message = $this->get_message();
			}

			return htmlspecialchars_decode( $message );
		}

		/**
		 * Get email headers.
		 */
		public function get_headers() {
			$header = 'Content-Type: ' . $this->get_content_type() . "\r\n";

			return $header;
		}

		/**
		 * Get attachments.
		 */
		public function get_attachments() {

			return array();
		}

		/**
		 * Get content type.
		 */
		public function get_content_type() {

			return 'text/html';
		}

		/**
		 * Get WordPress blog name.
		 */
		public function get_blogname() {
			return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		}

		/**
		 * Get valid recipients.
		 */
		public function get_recipient() {
			$recipients = array_map( 'trim', explode( ',', $this->recipient ) );
			$recipients = array_filter( $recipients, 'is_email' );

			return implode( ', ', $recipients );
		}

		/**
		 * Format String
		 */
		public function format_string( $string ) {
			$find    = array_keys( $this->placeholders );
			$replace = array_values( $this->placeholders );

			$string = str_replace( $find, $replace, $string );

			return $string;
		}

		/**
		 * Custom CSS
		 */
		public function custom_css() {
			return '';
		}

		/**
		 * Send an email.
		 */
		public function send_email( $to, $subject, $message, $headers = false, $attachments = array() ) {
			if ( ! $headers ) {
				$headers = $this->get_headers();
			}

			add_filter( 'wp_mail_from', array( $this, 'get_from_address' ), 12 );
			add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ), 12 );
			add_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ), 12 );

			if ( get_option( 'cartpops_settings_email_template_type', '2' ) == '2' ) {
				$mailer = WC()->mailer();
				$return = $mailer->send( $to, $subject, $message, $headers, $attachments );
			} else {
				$return = wp_mail( $to, $subject, $message, $headers, $attachments );
			}

			remove_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
			remove_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
			remove_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );

			return $return;
		}

		/**
		 * Get the from name
		 */
		public function get_from_name() {

			$from_name = get_option( 'cartpops_settings_email_from_name' ) != '' ? get_option( 'cartpops_settings_email_from_name' ) : get_option( 'blogname' );

			return wp_specialchars_decode( esc_html( $from_name ), ENT_QUOTES );
		}

		/**
		 * Get the from address
		 */
		public function get_from_address() {

			$from_address = get_option( 'cartpops_settings_email_from_address' ) != '' ? get_option( 'cartpops_settings_email_from_address' ) : get_option( 'new_admin_email' );

			return sanitize_email( $from_address );
		}

	}

}
