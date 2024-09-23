<?php

/**
 * Date Time.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'CartPops_Date_Time' ) ) {

	/**
	 * Class.
	 */
	class CartPops_Date_Time {


		/**
		 * WordPress Timezone.
		 *
		 * @var string
		 */
		private static $wp_timezone;

		/**
		 * WordPress Date Format.
		 *
		 * @var string
		 */
		private static $wp_date_format;

		/**
		 * WordPress Time Format.
		 *
		 * @var string
		 */
		private static $wp_time_format;

		/**
		 *  Get WordPress TimeZone.
		 */
		public static function get_wp_timezone() {
			if ( static::$wp_timezone ) {
				return static::$wp_timezone;
			}

			static::$wp_timezone = get_option( 'timezone_string' );

			if ( ! static::$wp_timezone ) {
				static::$wp_timezone = 'UTC' . get_option( 'gmt_offset' );
			}

			return static::$wp_timezone;
		}

		/**
		 *  Get WordPress Date Format.
		 */
		public static function get_wp_date_format() {
			if ( static::$wp_date_format ) {
				return static::$wp_date_format;
			}

			static::$wp_date_format = get_option( 'date_format' );

			return static::$wp_date_format;
		}

		/**
		 *  Get WordPress Time Format.
		 */
		public static function get_wp_time_format() {
			if ( static::$wp_time_format ) {
				return static::$wp_time_format;
			}

			static::$wp_time_format = get_option( 'time_format' );

			return static::$wp_time_format;
		}

		/**
		 *  Create Date/Time Object TimeZone.
		 */
		public static function get_tz_date_time_object( $date, $tz = false, $utc = false ) {

			if ( ! $tz ) {
				$tz = self::get_wp_timezone();
			}

			$tz = self::maybe_get_tz_name( $tz );

			$date_object = date_create( $date, timezone_open( $tz ) );

			if ( $utc ) {
				$date_object->setTimezone( timezone_open( 'UTC' ) );
			}

			return $date_object;
		}

		/**
		 *  Create Date/Time Mysql format.
		 */
		public static function get_mysql_date_time_format( $date, $tz = false, $utc = false ) {

			$date_object = self::get_tz_date_time_object( $date, $tz, $utc );

			return $date_object->format( 'Y-m-d H:i:s' );
		}

		/**
		 *  Create Date/Time Object.
		 */
		public static function get_date_time_object( $date, $wp_zone = true ) {
			$date_object = date_create( $date );

			if ( $wp_zone ) {

				$tz = ( true === $wp_zone ) ? self::get_wp_timezone() : $wp_zone;

				$tz = self::maybe_get_tz_name( $tz );
				$date_object->setTimezone( timezone_open( $tz ) );
			}

			return $date_object;
		}

		/**
		 * Format date time based on WordPress.
		 */
		public static function get_date_object_format_datetime( $date, $format = false, $wp_zone = true, $separator = ' ', $display_tz = false ) {
			$tz_format   = '';
			$date_object = self::get_date_time_object( $date, $wp_zone );

			if ( $display_tz ) {
				$tz_format = ' (UTC ' . $date_object->format( 'P' ) . ')';
			}

			switch ( $format ) {
				case 'date':
					return $date_object->format( get_option( 'date_format' ) );
					break;
				case 'time':
					return $date_object->format( get_option( 'time_format' ) ) . $tz_format;
					break;
				default:
					$format = ( $format ) ? $format : get_option( 'date_format' ) . $separator . get_option( 'time_format' );

					return $date_object->format( $format ) . $tz_format;
					break;
			}
		}

		/**
		 *  Get Time zone name.
		 */
		public static function maybe_get_tz_name( $timezone ) {
			if ( ! self::is_utc_offset( $timezone ) && ! is_numeric( $timezone ) ) {
				return $timezone;
			}

			$offset = $timezone;
			if ( ! is_numeric( $timezone ) ) {
				$offset = str_replace( 'utc', '', trim( strtolower( $timezone ) ) );
			}

			// try to get timezone from gmt_offset, respecting daylight savings.
			$timezone = timezone_name_from_abbr( null, $offset * 3600, true );

			// if that didn't work, maybe they don't have daylight savings.
			if ( false === $timezone ) {
				$timezone = timezone_name_from_abbr( null, $offset * 3600, false );
			}

			// and if THAT didn't work, round the gmt_offset down and then try to get the timezone respecting daylight savings.
			if ( false === $timezone ) {
				$timezone = timezone_name_from_abbr( null, (int) $offset * 3600, true );
			}

			// lastly if that didn't work, round the gmt_offset down and maybe that TZ doesn't do daylight savings.
			if ( false === $timezone ) {
				$timezone = timezone_name_from_abbr( null, (int) $offset * 3600, false );
			}

			return $timezone;
		}

		/**
		 * Check is UTC offset time zone.
		 */
		public static function is_utc_offset( $timezone ) {
			$timezone = trim( $timezone );

			return ( 0 === strpos( $timezone, 'UTC' ) && strlen( $timezone ) > 3 );
		}

	}

}
