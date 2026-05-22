<?php
/**
 * Frontend booking details REST API.
 *
 * This endpoint returns the post-payment booking payload consumed by the
 * gaticrew.com booking confirmation page. The local GatiCrew attendee table
 * identifies the booking, while Event Tickets attendee posts remain the source
 * of truth for QR security codes and QR check-in URLs.
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

		$event_id                = self::get_event_id( $attendees, $order );
		$event_details           = self::get_event_details( $event_id );
		$event_tickets_attendees = self::get_event_tickets_attendees( $order, $event_id, $attendees );
		$response = rest_ensure_response(
			array(
				'success'              => true,
				'booking_id'           => $booking_id,
				'order_id'             => absint( $order->get_id() ),
				'order_status'         => sanitize_key( $order->get_status() ),
				'payment_status'       => self::get_payment_status( $order ),
				'booking_status'       => self::get_booking_status( $attendees ),
				'booking_created_date' => self::get_booking_created_date( $attendees, $order ),
				'event_name'           => ! empty( $event_details['title'] ) ? $event_details['title'] : '',
				'event'                => $event_details,
				'customer'             => self::get_customer_details( $order ),
				'attendees'            => self::format_attendees( $attendees, $event_tickets_attendees ),
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
	 * @param array $event_tickets_attendees Real Event Tickets attendee data.
	 * @return array
	 */
	private static function format_attendees( array $attendees, array $event_tickets_attendees = array() ) {
		$formatted = array();
		$used      = array();

		foreach ( $attendees as $index => $attendee ) {
			$gaticrew_attendee_id = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;
			$name                 = isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '';

			if ( '' === $name && ! empty( $attendee['attendee_names'] ) && is_array( $attendee['attendee_names'] ) ) {
				$name = sanitize_text_field( reset( $attendee['attendee_names'] ) );
			}

			$real_attendee = self::match_event_tickets_attendee( $attendee, $event_tickets_attendees, $used, $index );
			$real_id       = ! empty( $real_attendee['attendee_id'] ) ? absint( $real_attendee['attendee_id'] ) : 0;
			$real_qr_url   = ! empty( $real_attendee['attendee_qr_url'] ) ? esc_url_raw( $real_attendee['attendee_qr_url'] ) : '';
			$real_qr_image = ! empty( $real_attendee['attendee_qr_image_url'] ) ? esc_url_raw( $real_attendee['attendee_qr_image_url'] ) : '';

			$formatted[] = array(
				'attendee_id'               => $real_id,
				'gaticrew_attendee_id'      => $gaticrew_attendee_id,
				'attendee_name'             => ! empty( $real_attendee['attendee_name'] ) ? $real_attendee['attendee_name'] : $name,
				'attendee_email'            => ! empty( $real_attendee['attendee_email'] ) ? $real_attendee['attendee_email'] : ( isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '' ),
				'attendee_ticket_id'        => ! empty( $real_attendee['attendee_ticket_id'] ) ? $real_attendee['attendee_ticket_id'] : '',
				'attendee_ticket_unique_id' => ! empty( $real_attendee['attendee_ticket_unique_id'] ) ? $real_attendee['attendee_ticket_unique_id'] : '',
				'attendee_qr_token'         => ! empty( $real_attendee['attendee_qr_token'] ) ? $real_attendee['attendee_qr_token'] : '',
				'attendee_qr_url'           => $real_qr_url,
				'attendee_qr_image_url'     => $real_qr_image,
				'checkin_status'            => ! empty( $real_attendee['checkin_status'] ) ? $real_attendee['checkin_status'] : self::get_attendee_checkin_status( $attendee ),
				'qr_url'                    => $real_qr_image,
				'validation_url'            => $real_qr_url,
			);
		}

		return $formatted;
	}

	/**
	 * Gets real Event Tickets attendees connected to this booking.
	 *
	 * The security code stored on Event Tickets attendee posts is the QR token
	 * that TEC validates during QR check-in. This method only reads stored TEC
	 * attendee data and does not generate replacement tokens.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param int      $event_id Event post ID.
	 * @param array    $booking_attendees GatiCrew attendee rows.
	 * @return array
	 */
	private static function get_event_tickets_attendees( WC_Order $order, $event_id, array $booking_attendees ) {
		$ids = self::get_event_tickets_attendee_ids_by_order( absint( $order->get_id() ), absint( $event_id ) );

		if ( empty( $ids ) && $event_id ) {
			$ids = self::get_event_tickets_attendee_ids_by_event_and_identity( absint( $event_id ), $booking_attendees, $order );
		}

		return self::normalize_event_tickets_attendees( $ids );
	}

	/**
	 * Finds Event Tickets attendee post IDs by order relation meta.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $event_id Event post ID.
	 * @return array
	 */
	private static function get_event_tickets_attendee_ids_by_order( $order_id, $event_id ) {
		global $wpdb;

		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			return array();
		}

		$post_types = self::get_event_tickets_attendee_post_types();
		$order_keys = self::get_event_tickets_meta_keys( 'order' );
		$event_keys = self::get_event_tickets_meta_keys( 'event' );
		$params     = array_merge( $order_keys, array( (string) $order_id ) );
		$sql        = "
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} order_pm
				ON order_pm.post_id = p.ID
				AND order_pm.meta_key IN (" . self::get_placeholders( $order_keys ) . ")
				AND order_pm.meta_value = %s
		";

		if ( $event_id ) {
			$sql     .= "
				INNER JOIN {$wpdb->postmeta} event_pm
					ON event_pm.post_id = p.ID
					AND event_pm.meta_key IN (" . self::get_placeholders( $event_keys ) . ")
					AND event_pm.meta_value = %s
			";
			$params = array_merge( $params, $event_keys, array( (string) absint( $event_id ) ) );
		}

		$sql .= "
			WHERE p.post_type IN (" . self::get_placeholders( $post_types ) . ")
				AND p.post_status <> 'trash'
			ORDER BY p.ID ASC
		";
		$params = array_merge( $params, $post_types );

		return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Finds Event Tickets attendee IDs by event and booking attendee identity.
	 *
	 * @param int      $event_id Event post ID.
	 * @param array    $booking_attendees GatiCrew attendee rows.
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function get_event_tickets_attendee_ids_by_event_and_identity( $event_id, array $booking_attendees, WC_Order $order ) {
		global $wpdb;

		$post_types = self::get_event_tickets_attendee_post_types();
		$event_keys = self::get_event_tickets_meta_keys( 'event' );
		$params     = array_merge( $event_keys, array( (string) absint( $event_id ) ) );
		$sql        = "
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} event_pm
				ON event_pm.post_id = p.ID
				AND event_pm.meta_key IN (" . self::get_placeholders( $event_keys ) . ")
				AND event_pm.meta_value = %s
			WHERE p.post_type IN (" . self::get_placeholders( $post_types ) . ")
				AND p.post_status <> 'trash'
			ORDER BY p.ID ASC
		";
		$params     = array_merge( $params, $post_types );
		$candidates = self::normalize_event_tickets_attendees( array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$matched    = array();
		$used       = array();

		foreach ( $booking_attendees as $index => $booking_attendee ) {
			$real_attendee = self::match_event_tickets_attendee( $booking_attendee, $candidates, $used, $index );

			if ( ! empty( $real_attendee['attendee_id'] ) ) {
				$matched[] = absint( $real_attendee['attendee_id'] );
			}
		}

		return $matched;
	}

	/**
	 * Normalizes Event Tickets attendee posts to API fields.
	 *
	 * @param array $attendee_ids Event Tickets attendee post IDs.
	 * @return array
	 */
	private static function normalize_event_tickets_attendees( array $attendee_ids ) {
		$normalized = array();

		foreach ( array_unique( array_map( 'absint', $attendee_ids ) ) as $attendee_id ) {
			$attendee = get_post( $attendee_id );

			if ( ! $attendee instanceof WP_Post ) {
				continue;
			}

			$provider_data = self::get_event_tickets_attendee_data( $attendee_id );
			$security_code = ! empty( $provider_data['security_code'] ) ? sanitize_text_field( $provider_data['security_code'] ) : self::get_event_tickets_security_code( $attendee_id );
			$event_id      = ! empty( $provider_data['event_id'] ) ? absint( $provider_data['event_id'] ) : absint( self::get_first_post_meta( $attendee_id, self::get_event_tickets_meta_keys( 'event' ) ) );
			$qr_ticket_id  = ! empty( $provider_data['qr_ticket_id'] ) ? absint( $provider_data['qr_ticket_id'] ) : $attendee_id;
			$unique_id     = ! empty( $provider_data['ticket_id'] ) ? sanitize_text_field( $provider_data['ticket_id'] ) : self::get_first_post_meta( $attendee_id, array( '_unique_id' ) );
			$ticket_meta   = self::get_first_post_meta( $attendee_id, self::get_event_tickets_meta_keys( 'ticket' ) );
			$ticket_id     = absint( $ticket_meta );
			$qr_url        = self::get_event_tickets_qr_url( $qr_ticket_id, $event_id, $security_code );

			$normalized[] = array(
				'attendee_id'               => $attendee_id,
				'attendee_name'             => ! empty( $provider_data['holder_name'] ) ? sanitize_text_field( $provider_data['holder_name'] ) : sanitize_text_field( self::get_first_post_meta( $attendee_id, self::get_event_tickets_meta_keys( 'name' ) ) ),
				'attendee_email'            => ! empty( $provider_data['holder_email'] ) ? sanitize_email( $provider_data['holder_email'] ) : sanitize_email( self::get_first_post_meta( $attendee_id, self::get_event_tickets_meta_keys( 'email' ) ) ),
				'attendee_ticket_id'        => $ticket_id,
				'attendee_ticket_unique_id' => '' !== $unique_id ? sanitize_text_field( $unique_id ) : sanitize_text_field( $ticket_meta ),
				'attendee_qr_token'         => sanitize_text_field( $security_code ),
				'attendee_qr_url'           => $qr_url,
				'attendee_qr_image_url'     => self::get_event_tickets_qr_image_url( $provider_data, $qr_url, $qr_ticket_id, $event_id, $security_code ),
				'checkin_status'            => self::get_event_tickets_checkin_status( $attendee_id ),
			);
		}

		return $normalized;
	}

	/**
	 * Matches a GatiCrew attendee row to a real Event Tickets attendee.
	 *
	 * @param array $booking_attendee GatiCrew attendee row.
	 * @param array $event_tickets_attendees Event Tickets attendees.
	 * @param array $used Used Event Tickets attendee IDs.
	 * @param int   $index Current attendee index.
	 * @return array
	 */
	private static function match_event_tickets_attendee( array $booking_attendee, array $event_tickets_attendees, array &$used, $index = 0 ) {
		if ( empty( $event_tickets_attendees ) ) {
			return array();
		}

		$name  = isset( $booking_attendee['attendee_name'] ) ? sanitize_text_field( $booking_attendee['attendee_name'] ) : '';
		$email = isset( $booking_attendee['attendee_email'] ) ? sanitize_email( $booking_attendee['attendee_email'] ) : '';

		if ( '' === $name && ! empty( $booking_attendee['attendee_names'] ) && is_array( $booking_attendee['attendee_names'] ) ) {
			$name = sanitize_text_field( reset( $booking_attendee['attendee_names'] ) );
		}

		foreach ( $event_tickets_attendees as $real_attendee ) {
			$real_id = ! empty( $real_attendee['attendee_id'] ) ? absint( $real_attendee['attendee_id'] ) : 0;

			if ( ! $real_id || in_array( $real_id, $used, true ) ) {
				continue;
			}

			$real_name  = isset( $real_attendee['attendee_name'] ) ? sanitize_text_field( $real_attendee['attendee_name'] ) : '';
			$real_email = isset( $real_attendee['attendee_email'] ) ? sanitize_email( $real_attendee['attendee_email'] ) : '';

			if (
				( '' !== $email && '' !== $real_email && strtolower( $email ) === strtolower( $real_email ) && ( '' === $name || '' === $real_name || strtolower( $name ) === strtolower( $real_name ) ) )
				|| ( '' !== $name && '' !== $real_name && strtolower( $name ) === strtolower( $real_name ) )
			) {
				$used[] = $real_id;
				return $real_attendee;
			}
		}

		if ( isset( $event_tickets_attendees[ $index ] ) ) {
			$real_id = absint( $event_tickets_attendees[ $index ]['attendee_id'] );

			if ( $real_id && ! in_array( $real_id, $used, true ) ) {
				$used[] = $real_id;
				return $event_tickets_attendees[ $index ];
			}
		}

		return array();
	}

	/**
	 * Returns Event Tickets attendee post types across supported providers.
	 *
	 * @return array
	 */
	private static function get_event_tickets_attendee_post_types() {
		return array(
			'tribe_rsvp_attendees',
			'tribe_tpp_attendees',
			'tec_tc_attendee',
			'tribe_wooticket',
		);
	}

	/**
	 * Returns Event Tickets attendee meta keys by purpose.
	 *
	 * @param string $type Meta key type.
	 * @return array
	 */
	private static function get_event_tickets_meta_keys( $type ) {
		$keys = array(
			'order'    => array( '_gaticrew_woo_order_id', '_tribe_rsvp_order', '_tribe_tpp_order', '_tec_tickets_commerce_order', '_tribe_wooticket_order', '_tribe_tickets_order_id', '_tribe_tickets_order' ),
			'event'    => array( '_tribe_rsvp_event', '_tribe_tpp_event', '_tec_tickets_commerce_event', '_tribe_wooticket_event', '_tribe_tickets_post_id' ),
			'ticket'   => array( '_tribe_rsvp_product', '_tribe_tpp_product', '_tec_tickets_commerce_ticket', '_tribe_wooticket_product', '_tribe_tickets_ticket_id' ),
			'security' => array( '_tribe_rsvp_security_code', '_tribe_tpp_security_code', '_tec_tickets_commerce_security_code', '_tribe_wooticket_security_code', '_tribe_tickets_security_code' ),
			'name'     => array( '_tribe_rsvp_full_name', '_tribe_tpp_full_name', '_tribe_tickets_full_name', '_tec_tickets_commerce_full_name', '_tribe_wooticket_full_name' ),
			'email'    => array( '_tribe_rsvp_email', '_tribe_tpp_email', '_tribe_tickets_email', '_tec_tickets_commerce_email', '_tribe_wooticket_email' ),
			'checkin'  => array( '_tribe_rsvp_checkedin', '_tribe_tpp_checkedin', '_tec_tickets_commerce_checked_in', '_tribe_wooticket_checkedin', '_tribe_tickets_checkedin' ),
		);

		return isset( $keys[ $type ] ) ? $keys[ $type ] : array();
	}

	/**
	 * Reads a stored Event Tickets security code from attendee meta.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return string
	 */
	private static function get_event_tickets_security_code( $attendee_id ) {
		$provider = self::get_event_tickets_provider( $attendee_id );

		if ( is_object( $provider ) && ! empty( $provider->security_code ) ) {
			$value = get_post_meta( absint( $attendee_id ), sanitize_text_field( $provider->security_code ), true );

			if ( '' !== (string) $value ) {
				return sanitize_text_field( $value );
			}
		}

		return sanitize_text_field( self::get_first_post_meta( $attendee_id, self::get_event_tickets_meta_keys( 'security' ) ) );
	}

	/**
	 * Reads the canonical Event Tickets attendee array from the active provider.
	 *
	 * Provider data contains TEC's ticket ID, QR attendee ID and security code
	 * exactly as used by ticket emails and QR check-in.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return array
	 */
	private static function get_event_tickets_attendee_data( $attendee_id ) {
		$provider = self::get_event_tickets_provider( $attendee_id );

		if ( ! is_object( $provider ) || ! method_exists( $provider, 'get_attendees_by_id' ) ) {
			return array();
		}

		try {
			$attendees = $provider->get_attendees_by_id( absint( $attendee_id ) );
			$attendee  = is_array( $attendees ) ? reset( $attendees ) : array();

			return is_array( $attendee ) ? $attendee : array();
		} catch ( Throwable $exception ) {
			return array();
		}
	}

	/**
	 * Builds the official Event Tickets QR check-in URL from stored data.
	 *
	 * @param int    $attendee_id Event Tickets attendee post ID.
	 * @param int    $event_id Event post ID.
	 * @param string $security_code Stored Event Tickets security code.
	 * @return string
	 */
	private static function get_event_tickets_qr_url( $attendee_id, $event_id, $security_code ) {
		$attendee_id   = absint( $attendee_id );
		$event_id      = absint( $event_id );
		$security_code = sanitize_text_field( $security_code );

		if ( ! $attendee_id || ! $event_id || '' === $security_code ) {
			return '';
		}

		if ( function_exists( 'tribe' ) && class_exists( '\TEC\Tickets\QR\Connector' ) ) {
			try {
				$connector = tribe( \TEC\Tickets\QR\Connector::class );

				if ( is_object( $connector ) && method_exists( $connector, 'get_checkin_url' ) ) {
					return esc_url_raw( $connector->get_checkin_url( $attendee_id, $event_id, $security_code ) );
				}
			} catch ( Throwable $exception ) {
				return '';
			}
		}

		return esc_url_raw(
			add_query_arg(
				array(
					'event_qr_code' => 1,
					'ticket_id'     => $attendee_id,
					'event_id'      => $event_id,
					'security_code' => $security_code,
					'path'          => function_exists( 'tribe_tickets_rest_url_prefix' ) ? tribe_tickets_rest_url_prefix() . '/qr' : 'tribe/tickets/v1/qr',
				),
				home_url( '/' )
			)
		);
	}

	/**
	 * Returns the Event Tickets-generated QR image URL when the QR connector can provide it.
	 *
	 * @param array  $ticket_data Event Tickets attendee/ticket data.
	 * @param string $qr_url Official Event Tickets QR check-in URL.
	 * @param int    $attendee_id Event Tickets attendee post ID.
	 * @param int    $event_id Event post ID.
	 * @param string $security_code Stored Event Tickets security code.
	 * @return string
	 */
	private static function get_event_tickets_qr_image_url( array $ticket_data, $qr_url, $attendee_id, $event_id, $security_code ) {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\TEC\Tickets\QR\Connector' ) ) {
			return '';
		}

		try {
			$connector = tribe( \TEC\Tickets\QR\Connector::class );

			if ( ! is_object( $connector ) ) {
				return '';
			}

			$ticket_data = array_merge(
				$ticket_data,
				array(
					'qr_ticket_id'  => absint( $attendee_id ),
					'event_id'      => absint( $event_id ),
					'security_code' => sanitize_text_field( $security_code ),
				)
			);

			if ( method_exists( $connector, 'get_image_url_from_ticket_data' ) ) {
				$image_url = $connector->get_image_url_from_ticket_data( $ticket_data );

				if ( $image_url ) {
					return esc_url_raw( $image_url );
				}
			}

			if ( method_exists( $connector, 'get_image_url_for_link' ) && '' !== (string) $qr_url ) {
				$image_url = $connector->get_image_url_for_link( esc_url_raw( $qr_url ) );

				return $image_url ? esc_url_raw( $image_url ) : '';
			}
		} catch ( Throwable $exception ) {
			return '';
		}

		return '';
	}

	/**
	 * Returns Event Tickets check-in status from provider meta.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return string
	 */
	private static function get_event_tickets_checkin_status( $attendee_id ) {
		$provider = self::get_event_tickets_provider( $attendee_id );
		$keys     = self::get_event_tickets_meta_keys( 'checkin' );

		if ( is_object( $provider ) && ! empty( $provider->checkin_key ) ) {
			array_unshift( $keys, sanitize_text_field( $provider->checkin_key ) );
		}

		array_unshift( $keys, '_tribe_qr_status' );

		$checked_in = self::get_first_post_meta( $attendee_id, array_unique( $keys ) );

		if ( function_exists( 'tribe_is_truthy' ) ) {
			return tribe_is_truthy( $checked_in ) ? 'checked_in' : 'confirmed';
		}

		return filter_var( $checked_in, FILTER_VALIDATE_BOOLEAN ) ? 'checked_in' : 'confirmed';
	}

	/**
	 * Returns the Event Tickets provider object for an attendee.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return object|null
	 */
	private static function get_event_tickets_provider( $attendee_id ) {
		if ( ! function_exists( 'tribe' ) ) {
			return null;
		}

		try {
			$data_api = tribe( 'tickets.data_api' );

			return is_object( $data_api ) && method_exists( $data_api, 'get_ticket_provider' ) ? $data_api->get_ticket_provider( absint( $attendee_id ) ) : null;
		} catch ( Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Reads the first non-empty post meta value from a list of keys.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $keys Meta keys.
	 * @return string
	 */
	private static function get_first_post_meta( $post_id, array $keys ) {
		foreach ( $keys as $key ) {
			$value = get_post_meta( absint( $post_id ), sanitize_text_field( $key ), true );

			if ( '' !== (string) $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Returns SQL placeholders for an array.
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private static function get_placeholders( array $values ) {
		return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
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
