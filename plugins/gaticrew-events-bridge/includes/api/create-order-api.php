<?php
/**
 * Frontend order creation REST API.
 *
 * This endpoint creates pending WooCommerce ticket orders for the gaticrew.com
 * booking popup. Payment gateway handoff is intentionally left out; Razorpay
 * can be layered on top once the order creation contract is stable.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Create_Order_API {
	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'gaticrew/v1';

	/**
	 * REST route.
	 */
	const ROUTE = '/create-order';

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
	 * Registers POST /wp-json/gaticrew/v1/create-order.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_order' ),
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
	 * Creates a pending WooCommerce order and attendee records.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function create_order( WP_REST_Request $request ) {
		self::load_runtime_dependencies();

		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return self::error_response( 'woocommerce_unavailable', __( 'WooCommerce is not available.', 'gaticrew-events-bridge' ), array(), 503 );
		}

		$payload    = self::sanitize_payload( (array) $request->get_json_params() );
		$validation = self::validate_payload( $payload );

		if ( ! empty( $validation ) ) {
			return self::error_response( 'validation_failed', __( 'Invalid booking request.', 'gaticrew-events-bridge' ), $validation, 400 );
		}

		$event_id = absint( $payload['event_id'] );
		$product_id = self::get_linked_product_id( $event_id );

		if ( ! $product_id ) {
			return self::error_response(
				'product_not_linked',
				__( 'No WooCommerce ticket product is linked to this event.', 'gaticrew-events-bridge' ),
				array( 'event_id' => __( 'Event does not have a linked ticket product.', 'gaticrew-events-bridge' ) ),
				404
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'trash' === get_post_status( $product_id ) ) {
			return self::error_response(
				'invalid_product',
				__( 'Linked ticket product is not available.', 'gaticrew-events-bridge' ),
				array( 'product_id' => __( 'Linked product could not be loaded.', 'gaticrew-events-bridge' ) ),
				404
			);
		}

		try {
			$order = self::build_order( $payload, $event_id, $product );
			self::create_attendee_rows( $order, $payload, $event_id, $product_id );
		} catch ( Exception $exception ) {
			return self::error_response(
				'order_creation_failed',
				__( 'Order could not be created.', 'gaticrew-events-bridge' ),
				array( 'order' => sanitize_text_field( $exception->getMessage() ) ),
				500
			);
		}

		$response = rest_ensure_response(
			array(
				'success'    => true,
				'order_id'   => absint( $order->get_id() ),
				'booking_id' => GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $payload['booking_id'] ),
				'amount'     => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
				'currency'   => get_woocommerce_currency(),
			)
		);

		$response->set_status( 201 );
		GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'POST, OPTIONS' );

		return $response;
	}

	/**
	 * Sanitizes incoming JSON into a predictable shape.
	 *
	 * @param array $payload Raw request body.
	 * @return array
	 */
	private static function sanitize_payload( array $payload ) {
		$attendees = array();

		if ( ! empty( $payload['attendees'] ) && is_array( $payload['attendees'] ) ) {
			foreach ( $payload['attendees'] as $attendee ) {
				$name = is_array( $attendee ) && isset( $attendee['name'] ) ? sanitize_text_field( $attendee['name'] ) : '';

				if ( '' !== $name ) {
					$attendees[] = array( 'name' => $name );
				}
			}
		}

		return array(
			'event_id'       => isset( $payload['event_id'] ) ? absint( $payload['event_id'] ) : 0,
			'quantity'       => isset( $payload['quantity'] ) ? absint( $payload['quantity'] ) : 0,
			'customer_name'  => isset( $payload['customer_name'] ) ? sanitize_text_field( $payload['customer_name'] ) : '',
			'customer_email' => isset( $payload['customer_email'] ) ? self::sanitize_email_value( $payload['customer_email'] ) : '',
			'customer_phone' => isset( $payload['customer_phone'] ) ? wc_sanitize_phone_number( $payload['customer_phone'] ) : '',
			'attendees'      => $attendees,
			'booking_id'     => GatiCrew_Events_Bridge_Bookings::generate_booking_id(),
		);
	}

	/**
	 * Validates the sanitized payload.
	 *
	 * @param array $payload Sanitized payload.
	 * @return array
	 */
	private static function validate_payload( array $payload ) {
		$errors = array();

		if ( empty( $payload['event_id'] ) || ! self::is_valid_event( $payload['event_id'] ) ) {
			$errors['event_id'] = __( 'A valid published event_id is required.', 'gaticrew-events-bridge' );
		}

		if ( empty( $payload['quantity'] ) || 0 >= absint( $payload['quantity'] ) ) {
			$errors['quantity'] = __( 'Quantity must be greater than zero.', 'gaticrew-events-bridge' );
		}

		if ( '' === $payload['customer_name'] ) {
			$errors['customer_name'] = __( 'Customer name is required.', 'gaticrew-events-bridge' );
		}

		if ( '' === $payload['customer_email'] || ! is_email( $payload['customer_email'] ) ) {
			$errors['customer_email'] = __( 'A valid customer email is required.', 'gaticrew-events-bridge' );
		}

		if ( count( $payload['attendees'] ) !== absint( $payload['quantity'] ) ) {
			$errors['attendees'] = __( 'Attendee count must match quantity.', 'gaticrew-events-bridge' );
		}

		foreach ( $payload['attendees'] as $index => $attendee ) {
			if ( empty( $attendee['name'] ) ) {
				$errors[ 'attendees.' . $index . '.name' ] = __( 'Attendee name is required.', 'gaticrew-events-bridge' );
			}
		}

		return $errors;
	}

	/**
	 * Creates and saves the pending WooCommerce order.
	 *
	 * @param array      $payload Sanitized payload.
	 * @param int        $event_id Event ID.
	 * @param WC_Product $product Product object.
	 * @return WC_Order
	 */
	private static function build_order( array $payload, $event_id, WC_Product $product ) {
		$order = wc_create_order(
			array(
				'created_via' => 'gaticrew-rest-api',
			)
		);

		if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
			throw new RuntimeException( __( 'WooCommerce order initialization failed.', 'gaticrew-events-bridge' ) );
		}

		$item_id = $order->add_product( $product, absint( $payload['quantity'] ) );
		$item    = $item_id ? $order->get_item( $item_id ) : false;

		if ( $item instanceof WC_Order_Item_Product ) {
			$item->update_meta_data( '_gaticrew_booking_id', $payload['booking_id'] );
			$item->update_meta_data( '_gaticrew_attendee_names', self::get_attendee_names( $payload['attendees'] ) );
			$item->save();
		}

		$name_parts = self::split_customer_name( $payload['customer_name'] );

		$order->set_billing_first_name( $name_parts['first_name'] );
		$order->set_billing_last_name( $name_parts['last_name'] );
		$order->set_billing_email( $payload['customer_email'] );
		$order->set_billing_phone( $payload['customer_phone'] );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_ID', '_gaticrew_event_id' ), absint( $event_id ) );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_NAME', '_gaticrew_event_name' ), GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ) );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_DATE', '_gaticrew_event_date' ), GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_EVENT_VENUE', '_gaticrew_event_venue' ), GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_BOOKING_ID', '_gaticrew_booking_id' ), $payload['booking_id'] );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_CUSTOMER_NAME', '_gaticrew_customer_name' ), $payload['customer_name'] );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_CUSTOMER_EMAIL', '_gaticrew_customer_email' ), $payload['customer_email'] );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_CUSTOMER_PHONE', '_gaticrew_customer_phone' ), $payload['customer_phone'] );
		$order->update_meta_data( self::get_order_meta_key( 'ORDER_META_ATTENDEE_NAMES', '_gaticrew_attendee_names' ), self::get_attendee_names( $payload['attendees'] ) );
		$order->update_meta_data( '_gaticrew_quantity', absint( $payload['quantity'] ) );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/**
	 * Creates one attendee row per attendee for frontend-created orders.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $payload Sanitized payload.
	 * @param int      $event_id Event ID.
	 * @param int      $product_id Product ID.
	 * @return void
	 */
	private static function create_attendee_rows( WC_Order $order, array $payload, $event_id, $product_id ) {
		if ( ! class_exists( 'GatiCrew_Events_Bridge_Attendees_Repository' ) ) {
			return;
		}

		$repository = new GatiCrew_Events_Bridge_Attendees_Repository();
		$order_id   = absint( $order->get_id() );
		$quantity   = absint( $payload['quantity'] );

		foreach ( $payload['attendees'] as $index => $attendee ) {
			$ticket_index = $index + 1;

			if ( $repository->exists_for_order_booking_ticket_index( $order_id, $payload['booking_id'], $ticket_index ) ) {
				continue;
			}

			$repository->create(
				array(
					'order_id'               => $order_id,
					'event_id'               => absint( $event_id ),
					'product_id'             => absint( $product_id ),
					'booking_id'             => $payload['booking_id'],
					'ticket_index'           => $ticket_index,
					'attendee_name'          => sanitize_text_field( $attendee['name'] ),
					'attendee_names'         => array( sanitize_text_field( $attendee['name'] ) ),
					'attendee_email'         => $payload['customer_email'],
					'attendee_phone'         => $payload['customer_phone'],
					'quantity'               => $quantity,
					'ticket_quantity'        => $quantity,
					'status'                 => GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED,
					'allow_group_duplicate'  => true,
				)
			);
		}
	}

	/**
	 * Finds linked WooCommerce product ID from event meta.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	private static function get_linked_product_id( $event_id ) {
		$event_id = absint( $event_id );
		$product_id = absint( get_post_meta( $event_id, '_gaticrew_product_id', true ) );

		if ( ! $product_id && class_exists( 'GatiCrew_Events_Bridge' ) ) {
			$product_id = absint( get_post_meta( $event_id, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID, true ) );
		}

		return $product_id;
	}

	/**
	 * Validates that an event exists and is published.
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	private static function is_valid_event( $event_id ) {
		$event_id = absint( $event_id );

		if ( ! $event_id ) {
			return false;
		}

		$post_type = class_exists( 'GatiCrew_Events_Bridge_Events' ) ? GatiCrew_Events_Bridge_Events::get_event_post_type() : 'tribe_events';

		return $post_type === get_post_type( $event_id ) && 'publish' === get_post_status( $event_id );
	}

	/**
	 * Returns attendee names from sanitized attendee rows.
	 *
	 * @param array $attendees Attendee rows.
	 * @return array
	 */
	private static function get_attendee_names( array $attendees ) {
		$names = array();

		foreach ( $attendees as $attendee ) {
			if ( ! empty( $attendee['name'] ) ) {
				$names[] = sanitize_text_field( $attendee['name'] );
			}
		}

		return $names;
	}

	/**
	 * Splits a full customer name into billing first and last name fields.
	 *
	 * @param string $name Customer full name.
	 * @return array
	 */
	private static function split_customer_name( $name ) {
		$parts      = preg_split( '/\s+/', trim( sanitize_text_field( $name ) ) );
		$first_name = ! empty( $parts[0] ) ? array_shift( $parts ) : '';

		return array(
			'first_name' => $first_name,
			'last_name'  => implode( ' ', $parts ),
		);
	}

	/**
	 * Extracts and sanitizes email values, including markdown-mailto accidents.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	private static function sanitize_email_value( $email ) {
		$email = sanitize_text_field( (string) $email );

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $email, $matches ) ) {
			return sanitize_email( $matches[0] );
		}

		return sanitize_email( $email );
	}

	/**
	 * Returns a plugin order meta key with fallback for early load contexts.
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

GatiCrew_Events_Bridge_Create_Order_API::init();
