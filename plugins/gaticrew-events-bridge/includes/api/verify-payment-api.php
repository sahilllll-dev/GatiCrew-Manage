<?php
/**
 * Razorpay payment verification REST API.
 *
 * This endpoint completes pending frontend-created WooCommerce ticket orders
 * after Razorpay reports a successful payment. The actual Razorpay SDK
 * signature verification is isolated in verify_razorpay_payment() so the
 * production SDK can be added without changing the REST contract.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Verify_Payment_API {
	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'gaticrew/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/verify-payment';

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
		error_log( '[GatiCrew Events Bridge] API boot hook registered: POST /wp-json/' . self::NAMESPACE . self::ROUTE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- TODO: Remove after production route verification.
	}

	/**
	 * Registers POST /wp-json/gaticrew/v1/verify-payment.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify_payment' ),
				'permission_callback' => '__return_true',
			)
		);
		error_log( '[GatiCrew Events Bridge] REST route registered: POST /wp-json/' . self::NAMESPACE . self::ROUTE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- TODO: Remove after production route verification.
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

		GatiCrew_Events_Bridge_CORS::send_headers( 'POST, OPTIONS' );

		return $served;
	}

	/**
	 * Verifies payment payload and completes the WooCommerce booking order.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function verify_payment( WP_REST_Request $request ) {
		self::load_runtime_dependencies();

		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::error_response( 'woocommerce_unavailable', __( 'WooCommerce is not available.', 'gaticrew-events-bridge' ), array(), 503 );
		}

		$payload    = self::sanitize_payload( (array) $request->get_json_params() );
		$validation = self::validate_payload( $payload );

		if ( ! empty( $validation ) ) {
			return self::error_response( 'validation_failed', __( 'Invalid payment verification request.', 'gaticrew-events-bridge' ), $validation, 400 );
		}

		$order = wc_get_order( $payload['order_id'] );

		if ( ! $order instanceof WC_Order ) {
			return self::error_response( 'invalid_order', __( 'WooCommerce order could not be found.', 'gaticrew-events-bridge' ), array( 'order_id' => __( 'Order does not exist.', 'gaticrew-events-bridge' ) ), 404 );
		}

		if ( self::is_order_already_verified( $order ) ) {
			return self::error_response( 'payment_already_verified', __( 'Payment has already been verified for this order.', 'gaticrew-events-bridge' ), array(), 409 );
		}

		if ( ! $order->has_status( 'pending' ) ) {
			return self::error_response( 'invalid_order_status', __( 'Only pending orders can be verified.', 'gaticrew-events-bridge' ), array( 'status' => $order->get_status() ), 409 );
		}

		if ( ! self::verify_razorpay_payment( $payload, $order ) ) {
			return self::error_response( 'payment_verification_failed', __( 'Payment verification failed.', 'gaticrew-events-bridge' ), array(), 400 );
		}

		try {
			$booking_id = self::ensure_booking_id( $order );
			$attendees  = self::ensure_attendees_for_order( $order, $booking_id );
			self::sync_event_tickets_attendees( $order );
			self::ensure_attendee_qr_codes( $attendees );
			self::complete_order_payment( $order, $payload );
		} catch ( Exception $exception ) {
			return self::error_response(
				'payment_completion_failed',
				__( 'Payment was verified but booking completion failed.', 'gaticrew-events-bridge' ),
				array( 'order' => sanitize_text_field( $exception->getMessage() ) ),
				500
			);
		}

		$response = rest_ensure_response(
			array(
				'success'    => true,
				'message'    => __( 'Payment verified successfully', 'gaticrew-events-bridge' ),
				'booking_id' => $booking_id,
				'order_id'   => absint( $order->get_id() ),
			)
		);

		GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'POST, OPTIONS' );

		return $response;
	}

	/**
	 * Sanitizes incoming JSON into a stable structure.
	 *
	 * @param array $payload Raw request body.
	 * @return array
	 */
	private static function sanitize_payload( array $payload ) {
		return array(
			'order_id'            => isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : 0,
			'razorpay_order_id'   => isset( $payload['razorpay_order_id'] ) ? sanitize_text_field( $payload['razorpay_order_id'] ) : '',
			'razorpay_payment_id' => isset( $payload['razorpay_payment_id'] ) ? sanitize_text_field( $payload['razorpay_payment_id'] ) : '',
			'razorpay_signature'  => isset( $payload['razorpay_signature'] ) ? sanitize_text_field( $payload['razorpay_signature'] ) : '',
		);
	}

	/**
	 * Validates required payment verification fields.
	 *
	 * @param array $payload Sanitized payload.
	 * @return array
	 */
	private static function validate_payload( array $payload ) {
		$errors = array();

		if ( empty( $payload['order_id'] ) ) {
			$errors['order_id'] = __( 'WooCommerce order_id is required.', 'gaticrew-events-bridge' );
		}

		if ( '' === $payload['razorpay_order_id'] ) {
			$errors['razorpay_order_id'] = __( 'Razorpay order ID is required.', 'gaticrew-events-bridge' );
		}

		if ( '' === $payload['razorpay_payment_id'] ) {
			$errors['razorpay_payment_id'] = __( 'Razorpay payment ID is required.', 'gaticrew-events-bridge' );
		}

		if ( '' === $payload['razorpay_signature'] ) {
			$errors['razorpay_signature'] = __( 'Razorpay signature is required.', 'gaticrew-events-bridge' );
		}

		return $errors;
	}

	/**
	 * Placeholder Razorpay signature verification.
	 *
	 * TODO: Install Razorpay PHP SDK and replace this placeholder with:
	 * Razorpay\Api\Utility::verifyPaymentSignature(
	 *     array(
	 *         'razorpay_order_id' => $payload['razorpay_order_id'],
	 *         'razorpay_payment_id' => $payload['razorpay_payment_id'],
	 *         'razorpay_signature' => $payload['razorpay_signature'],
	 *     )
	 * );
	 *
	 * Until the SDK keys are configured, this method returns true for a complete
	 * sanitized payload and exposes a filter for local hardening/testing.
	 *
	 * @param array    $payload Sanitized payment payload.
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function verify_razorpay_payment( array $payload, WC_Order $order ) {
		$is_valid_placeholder = '' !== $payload['razorpay_order_id']
			&& '' !== $payload['razorpay_payment_id']
			&& '' !== $payload['razorpay_signature'];

		return (bool) apply_filters( 'gaticrew_events_bridge_verify_razorpay_payment', $is_valid_placeholder, $payload, $order );
	}

	/**
	 * Prevents duplicate verification for already-paid orders.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private static function is_order_already_verified( WC_Order $order ) {
		return '' !== sanitize_text_field( (string) $order->get_meta( '_gaticrew_payment_verified_at', true ) )
			|| '' !== sanitize_text_field( (string) $order->get_meta( '_gaticrew_razorpay_payment_id', true ) );
	}

	/**
	 * Ensures the order has one stable booking ID.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private static function ensure_booking_id( WC_Order $order ) {
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $order->get_meta( self::get_order_meta_key( 'ORDER_META_BOOKING_ID', '_gaticrew_booking_id' ), true ) );

		if ( '' === $booking_id ) {
			$booking_id = self::get_first_item_booking_id( $order );
		}

		if ( '' === $booking_id ) {
			$booking_id = GatiCrew_Events_Bridge_Bookings::generate_booking_id();
		}

		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_BOOKING_ID', '_gaticrew_booking_id' ), $booking_id );
		$order->save();

		return $booking_id;
	}

	/**
	 * Creates attendee rows when the create-order step did not already create them.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $booking_id Booking ID.
	 * @return array Attendee rows for the booking.
	 */
	private static function ensure_attendees_for_order( WC_Order $order, $booking_id ) {
		if ( ! class_exists( 'GatiCrew_Events_Bridge_Attendees_Repository' ) ) {
			return array();
		}

		$repository = new GatiCrew_Events_Bridge_Attendees_Repository();
		$order_id   = absint( $order->get_id() );
		$event_id   = self::get_order_event_id( $order );
		$product_id = self::get_order_product_id( $order, $event_id );

		if ( ! $order_id || ! $event_id ) {
			throw new RuntimeException( __( 'Order is not linked to a GatiCrew event.', 'gaticrew-events-bridge' ) );
		}

		$quantity       = self::get_order_quantity( $order );
		$attendee_names = self::get_order_attendee_names( $order, $quantity );
		$customer       = self::get_customer_data( $order );

		for ( $index = 1; $index <= $quantity; $index++ ) {
			if ( $repository->exists_for_order_booking_ticket_index( $order_id, $booking_id, $index ) ) {
				continue;
			}

			$attendee_name = isset( $attendee_names[ $index - 1 ] ) && '' !== $attendee_names[ $index - 1 ]
				? $attendee_names[ $index - 1 ]
				: $customer['name'];

			$repository->create(
				array(
					'order_id'              => $order_id,
					'event_id'              => $event_id,
					'product_id'            => $product_id,
					'booking_id'            => $booking_id,
					'ticket_index'          => $index,
					'attendee_name'         => $attendee_name,
					'attendee_names'        => array( $attendee_name ),
					'attendee_email'        => $customer['email'],
					'attendee_phone'        => $customer['phone'],
					'quantity'              => $quantity,
					'ticket_quantity'       => $quantity,
					'status'                => GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED,
					'allow_group_duplicate' => true,
				)
			);
		}

		$attendees = $repository->get_group_by_order_booking( $order_id, $booking_id );

		if ( empty( $attendees ) ) {
			throw new RuntimeException( __( 'No attendee rows could be created for this booking.', 'gaticrew-events-bridge' ) );
		}

		return $attendees;
	}

	/**
	 * Generates attendee-ID QR codes and stores QR data against each row.
	 *
	 * @param array $attendees Attendee rows.
	 * @return void
	 */
	private static function ensure_attendee_qr_codes( array $attendees ) {
		global $wpdb;

		if ( empty( $attendees ) || ! class_exists( 'GatiCrew_Events_Bridge_QR_Code' ) ) {
			return;
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		foreach ( $attendees as $attendee ) {
			$attendee_id = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;

			if ( ! $attendee_id ) {
				continue;
			}

			$qr_url = self::generate_attendee_id_qr_code( $attendee_id );

			$wpdb->update(
				$table,
				array(
					'qr_code'        => esc_url_raw( $qr_url ),
					'qr_token'       => (string) $attendee_id,
					'qr_status'      => GatiCrew_Events_Bridge_QR_Tokens::STATUS_ACTIVE,
					'status'         => GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED,
					'booking_status' => GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED,
				),
				array( 'id' => $attendee_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			// TODO: Trigger PDF regeneration here when final ticket PDFs need to
			// include payment receipt data from the Razorpay SDK response.
		}
	}

	/**
	 * Ensures real Event Tickets attendees exist before the paid order completes.
	 *
	 * This keeps direct Woo order creation aligned with the TEC attendee/QR
	 * model used by Event Tickets scanner validation.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function sync_event_tickets_attendees( WC_Order $order ) {
		if ( ! class_exists( 'GatiCrew_Events_Bridge_Event_Tickets_Sync' ) ) {
			return;
		}

		GatiCrew_Events_Bridge_Event_Tickets_Sync::sync_order( $order );
	}

	/**
	 * Completes the WooCommerce order after verification.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $payload Sanitized payment payload.
	 * @return void
	 */
	private static function complete_order_payment( WC_Order $order, array $payload ) {
		$verified_at = current_time( 'mysql' );

		$order->update_meta_data( '_gaticrew_razorpay_order_id', $payload['razorpay_order_id'] );
		$order->update_meta_data( '_gaticrew_razorpay_payment_id', $payload['razorpay_payment_id'] );
		$order->update_meta_data( '_gaticrew_razorpay_signature', $payload['razorpay_signature'] );
		$order->update_meta_data( '_gaticrew_payment_verified_at', $verified_at );
		$order->update_meta_data( '_gaticrew_payment_status', 'verified' );
		$order->update_meta_data( '_gaticrew_booking_status', GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED );
		$order->set_transaction_id( $payload['razorpay_payment_id'] );
		$order->payment_complete( $payload['razorpay_payment_id'] );
		$order->add_order_note( sprintf( 'GatiCrew Razorpay payment verified at %1$s. Razorpay payment ID: %2$s', $verified_at, $payload['razorpay_payment_id'] ) );
		$order->save();
	}

	/**
	 * Generates and stores an SVG QR image for /checkin/{attendee_id}.
	 *
	 * @param int $attendee_id Attendee row ID.
	 * @return string Public QR image URL.
	 */
	private static function generate_attendee_id_qr_code( $attendee_id ) {
		$attendee_id = absint( $attendee_id );

		if ( ! $attendee_id ) {
			return '';
		}

		$target = self::get_upload_target( 'qr', 'gaticrew-attendee-qr-' . $attendee_id . '.svg' );

		if ( empty( $target['path'] ) || empty( $target['url'] ) ) {
			return '';
		}

		$validation_url = self::get_attendee_validation_url( $attendee_id );
		$svg            = GatiCrew_Events_Bridge_QR_Code::render_svg( $validation_url, 4, 4 );

		if ( '' === $svg ) {
			return '';
		}

		file_put_contents( $target['path'], $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return esc_url_raw( $target['url'] );
	}

	/**
	 * Returns the validation URL encoded into attendee-ID QR codes.
	 *
	 * @param int $attendee_id Attendee row ID.
	 * @return string
	 */
	private static function get_attendee_validation_url( $attendee_id ) {
		$attendee_id = absint( $attendee_id );
		$base_url    = apply_filters( 'gaticrew_events_bridge_attendee_checkin_base_url', 'https://manage.gaticrew.com/checkin' );
		$base_url    = untrailingslashit( esc_url_raw( $base_url ) );

		if ( '' === $base_url ) {
			$base_url = untrailingslashit( home_url( '/checkin' ) );
		}

		return $base_url . '/' . rawurlencode( (string) $attendee_id );
	}

	/**
	 * Returns a writable uploads path and public URL.
	 *
	 * @param string $subdir Asset subdirectory.
	 * @param string $filename Asset filename.
	 * @return array
	 */
	private static function get_upload_target( $subdir, $filename ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return array();
		}

		$subdir   = sanitize_key( $subdir );
		$filename = sanitize_file_name( $filename );
		$dir      = trailingslashit( $uploads['basedir'] ) . 'gaticrew-tickets/' . $subdir;
		$url      = trailingslashit( $uploads['baseurl'] ) . 'gaticrew-tickets/' . $subdir . '/' . $filename;

		if ( ! wp_mkdir_p( $dir ) ) {
			return array();
		}

		return array(
			'path' => trailingslashit( $dir ) . $filename,
			'url'  => $url,
		);
	}

	/**
	 * Finds event ID from order meta or linked product line items.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int
	 */
	private static function get_order_event_id( WC_Order $order ) {
		$event_id = absint( $order->get_meta( self::get_order_meta_key( 'ORDER_META_EVENT_ID', '_gaticrew_event_id' ), true ) );

		if ( $event_id ) {
			return $event_id;
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$event_id   = self::get_event_id_by_product_id( $product_id );

			if ( $event_id ) {
				$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_ID', '_gaticrew_event_id' ), $event_id );
				$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_NAME', '_gaticrew_event_name' ), GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ) );
				$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_DATE', '_gaticrew_event_date' ), GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) );
				$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_VENUE', '_gaticrew_event_venue' ), GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) );
				$order->save();

				return $event_id;
			}
		}

		return 0;
	}

	/**
	 * Returns the first linked product ID for the verified order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param int      $event_id Event ID.
	 * @return int
	 */
	private static function get_order_product_id( WC_Order $order, $event_id ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$linked_event_id = self::get_event_id_by_product_id( $product_id );

			if ( $linked_event_id && absint( $event_id ) === $linked_event_id ) {
				return absint( $product_id );
			}
		}

		return 0;
	}

	/**
	 * Finds an event linked to a product through either supported meta key.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private static function get_event_id_by_product_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return 0;
		}

		$events = get_posts(
			array(
				'post_type'              => GatiCrew_Events_Bridge_Events::get_event_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => '_gaticrew_product_id',
						'value'   => $product_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => self::get_event_product_meta_key(),
						'value'   => $product_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return ! empty( $events[0] ) ? absint( $events[0] ) : 0;
	}

	/**
	 * Returns the total linked ticket quantity on the order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int
	 */
	private static function get_order_quantity( WC_Order $order ) {
		$quantity = absint( $order->get_meta( '_gaticrew_quantity', true ) );

		if ( $quantity ) {
			return $quantity;
		}

		$total    = 0;
		$event_id = self::get_order_event_id( $order );

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id      = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$linked_event_id = self::get_event_id_by_product_id( $product_id );

			if ( ! $event_id || ! $linked_event_id || absint( $event_id ) === $linked_event_id ) {
				$total += absint( $item->get_quantity() );
			}
		}

		return max( 1, $total );
	}

	/**
	 * Returns attendee names saved during order creation.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param int      $quantity Ticket quantity.
	 * @return array
	 */
	private static function get_order_attendee_names( WC_Order $order, $quantity ) {
		$names = self::normalize_names_value( $order->get_meta( self::get_order_meta_key( 'ORDER_META_ATTENDEE_NAMES', '_gaticrew_attendee_names' ), true ) );

		if ( empty( $names ) ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$names = self::normalize_names_value( $item->get_meta( '_gaticrew_attendee_names', true ) );

				if ( ! empty( $names ) ) {
					break;
				}
			}
		}

		$customer = self::get_customer_data( $order );

		while ( count( $names ) < absint( $quantity ) ) {
			$names[] = $customer['name'];
		}

		return array_slice( array_values( $names ), 0, absint( $quantity ) );
	}

	/**
	 * Normalizes attendee name meta into a sanitized array.
	 *
	 * @param mixed $value Raw meta value.
	 * @return array
	 */
	private static function normalize_names_value( $value ) {
		$value = maybe_unserialize( $value );

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$names = array();

		foreach ( $value as $name ) {
			$name = sanitize_text_field( $name );

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( $names );
	}

	/**
	 * Returns customer data used in attendee fallback creation.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function get_customer_data( WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );

		if ( '' === $name ) {
			$name = sanitize_text_field( (string) $order->get_meta( self::get_order_meta_key( 'ORDER_META_CUSTOMER_NAME', '_gaticrew_customer_name' ), true ) );
		}

		return array(
			'name'  => '' !== $name ? sanitize_text_field( $name ) : __( 'Guest', 'gaticrew-events-bridge' ),
			'email' => sanitize_email( $order->get_billing_email() ? $order->get_billing_email() : $order->get_meta( self::get_order_meta_key( 'ORDER_META_CUSTOMER_EMAIL', '_gaticrew_customer_email' ), true ) ),
			'phone' => function_exists( 'wc_sanitize_phone_number' ) ? wc_sanitize_phone_number( $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_meta( self::get_order_meta_key( 'ORDER_META_CUSTOMER_PHONE', '_gaticrew_customer_phone' ), true ) ) : sanitize_text_field( $order->get_billing_phone() ),
		);
	}

	/**
	 * Returns the first booking ID stored on an order item.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private static function get_first_item_booking_id( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $item->get_meta( '_gaticrew_booking_id', true ) );

				if ( '' !== $booking_id ) {
					return $booking_id;
				}
			}
		}

		return '';
	}

	/**
	 * Returns the event product meta key with fallback for early load contexts.
	 *
	 * @return string
	 */
	private static function get_event_product_meta_key() {
		return class_exists( 'GatiCrew_Events_Bridge' ) && defined( 'GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID' )
			? GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID
			: '_gaticrew_ticket_product_id';
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
			'qr/class-gaticrew-events-bridge-qr-tokens.php',
			'qr/class-gaticrew-events-bridge-qr-code.php',
			'includes/class-gaticrew-events-bridge-ticket-assets.php',
			'includes/class-gaticrew-events-bridge-event-tickets-sync.php',
			'attendees/class-gaticrew-events-bridge-attendees-repository.php',
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

		GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'POST, OPTIONS' );

		return $response;
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}

GatiCrew_Events_Bridge_Verify_Payment_API::init();
