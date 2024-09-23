<?php

/**
 * Post.
 * */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CartPops_Post' ) ) {

	/**
	 * CartPops_Post Class.
	 */
	abstract class CartPops_Post {


		/**
		 * ID
		 *
		 * @var string/int
		 * */
		protected $id = '';

		/**
		 * Post.
		 *
		 * @var object
		 * */
		protected $post;

		/**
		 * Post type.
		 *
		 * @var string
		 * */
		protected $post_type = '';

		/**
		 * Post status.
		 *
		 * @var string
		 * */
		protected $post_status = '';

		/**
		 * Default Meta data.
		 *
		 * @var array
		 * */
		protected $default_meta_data = array();

		/**
		 * Current Meta data.
		 *
		 * @var array
		 * */
		protected $current_meta_data = array();

		/**
		 * Meta data keys.
		 *
		 * @var array.
		 * */
		protected $meta_data_keys = array();

		/**
		 * Status.
		 *
		 * @var string
		 * */
		protected $status;

		/**
		 * Class initialization.
		 * */
		public function __construct( $_id = '', $populate = true ) {
			$this->id = $_id;

			if ( $populate && $_id ) {
				$this->populate_data();
			}
		}

		/**
		 * Has a status?
		 *
		 * @return bool
		 * */
		public function has_status( $status ) {
			$current_status = $this->get_status();

			if ( is_array( $status ) && in_array( $current_status, $status ) ) {
				return true;
			}

			if ( $current_status == $status ) {
				return true;
			}

			return false;
		}

		/**
		 * Update a status.
		 *
		 * @return bool/WP_Error
		 * */
		public function update_status( $status ) {
			$post_args = array(
				'ID'          => $this->id,
				'post_type'   => $this->post_type,
				'post_status' => $status,
			);

			return wp_update_post( $post_args );
		}

		/**
		 * Get a id.
		 *
		 * @return string/int
		 * */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Get a status.
		 *
		 * @return string
		 * */
		public function get_status() {
			return $this->status;
		}

		/**
		 * Check the current post is valid?.
		 *
		 * @return bool
		 * */
		public function exists() {
			return isset( $this->post->post_type ) && $this->post->post_type == $this->post_type;
		}

		/**
		 * Populate the data for current post.
		 *
		 * @return void
		 * */
		protected function populate_data() {
			if ( 'auto-draft' == $this->get_status() ) {
				return;
			}

			$this->load_postdata();
			$this->load_metadata();
		}

		/**
		 * Prepare a post data.
		 *
		 * @return void
		 * */
		protected function load_postdata() {
			$this->post = get_post( $this->id );
			if ( ! $this->post ) {
				return;
			}

			$this->status = $this->post->post_status;

			$this->load_extra_postdata();
		}

		/**
		 * Prepare a extra post data.
		 *
		 * @return void
		 * */
		protected function load_extra_postdata() {

		}

		/**
		 * Prepare a post meta data.
		 *
		 * @return void
		 * */
		protected function load_metadata() {

			$meta_data_array = get_post_meta( $this->id );
			if ( ! cartpops_check_is_array( $meta_data_array ) ) {
				return;
			}

			foreach ( $this->meta_data_keys as $key => $value ) {

				if ( ! isset( $meta_data_array[ $key ][0] ) ) {
					continue;
				}

				$this->default_meta_data [ $key ] = ( is_serialized( $meta_data_array[ $key ][0] ) ) ? @unserialize( $meta_data_array[ $key ][0] ) : $meta_data_array[ $key ][0];
			}
		}

		/**
		 * Get a property.
		 *
		 * @return mixed
		 * */
		protected function get_prop( $key ) {
			if ( isset( $this->current_meta_data[ $key ] ) ) {
				// Current object meta value.
				return $this->current_meta_data[ $key ];
			} elseif ( isset( $this->default_meta_data[ $key ] ) ) {
				// Database meta value.
				return $this->default_meta_data[ $key ];
			} elseif ( isset( $this->meta_data_keys[ $key ] ) ) {
				// Default meta value.
				return $this->meta_data_keys[ $key ];
			}

			return '';
		}

		/**
		 * Set a property.
		 *
		 * @return mixed
		 * */
		protected function set_prop( $key, $value ) {
			if ( isset( $this->meta_data_keys[ $key ] ) ) {
				// Set current object meta value.
				$this->current_meta_data[ $key ] = $value;
			}
		}

		/**
		 * Create a post.
		 *
		 * @return int/string
		 * */
		public function create( $meta_data, $post_args = array() ) {

			$default_post_args = array(
				'post_type'   => $this->post_type,
				'post_status' => $this->post_status,
			);

			$post_args = wp_parse_args( $post_args, $default_post_args );

			$this->id = wp_insert_post( $post_args );

			$this->update_metas( $meta_data );

			$this->populate_data();

			return $this->id;
		}

		/**
		 * Update a post.
		 *
		 * @return int/string
		 * */
		public function update( $meta_data, $post_args = array() ) {
			if ( ! $this->id ) {
				return false;
			}

			$default_post_args = array(
				'ID'          => $this->id,
				'post_type'   => $this->post_type,
				'post_status' => $this->get_status(),
			);

			$post_args = wp_parse_args( $post_args, $default_post_args );

			wp_update_post( $post_args );

			$this->update_metas( $meta_data );

			$this->populate_data();

			return $this->id;
		}

		/**
		 * Update the post metas.
		 *
		 * @return void
		 * */
		public function update_metas( $meta_data ) {
			if ( ! $this->id ) {
				return;
			}

			foreach ( $this->meta_data_keys as $meta_key => $default ) {
				if ( ! isset( $meta_data[ $meta_key ] ) ) {
					continue;
				}

				update_post_meta( $this->id, sanitize_key( $meta_key ), $meta_data[ $meta_key ] );
			}
		}

		/**
		 * Update a post meta.
		 *
		 * @return void
		 * */
		public function update_meta( $meta_key, $value ) {
			if ( ! $this->id ) {
				return false;
			}

			update_post_meta( $this->id, sanitize_key( $meta_key ), $value );
		}

	}

}
