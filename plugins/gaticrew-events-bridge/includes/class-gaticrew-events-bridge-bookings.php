<?php
/**
 * Booking helper methods.
 *
 * Booking IDs are intentionally isolated here so future QR code generation,
 * ticket validation, and attendee management can reuse the same identity layer.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Bookings {
	/**
	 * Booking ID prefix.
	 */
	const PREFIX = 'GC';

	/**
	 * Generates a unique booking ID in the format GC-{YEAR}-{RANDOM}.
	 *
	 * @return string
	 */
	public static function generate_booking_id() {
		$year = function_exists( 'current_time' ) ? current_time( 'Y' ) : gmdate( 'Y' );

		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			$booking_id = sprintf(
				'%1$s-%2$s-%3$s',
				self::PREFIX,
				$year,
				self::generate_random_segment()
			);

			if ( ! self::booking_id_exists( $booking_id ) ) {
				return $booking_id;
			}
		}

		return sprintf(
			'%1$s-%2$s-%3$s',
			self::PREFIX,
			$year,
			self::generate_random_segment() . wp_rand( 10, 99 )
		);
	}

	/**
	 * Sanitizes booking IDs before persistence or display.
	 *
	 * @param string $booking_id Raw booking ID.
	 * @return string
	 */
	public static function sanitize_booking_id( $booking_id ) {
		$booking_id = strtoupper( sanitize_text_field( (string) $booking_id ) );

		return preg_replace( '/[^A-Z0-9-]/', '', $booking_id );
	}

	/**
	 * Checks whether an order already has the generated booking ID.
	 *
	 * @param string $booking_id Booking ID.
	 * @return bool
	 */
	private static function booking_id_exists( $booking_id ) {
		global $wpdb;

		$booking_id = self::sanitize_booking_id( $booking_id );

		if ( '' === $booking_id ) {
			return false;
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_key'   => GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID,
					'meta_value' => $booking_id,
				)
			);

			if ( ! empty( $orders ) ) {
				return true;
			}
		}

		if ( class_exists( 'GatiCrew_Events_Bridge_Schema' ) ) {
			$table_name = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE booking_id = %s LIMIT 1",
					$booking_id
				)
			);
		}

		return false;
	}

	/**
	 * Generates a compact uppercase alphanumeric segment.
	 *
	 * @return string
	 */
	private static function generate_random_segment() {
		$characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$segment    = '';
		$length     = strlen( $characters ) - 1;

		for ( $index = 0; $index < 6; $index++ ) {
			$segment .= $characters[ wp_rand( 0, $length ) ];
		}

		return $segment;
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
