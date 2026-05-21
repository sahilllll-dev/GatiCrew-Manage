<?php
/**
 * Event helper methods.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Events {
	/**
	 * Returns The Events Calendar post type without hard-failing if constants move.
	 *
	 * @return string
	 */
	public static function get_event_post_type() {
		if ( class_exists( 'Tribe__Events__Main' ) && defined( 'Tribe__Events__Main::POSTTYPE' ) ) {
			return Tribe__Events__Main::POSTTYPE;
		}

		return 'tribe_events';
	}

	/**
	 * Formats event date metadata into API-friendly values.
	 *
	 * @param int $event_id Event post ID.
	 * @return array
	 */
	public static function get_event_date( $event_id ) {
		$event_id = absint( $event_id );

		return array(
			'start'       => self::get_event_date_value( $event_id, '_EventStartDate' ),
			'end'         => self::get_event_date_value( $event_id, '_EventEndDate' ),
			'start_utc'   => self::get_event_date_value( $event_id, '_EventStartDateUTC' ),
			'end_utc'     => self::get_event_date_value( $event_id, '_EventEndDateUTC' ),
			'timezone'    => sanitize_text_field( (string) get_post_meta( $event_id, '_EventTimezone', true ) ),
			'all_day'     => 'yes' === get_post_meta( $event_id, '_EventAllDay', true ),
		);
	}

	/**
	 * Finds the published event linked to a WooCommerce product.
	 *
	 * @param int $product_id WooCommerce product or variation ID.
	 * @return WP_Post|null
	 */
	public static function get_event_by_ticket_product_id( $product_id ) {
		static $cache = array();

		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return null;
		}

		if ( array_key_exists( $product_id, $cache ) ) {
			return $cache[ $product_id ];
		}

		$events = get_posts(
			array(
				'post_type'              => self::get_event_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID,
						'value'   => $product_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$cache[ $product_id ] = ! empty( $events[0] ) && $events[0] instanceof WP_Post ? $events[0] : null;

		return $cache[ $product_id ];
	}

	/**
	 * Returns a human-readable event date string for order snapshots.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function get_event_date_label( $event_id ) {
		$event_id = absint( $event_id );

		if ( ! $event_id ) {
			return '';
		}

		if ( function_exists( 'tribe_get_start_date' ) ) {
			$date = tribe_get_start_date( $event_id, true );

			return sanitize_text_field( (string) $date );
		}

		$date = self::get_event_date_value( $event_id, '_EventStartDate' );

		if ( ! $date ) {
			return '';
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp ) {
			return sanitize_text_field( $date );
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return sanitize_text_field( wp_date( $format, $timestamp ) );
	}

	/**
	 * Returns the real The Events Calendar event title by event ID.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function get_event_title_label( $event_id ) {
		$event_id = absint( $event_id );

		if ( ! $event_id || self::get_event_post_type() !== get_post_type( $event_id ) ) {
			return '';
		}

		return sanitize_text_field( get_the_title( $event_id ) );
	}

	/**
	 * Gets structured venue data from The Events Calendar metadata.
	 *
	 * @param int $event_id Event post ID.
	 * @return array|null
	 */
	public static function get_venue( $event_id ) {
		$event_id = absint( $event_id );
		$venue_id = 0;

		if ( function_exists( 'tribe_get_venue_id' ) ) {
			$venue_id = absint( tribe_get_venue_id( $event_id ) );
		}

		if ( ! $venue_id ) {
			$venue_id = absint( get_post_meta( $event_id, '_EventVenueID', true ) );
		}

		if ( ! $venue_id ) {
			return null;
		}

		return array(
			'id'        => $venue_id,
			'name'      => self::get_venue_field( $event_id, $venue_id, 'tribe_get_venue', '' ),
			'address'   => self::get_venue_field( $event_id, $venue_id, 'tribe_get_address', '_VenueAddress' ),
			'city'      => self::get_venue_field( $event_id, $venue_id, 'tribe_get_city', '_VenueCity' ),
			'state'     => self::get_venue_field( $event_id, $venue_id, 'tribe_get_stateprovince', '_VenueStateProvince' ),
			'country'   => self::get_venue_field( $event_id, $venue_id, 'tribe_get_country', '_VenueCountry' ),
			'zip'       => self::get_venue_field( $event_id, $venue_id, 'tribe_get_zip', '_VenueZip' ),
			'phone'     => self::get_venue_field( $event_id, $venue_id, 'tribe_get_phone', '_VenuePhone' ),
			'website'   => esc_url_raw( (string) get_post_meta( $venue_id, '_VenueURL', true ) ),
			'permalink' => get_permalink( $venue_id ),
		);
	}

	/**
	 * Returns a compact venue label for WooCommerce order meta.
	 *
	 * @param int $event_id Event post ID.
	 * @return string
	 */
	public static function get_venue_label( $event_id ) {
		$venue = self::get_venue( $event_id );

		if ( empty( $venue ) || ! is_array( $venue ) ) {
			return '';
		}

		$parts = array_filter(
			array_map(
				'sanitize_text_field',
				array(
					isset( $venue['name'] ) ? $venue['name'] : '',
					isset( $venue['address'] ) ? $venue['address'] : '',
					isset( $venue['city'] ) ? $venue['city'] : '',
					isset( $venue['state'] ) ? $venue['state'] : '',
					isset( $venue['country'] ) ? $venue['country'] : '',
					isset( $venue['zip'] ) ? $venue['zip'] : '',
				)
			)
		);

		return implode( ', ', array_unique( $parts ) );
	}

	/**
	 * Reads and sanitizes an event date meta value.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $meta_key Event date meta key.
	 * @return string|null
	 */
	private static function get_event_date_value( $event_id, $meta_key ) {
		$value = get_post_meta( $event_id, $meta_key, true );

		if ( '' === $value ) {
			return null;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Uses TEC helper functions where available, then falls back to post meta.
	 *
	 * @param int    $event_id Event post ID.
	 * @param int    $venue_id Venue post ID.
	 * @param string $function TEC helper function name.
	 * @param string $meta_key Venue meta key.
	 * @return string
	 */
	private static function get_venue_field( $event_id, $venue_id, $function, $meta_key ) {
		if ( function_exists( $function ) ) {
			$value = call_user_func( $function, $event_id );
		} elseif ( '' === $meta_key ) {
			$value = get_the_title( $venue_id );
		} else {
			$value = get_post_meta( $venue_id, $meta_key, true );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
