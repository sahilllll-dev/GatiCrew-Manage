<?php
/**
 * Frontend booking details REST API.
 *
 * This endpoint returns the post-payment booking payload consumed by the
 * gaticrew.com booking confirmation page. It reads from the attendee table
 * first because attendees are the source of truth for QR/check-in state.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Booking_Details_API {
	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'gaticrew/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/booking/(?P<booking_id>[a-zA-Z0-9-]+)';

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
		error_log( '[GatiCrew Events Bridge] API boot hook registered: GET /wp-json/' . self::NAMESPACE . '/booking/{booking_id}' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- TODO: Remove after production route verification.
	}

	/**
	 * Registers GET /wp-json/gaticrew/v1/booking/{booking_id}.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'booking_id' => array(
						'description'       => __( 'GatiCrew booking ID.', 'gaticrew-events-bridge' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_booking_id_arg' ),
					),
				),
			)
		);
		error_log( '[GatiCrew Events Bridge] REST route registered: GET /wp-json/' . self::NAMESPACE . '/booking/{booking_id}' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- TODO: Remove after production route verification.
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

		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE . '/booking/' ) ) {
			return $served;
		}

		GatiCrew_Events_Bridge_CORS::send_headers( 'GET, OPTIONS' );

		return $served;
	}

	/**
	 * Sanitizes booking IDs for REST args.
	 *
	 * @param mixed $value Raw booking ID.
	 * @return string
	 */
	public static function sanitize_booking_id_arg( $value ) {
		self::load_runtime_dependencies();

		return GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $value );
	}

	/**
	 * Validates booking ID shape before lookup.
	 *
	 * @param mixed $value Sanitized booking ID.
	 * @return bool
	 */
	public static function validate_booking_id_arg( $value ) {
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $value );

		return (bool) preg_match( '/^GC-[0-9]{4}-[A-Z0-9]{4,32}$/', $booking_id );
	}

	/**
	 * Returns complete booking details for frontend confirmation rendering.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_booking( WP_REST_Request $request ) {
		self::load_runtime_dependencies();

		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::error_response( 'woocommerce_unavailable', __( 'WooCommerce is not available.', 'gaticrew-events-bridge' ), array(), 503 );
		}

		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $request->get_param( 'booking_id' ) );

		if ( ! self::validate_booking_id_arg( $booking_id ) ) {
			return self::error_response( 'invalid_booking_id', __( 'Invalid booking ID.', 'gaticrew-events-bridge' ), array( 'booking_id' => __( 'Booking ID format is invalid.', 'gaticrew-events-bridge' ) ), 400 );
		}

		$attendees = self::get_attendees_by_booking_id( $booking_id );

		if ( empty( $attendees ) ) {
			return self::error_response( 'booking_not_found', __( 'Booking could not be found.', 'gaticrew-events-bridge' ), array(), 404 );
		}

		$order_id = isset( $attendees[0]['order_id'] ) ? absint( $attendees[0]['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return self::error_response( 'missing_order', __( 'Booking order could not be found.', 'gaticrew-events-bridge' ), array( 'order_id' => $order_id ), 404 );
		}

		$event_id = self::get_event_id( $attendees, $order );
		$response = rest_ensure_response(
			array(
				'success'              => true,
				'booking_id'           => $booking_id,
				'order_id'             => absint( $order->get_id() ),
				'order_status'         => sanitize_key( $order->get_status() ),
				'payment_status'       => self::get_payment_status( $order ),
				'booking_status'       => self::get_booking_status( $attendees ),
				'booking_created_date' => self::get_booking_created_date( $attendees, $order ),
				'event'                => self::get_event_details( $event_id ),
				'customer'             => self::get_customer_details( $order ),
				'attendees'            => self::format_attendees( $attendees ),
				'ticket_pdf_url'       => self::get_ticket_pdf_url( $attendees, $order ),
				'amount'               => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'currency'             => sanitize_text_field( $order->get_currency() ),
			)
		);

		GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'GET, OPTIONS' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Finds attendee rows by booking ID.
	 *
	 * @param string $booking_id Booking ID.
	 * @return array
	 */
	private static function get_attendees_by_booking_id( $booking_id ) {
		if ( ! class_exists( 'GatiCrew_Events_Bridge_Attendees_Repository' ) ) {
			return array();
		}

		$repository = new GatiCrew_Events_Bridge_Attendees_Repository();

		return $repository->get_group_by_qr_token( $booking_id );
	}

	/**
	 * Resolves the event ID from attendee rows or order meta.
	 *
	 * @param array    $attendees Attendee rows.
	 * @param WC_Order $order WooCommerce order.
	 * @return int
	 */
	private static function get_event_id( array $attendees, WC_Order $order ) {
		foreach ( $attendees as $attendee ) {
			$event_id = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;

			if ( $event_id ) {
				return $event_id;
			}
		}

		return absint( $order->get_meta( self::get_order_meta_key( 'ORDER_META_EVENT_ID', '_gaticrew_event_id' ), true ) );
	}

	/**
	 * Returns event details optimized for frontend rendering.
	 *
	 * @param int $event_id Event post ID.
	 * @return array|null
	 */
	private static function get_event_details( $event_id ) {
		$event_id = absint( $event_id );

		if ( ! $event_id || GatiCrew_Events_Bridge_Events::get_event_post_type() !== get_post_type( $event_id ) ) {
			return null;
		}

		return array(
			'id'             => $event_id,
			'title'          => html_entity_decode( sanitize_text_field( get_the_title( $event_id ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'slug'           => sanitize_title( get_post_field( 'post_name', $event_id ) ),
			'start_date'     => self::get_event_start_date( $event_id ),
			'end_date'       => self::get_event_end_date( $event_id ),
			'venue'          => self::get_event_venue( $event_id ),
			'featured_image' => self::get_featured_image_url( $event_id ),
		);
	}

	/**
	 * Returns event start date with The Events Calendar helper fallback.
	 *
	 * @param int $event_id Event post ID.
	 * @return string|null
	 */
	private static function get_event_start_date( $event_id ) {
		$event_id = absint( $event_id );

		if ( function_exists( 'tribe_get_start_date' ) ) {
			$date = tribe_get_start_date( $event_id, true, 'Y-m-d H:i:s' );

			return $date ? sanitize_text_field( $date ) : null;
		}

		return self::get_event_date_meta( $event_id, '_EventStartDate' );
	}

	/**
	 * Returns event end date with The Events Calendar helper fallback.
	 *
	 * @param int $event_id Event post ID.
	 * @return string|null
	 */
	private static function get_event_end_date( $event_id ) {
		$event_id = absint( $event_id );

		if ( function_exists( 'tribe_get_end_date' ) ) {
			$date = tribe_get_end_date( $event_id, true, 'Y-m-d H:i:s' );

			return $date ? sanitize_text_field( $date ) : null;
		}

		return self::get_event_date_meta( $event_id, '_EventEndDate' );
	}

	/**
	 * Reads a TEC event date meta value.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $meta_key Meta key.
	 * @return string|null
	 */
	private static function get_event_date_meta( $event_id, $meta_key ) {
		$value = get_post_meta( absint( $event_id ), sanitize_text_field( $meta_key ), true );

		return '' !== $value ? sanitize_text_field( (string) $value ) : null;
	}

	/**
	 * Returns compact venue text.
	 *
	 * @param int $event_id Event post ID.
	 * @return string|null
	 */
	private static function get_event_venue( $event_id ) {
		$venue = GatiCrew_Events_Bridge_Events::get_venue_label( absint( $event_id ) );

		if ( '' !== $venue ) {
			return sanitize_text_field( $venue );
		}

		if ( function_exists( 'tribe_get_venue' ) ) {
			$venue = tribe_get_venue( absint( $event_id ) );

			if ( '' !== (string) $venue ) {
				return sanitize_text_field( $venue );
			}
		}

		return null;
	}

	/**
	 * Returns event featured image URL.
	 *
	 * @param int $event_id Event post ID.
	 * @return string|null
	 */
	private static function get_featured_image_url( $event_id ) {
		$image = get_the_post_thumbnail_url( absint( $event_id ), 'full' );

		return $image ? esc_url_raw( $image ) : null;
	}

	/**
	 * Returns customer details from order billing and plugin meta fallbacks.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function get_customer_details( WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );

		if ( '' === $name ) {
			$name = sanitize_text_field( (string) $order->get_meta( self::get_order_meta_key( 'ORDER_META_CUSTOMER_NAME', '_gaticrew_customer_name' ), true ) );
		}

		$email = $order->get_billing_email();

		if ( '' === $email ) {
			$email = $order->get_meta( self::get_order_meta_key( 'ORDER_META_CUSTOMER_EMAIL', '_gaticrew_customer_email' ), true );
		}

		$phone = $order->get_billing_phone();

		if ( '' === $phone ) {
			$phone = $order->get_meta( self::get_order_meta_key( 'ORDER_META_CUSTOMER_PHONE', '_gaticrew_customer_phone' ), true );
		}

		return array(
			'name'  => sanitize_text_field( $name ),
			'email' => sanitize_email( $email ),
			'phone' => function_exists( 'wc_sanitize_phone_number' ) ? wc_sanitize_phone_number( $phone ) : sanitize_text_field( $phone ),
		);
	}

	/**
	 * Formats attendee rows for the frontend.
	 *
	 * @param array $attendees Attendee rows.
	 * @return array
	 */
	private static function format_attendees( array $attendees ) {
		$formatted = array();

		foreach ( $attendees as $attendee ) {
			$attendee_id = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;
			$name        = isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '';

			if ( '' === $name && ! empty( $attendee['attendee_names'] ) && is_array( $attendee['attendee_names'] ) ) {
				$name = sanitize_text_field( reset( $attendee['attendee_names'] ) );
			}

			$formatted[] = array(
				'attendee_id'    => $attendee_id,
				'attendee_name'  => $name,
				'attendee_email' => isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '',
				'checkin_status' => self::get_attendee_checkin_status( $attendee ),
				'qr_url'         => self::get_attendee_qr_url( $attendee ),
				'validation_url' => self::get_attendee_validation_url( $attendee ),
			);
		}

		return $formatted;
	}

	/**
	 * Returns attendee check-in status.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private static function get_attendee_checkin_status( array $attendee ) {
		if ( ! empty( $attendee['checked_in'] ) ) {
			return 'checked_in';
		}

		$status = isset( $attendee['booking_status'] ) ? sanitize_key( $attendee['booking_status'] ) : '';

		return '' !== $status ? $status : 'confirmed';
	}

	/**
	 * Returns attendee QR image URL.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private static function get_attendee_qr_url( array $attendee ) {
		$qr_code = isset( $attendee['qr_code'] ) ? esc_url_raw( $attendee['qr_code'] ) : '';

		if ( '' !== $qr_code ) {
			return $qr_code;
		}

		$token = ! empty( $attendee['id'] ) ? (string) absint( $attendee['id'] ) : '';

		if ( '' === $token && isset( $attendee['qr_token'] ) ) {
			$token = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] );
		}

		return '' !== $token ? esc_url_raw( GatiCrew_Events_Bridge_QR_Tokens::get_qr_image_url( $token ) ) : '';
	}

	/**
	 * Returns attendee QR validation URL.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private static function get_attendee_validation_url( array $attendee ) {
		$token = ! empty( $attendee['id'] ) ? (string) absint( $attendee['id'] ) : '';

		if ( '' === $token && isset( $attendee['qr_token'] ) ) {
			$token = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] );
		}

		return '' !== $token ? esc_url_raw( GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $token ) ) : '';
	}

	/**
	 * Returns a signed PDF download URL when possible.
	 *
	 * TODO: Replace this single booking-level URL with advanced PDF variants
	 * once sponsor/VIP ticket themes are introduced.
	 *
	 * @param array    $attendees Attendee rows.
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private static function get_ticket_pdf_url( array $attendees, WC_Order $order ) {
		$primary = ! empty( $attendees[0] ) && is_array( $attendees[0] ) ? $attendees[0] : array();

		if ( empty( $primary ) ) {
			return '';
		}

		if ( class_exists( 'GatiCrew_Events_Bridge_PDF_Tickets' ) ) {
			$url = GatiCrew_Events_Bridge_PDF_Tickets::get_public_download_url( $primary, $order );

			if ( '' !== $url ) {
				return esc_url_raw( $url );
			}
		}

		return ! empty( $primary['ticket_pdf'] ) ? esc_url_raw( $primary['ticket_pdf'] ) : '';
	}

	/**
	 * Returns payment status with order-status fallback.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private static function get_payment_status( WC_Order $order ) {
		$status = sanitize_key( (string) $order->get_meta( '_gaticrew_payment_status', true ) );

		if ( '' !== $status ) {
			return $status;
		}

		return $order->is_paid() ? 'paid' : 'pending';
	}

	/**
	 * Returns booking status from attendee rows.
	 *
	 * @param array $attendees Attendee rows.
	 * @return string
	 */
	private static function get_booking_status( array $attendees ) {
		$status = isset( $attendees[0]['booking_status'] ) ? sanitize_key( $attendees[0]['booking_status'] ) : '';

		return '' !== $status ? $status : 'confirmed';
	}

	/**
	 * Returns booking created date with order fallback.
	 *
	 * @param array    $attendees Attendee rows.
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	private static function get_booking_created_date( array $attendees, WC_Order $order ) {
		if ( ! empty( $attendees[0]['created_at'] ) ) {
			return sanitize_text_field( $attendees[0]['created_at'] );
		}

		$date = $order->get_date_created();

		return $date ? sanitize_text_field( $date->date( 'Y-m-d H:i:s' ) ) : null;
	}

	/**
	 * Returns an order meta key with fallback for early load contexts.
	 *
	 * @param string $constant Class constant name.
	 * @param string $fallback Fallback meta key.
	 * @return string
	 */
	private static function get_order_meta_key( $constant, $fallback ) {
		return class_exists( 'GatiCrew_Events_Bridge' ) && defined( 'GatiCrew_Events_Bridge::' . $constant )
			? constant( 'GatiCrew_Events_Bridge::' . $constant )
			: $fallback;
	}

	/**
	 * Loads classes needed when REST runs before the main orchestrator finishes.
	 *
	 * @return void
	 */
	private static function load_runtime_dependencies() {
		$files = array(
			'database/class-gaticrew-events-bridge-schema.php',
			'roles/class-gaticrew-events-bridge-role-manager.php',
			'qr/class-gaticrew-events-bridge-qr-tokens.php',
			'qr/class-gaticrew-events-bridge-qr-code.php',
			'attendees/class-gaticrew-events-bridge-attendees-repository.php',
			'pdf/class-gaticrew-events-bridge-pdf-tickets.php',
		);

		foreach ( $files as $file ) {
			$path = GATICREW_EVENTS_BRIDGE_PATH . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Builds a consistent JSON error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param array  $errors Field-level errors.
	 * @param int    $status HTTP status.
	 * @return WP_REST_Response
	 */
	private static function error_response( $code, $message, array $errors = array(), $status = 400 ) {
		$response = new WP_REST_Response(
			array(
				'success' => false,
				'code'    => sanitize_key( $code ),
				'message' => sanitize_text_field( $message ),
				'errors'  => $errors,
			),
			absint( $status )
		);

		GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'GET, OPTIONS' );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}

GatiCrew_Events_Bridge_Booking_Details_API::init();
