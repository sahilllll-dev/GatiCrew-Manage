<?php
/**
 * Frontend events REST API.
 *
 * This module owns the public /wp-json/gaticrew/v1/events payload used by
 * gaticrew.com. It stays independent from admin/order modules so the frontend
 * contract remains stable as booking internals evolve.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Events_API {
	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'gaticrew/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/events';

	/**
	 * Allowed frontend origin.
	 */
	const ALLOWED_ORIGIN = 'https://gaticrew.com';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ), 10, 4 );
		error_log( '[GatiCrew Events Bridge] API boot hook registered: GET /wp-json/' . self::NAMESPACE . self::ROUTE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- TODO: Remove after production route verification.
	}

	/**
	 * Registers GET /wp-json/gaticrew/v1/events.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_events' ),
				'permission_callback' => '__return_true',
			)
		);
		error_log( '[GatiCrew Events Bridge] REST route registered: GET /wp-json/' . self::NAMESPACE . self::ROUTE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- TODO: Remove after production route verification.
	}

	/**
	 * Adds CORS headers for the public frontend consumer.
	 *
	 * @param bool             $served Whether the request has already been served.
	 * @param WP_HTTP_Response $result REST result.
	 * @param WP_REST_Request  $request REST request.
	 * @param WP_REST_Server   $server REST server.
	 * @return bool
	 */
	public static function add_cors_headers( $served, $result, $request, $server ) {
		unset( $result, $server );

		if ( ! $request instanceof WP_REST_Request || '/' . self::NAMESPACE . self::ROUTE !== $request->get_route() ) {
			return $served;
		}

		GatiCrew_Events_Bridge_CORS::send_headers( 'GET, OPTIONS' );

		return $served;
	}

	/**
	 * Returns all published events in a frontend-friendly JSON payload.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_events() {
		$query = new WP_Query(
			array(
				'post_type'              => self::get_event_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'meta_value',
				'meta_key'               => '_EventStartDate',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$events = array();

		foreach ( $query->posts as $event ) {
			if ( $event instanceof WP_Post ) {
				$events[] = self::format_event( $event );
			}
		}

		$response = rest_ensure_response( $events );
		GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'GET, OPTIONS' );
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Formats a single event for the frontend.
	 *
	 * @param WP_Post $event Event post.
	 * @return array
	 */
	private static function format_event( WP_Post $event ) {
		$product_id   = absint( get_post_meta( $event->ID, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID, true ) );
		$product_data = self::get_product_data( $product_id );

		return array(
			'id'                => absint( $event->ID ),
			'title'             => html_entity_decode( get_the_title( $event ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'slug'              => sanitize_title( $event->post_name ),
			'excerpt'           => self::get_event_excerpt( $event ),
			'featured_image'    => self::get_featured_image_url( $event->ID ),
			'start_date'        => self::get_start_date( $event->ID ),
			'end_date'          => self::get_end_date( $event->ID ),
			'venue'             => self::get_venue( $event->ID ),
			'price'             => $product_data['price'],
			'tickets_remaining' => $product_data['tickets_remaining'],
		);
	}

	/**
	 * Returns a clean excerpt for card/list rendering.
	 *
	 * @param WP_Post $event Event post.
	 * @return string
	 */
	private static function get_event_excerpt( WP_Post $event ) {
		$excerpt = has_excerpt( $event ) ? $event->post_excerpt : wp_trim_words( wp_strip_all_tags( $event->post_content ), 32, '...' );

		return html_entity_decode( sanitize_text_field( $excerpt ), ENT_QUOTES, get_bloginfo( 'charset' ) );
	}

	/**
	 * Returns the event featured image URL.
	 *
	 * @param int $event_id Event ID.
	 * @return string|null
	 */
	private static function get_featured_image_url( $event_id ) {
		$image = get_the_post_thumbnail_url( absint( $event_id ), 'full' );

		return $image ? esc_url_raw( $image ) : null;
	}

	/**
	 * Returns the event start date using TEC helpers when available.
	 *
	 * @param int $event_id Event ID.
	 * @return string|null
	 */
	private static function get_start_date( $event_id ) {
		$event_id = absint( $event_id );

		if ( function_exists( 'tribe_get_start_date' ) ) {
			$date = tribe_get_start_date( $event_id, true, 'Y-m-d H:i:s' );

			return $date ? sanitize_text_field( $date ) : null;
		}

		return self::get_event_meta_date( $event_id, '_EventStartDate' );
	}

	/**
	 * Returns the event end date using TEC helpers when available.
	 *
	 * @param int $event_id Event ID.
	 * @return string|null
	 */
	private static function get_end_date( $event_id ) {
		$event_id = absint( $event_id );

		if ( function_exists( 'tribe_get_end_date' ) ) {
			$date = tribe_get_end_date( $event_id, true, 'Y-m-d H:i:s' );

			return $date ? sanitize_text_field( $date ) : null;
		}

		return self::get_event_meta_date( $event_id, '_EventEndDate' );
	}

	/**
	 * Reads a TEC event date meta field.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $meta_key Date meta key.
	 * @return string|null
	 */
	private static function get_event_meta_date( $event_id, $meta_key ) {
		$value = get_post_meta( absint( $event_id ), sanitize_text_field( $meta_key ), true );

		if ( '' === $value ) {
			return null;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Returns a compact venue string with safe fallbacks.
	 *
	 * @param int $event_id Event ID.
	 * @return string|null
	 */
	private static function get_venue( $event_id ) {
		$event_id = absint( $event_id );

		if ( function_exists( 'tribe_get_venue' ) ) {
			$venue = tribe_get_venue( $event_id );

			if ( '' !== (string) $venue ) {
				return sanitize_text_field( $venue );
			}
		}

		if ( class_exists( 'GatiCrew_Events_Bridge_Events' ) ) {
			$venue = GatiCrew_Events_Bridge_Events::get_venue_label( $event_id );

			if ( '' !== $venue ) {
				return sanitize_text_field( $venue );
			}
		}

		$venue_id = function_exists( 'tribe_get_venue_id' ) ? absint( tribe_get_venue_id( $event_id ) ) : absint( get_post_meta( $event_id, '_EventVenueID', true ) );

		return $venue_id ? sanitize_text_field( get_the_title( $venue_id ) ) : null;
	}

	/**
	 * Returns linked WooCommerce ticket price and availability.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private static function get_product_data( $product_id ) {
		$product_id = absint( $product_id );
		$data       = array(
			'price'             => null,
			'tickets_remaining' => null,
		);

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return $data;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'trash' === get_post_status( $product_id ) ) {
			return $data;
		}

		$stock_quantity = $product->get_stock_quantity();

		$data['price']             = wc_format_decimal( $product->get_price(), wc_get_price_decimals() );
		$data['tickets_remaining'] = null === $stock_quantity ? null : max( 0, (int) $stock_quantity );

		return $data;
	}

	/**
	 * Returns TEC event post type with a fallback.
	 *
	 * @return string
	 */
	private static function get_event_post_type() {
		if ( class_exists( 'GatiCrew_Events_Bridge_Events' ) ) {
			return GatiCrew_Events_Bridge_Events::get_event_post_type();
		}

		return 'tribe_events';
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}

GatiCrew_Events_Bridge_Events_API::init();
