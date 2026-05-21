<?php
/**
 * Frontend order creation REST API.
 *
 * This endpoint creates pending WooCommerce ticket orders for the gaticrew.com
 * booking popup. The endpoint creates a pending WooCommerce order, then creates
 * a Razorpay Orders API order that the frontend can pass to Razorpay Checkout.
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
	 * Razorpay config names.
	 *
	 * Define these in wp-config.php or as environment variables:
	 * define( 'RAZORPAY_KEY_ID', 'rzp_...' );
	 * define( 'RAZORPAY_KEY_SECRET', '...' );
	 */
	const RAZORPAY_KEY_ID_CONFIG     = 'RAZORPAY_KEY_ID';
	const RAZORPAY_KEY_SECRET_CONFIG = 'RAZORPAY_KEY_SECRET';

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
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_order( WP_REST_Request $request ) {
		try {
			error_log( 'create-order callback started' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Temporary API callback debug log.
			error_log( print_r( $request->get_json_params(), true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Temporary payload debug log.

			self::load_runtime_dependencies();

			if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) || ! class_exists( 'WooCommerce' ) ) {
				self::debug_log( 'WooCommerce missing' );
				return self::error_response( 'woocommerce_unavailable', __( 'WooCommerce is not available.', 'gaticrew-events-bridge' ), array(), 503 );
			}

			$payload    = self::sanitize_payload( (array) $request->get_json_params() );
			$validation = self::validate_payload( $payload );

			if ( ! empty( $validation ) ) {
				if ( isset( $validation['event_id'] ) ) {
					self::debug_log( 'Event not found' );
				}

				self::debug_log( 'Create-order validation failed: ' . wp_json_encode( $validation ) );
				return self::error_response( 'validation_failed', __( 'Invalid booking request.', 'gaticrew-events-bridge' ), $validation, 400 );
			}

			$event_id = absint( $payload['event_id'] );

			if ( ! self::is_valid_event( $event_id ) ) {
				self::debug_log( 'Event not found' );
				return self::error_response(
					'event_not_found',
					__( 'Event not found.', 'gaticrew-events-bridge' ),
					array( 'event_id' => __( 'A valid published event_id is required.', 'gaticrew-events-bridge' ) ),
					404
				);
			}

			$product_id = self::get_linked_product_id( $event_id );

			if ( ! $product_id ) {
				self::debug_log( 'Linked product missing' );
				return self::error_response(
					'missing_product',
					__( 'Linked WooCommerce product not found.', 'gaticrew-events-bridge' ),
					array( 'event_id' => __( 'Event does not have a linked ticket product.', 'gaticrew-events-bridge' ) ),
					400
				);
			}

			$product = wc_get_product( $product_id );

			if ( ! $product || 'trash' === get_post_status( $product_id ) ) {
				self::debug_log( 'Linked product could not be loaded. Product ID: ' . absint( $product_id ) );
				return self::error_response(
					'invalid_product',
					__( 'Linked ticket product is not available.', 'gaticrew-events-bridge' ),
					array( 'product_id' => __( 'Linked product could not be loaded.', 'gaticrew-events-bridge' ) ),
					404
				);
			}

			if ( ! $product->is_purchasable() ) {
				self::debug_log( 'Product not purchasable. Product ID: ' . absint( $product_id ) );
				return self::error_response(
					'product_not_purchasable',
					__( 'Linked WooCommerce product is not purchasable.', 'gaticrew-events-bridge' ),
					array( 'product_id' => __( 'Linked product is not purchasable.', 'gaticrew-events-bridge' ) ),
					400
				);
			}

			$razorpay_credentials = self::get_razorpay_credentials();

			if ( is_wp_error( $razorpay_credentials ) ) {
				return $razorpay_credentials;
			}

			$order          = self::build_order( $payload, $event_id, $product );
			$razorpay_order = self::create_razorpay_order( $order, $razorpay_credentials );

			if ( is_wp_error( $razorpay_order ) ) {
				return $razorpay_order;
			}

			$order->update_meta_data( '_gaticrew_razorpay_order_id', $razorpay_order['order_id'] );
			$order->save();

			self::create_attendee_rows( $order, $payload, $event_id, $product_id );

			$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $payload['booking_id'] );
			self::debug_log( 'Create-order completed. Order ID: ' . absint( $order->get_id() ) . ' Booking ID: ' . $booking_id );

			$response = new WP_REST_Response(
				array(
					'success'    => true,
					'order_id'   => absint( $order->get_id() ),
					'booking_id' => $booking_id,
					'amount'     => (float) wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
					'currency'   => $razorpay_order['currency'],
					'razorpay'   => array(
						'key'      => $razorpay_order['key'],
						'order_id' => $razorpay_order['order_id'],
						'amount'   => absint( $razorpay_order['amount'] ),
						'currency' => $razorpay_order['currency'],
					),
				),
				200
			);

			GatiCrew_Events_Bridge_CORS::add_response_headers( $response, 'POST, OPTIONS' );

			return $response;
		} catch ( Throwable $exception ) {
			error_log( $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Temporary API callback debug log.

			return new WP_Error(
				'server_error',
				$exception->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Creates a Razorpay order for the WooCommerce order.
	 *
	 * Uses the Razorpay PHP SDK when present, otherwise falls back to the
	 * Razorpay Orders REST API through WordPress HTTP functions.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $credentials Razorpay API credentials.
	 * @return array|WP_Error
	 */
	private static function create_razorpay_order( WC_Order $order, array $credentials ) {
		$key_id     = $credentials['key_id'];
		$key_secret = $credentials['key_secret'];

		$amount = absint( round( (float) $order->get_total() * 100 ) );

		if ( 0 >= $amount ) {
			self::debug_log( 'Razorpay amount invalid for Woo order ' . absint( $order->get_id() ) . '. Amount: ' . $amount );
			return self::error_response(
				'razorpay_invalid_amount',
				__( 'Razorpay order amount is invalid.', 'gaticrew-events-bridge' ),
				array( 'amount' => __( 'Order total must be greater than zero.', 'gaticrew-events-bridge' ) ),
				400
			);
		}

		$payload = array(
			'amount'          => $amount,
			'currency'        => 'INR',
			'receipt'         => (string) absint( $order->get_id() ),
			'payment_capture' => 1,
			'notes'           => array(
				'woocommerce_order_id' => (string) absint( $order->get_id() ),
				'booking_id'           => GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $order->get_meta( self::get_order_meta_key( 'ORDER_META_BOOKING_ID', '_gaticrew_booking_id' ), true ) ),
			),
		);

		self::debug_log( 'Creating Razorpay order for Woo order ' . absint( $order->get_id() ) . ' amount ' . $amount . ' INR.' );

		if ( self::load_razorpay_sdk() && class_exists( 'Razorpay\Api\Api' ) ) {
			return self::create_razorpay_order_with_sdk( $payload, $key_id, $key_secret );
		}

		return self::create_razorpay_order_with_http( $payload, $key_id, $key_secret );
	}

	/**
	 * Reads Razorpay credentials from wp-config.php constants or environment.
	 *
	 * @return array|WP_Error
	 */
	private static function get_razorpay_credentials() {
		$key_id     = self::get_razorpay_config_value( self::RAZORPAY_KEY_ID_CONFIG );
		$key_secret = self::get_razorpay_config_value( self::RAZORPAY_KEY_SECRET_CONFIG );

		if ( '' === $key_id || '' === $key_secret ) {
			self::debug_log( 'Razorpay config missing. Define RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.' );
			return self::error_response(
				'razorpay_config_missing',
				__( 'Razorpay configuration is missing.', 'gaticrew-events-bridge' ),
				array( 'razorpay' => __( 'Define RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET in wp-config.php or environment variables.', 'gaticrew-events-bridge' ) ),
				500
			);
		}

		return array(
			'key_id'     => $key_id,
			'key_secret' => $key_secret,
		);
	}

	/**
	 * Creates Razorpay order through the official SDK when available.
	 *
	 * @param array  $payload Razorpay Orders API payload.
	 * @param string $key_id Razorpay key ID.
	 * @param string $key_secret Razorpay key secret.
	 * @return array|WP_Error
	 */
	private static function create_razorpay_order_with_sdk( array $payload, $key_id, $key_secret ) {
		try {
			$api            = new \Razorpay\Api\Api( $key_id, $key_secret );
			$razorpay_order = $api->order->create( $payload );
			$data           = self::normalize_razorpay_order_data( $razorpay_order );

			if ( empty( $data['id'] ) ) {
				self::debug_log( 'Razorpay SDK response missing order id.' );
				return self::error_response(
					'razorpay_order_failed',
					__( 'Razorpay order could not be created.', 'gaticrew-events-bridge' ),
					array( 'razorpay' => __( 'Razorpay SDK response did not include an order ID.', 'gaticrew-events-bridge' ) ),
					502
				);
			}

			self::debug_log( 'Razorpay SDK order created: ' . sanitize_text_field( $data['id'] ) );

			return self::prepare_razorpay_response_data( $data, $payload, $key_id );
		} catch ( Throwable $exception ) {
			self::debug_log( 'Razorpay SDK order creation failed: ' . $exception->getMessage() );

			return self::error_response(
				'razorpay_order_failed',
				__( 'Razorpay order could not be created.', 'gaticrew-events-bridge' ),
				array( 'razorpay' => sanitize_text_field( $exception->getMessage() ) ),
				502
			);
		}
	}

	/**
	 * Creates Razorpay order through the Orders REST API.
	 *
	 * @param array  $payload Razorpay Orders API payload.
	 * @param string $key_id Razorpay key ID.
	 * @param string $key_secret Razorpay key secret.
	 * @return array|WP_Error
	 */
	private static function create_razorpay_order_with_http( array $payload, $key_id, $key_secret ) {
		$response = wp_remote_post(
			'https://api.razorpay.com/v1/orders',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $key_id . ':' . $key_secret ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::debug_log( 'Razorpay HTTP request failed: ' . $response->get_error_message() );
			return self::error_response(
				'razorpay_request_failed',
				__( 'Razorpay order request failed.', 'gaticrew-events-bridge' ),
				array( 'razorpay' => sanitize_text_field( $response->get_error_message() ) ),
				502
			);
		}

		$status_code = absint( wp_remote_retrieve_response_code( $response ) );
		$body        = (string) wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 > $status_code || 300 <= $status_code || ! is_array( $data ) || empty( $data['id'] ) ) {
			self::debug_log( 'Razorpay HTTP order creation failed. Status: ' . $status_code . ' Body: ' . $body );
			return self::error_response(
				'razorpay_order_failed',
				__( 'Razorpay order could not be created.', 'gaticrew-events-bridge' ),
				array( 'razorpay' => self::get_razorpay_error_message( $data, $status_code ) ),
				502
			);
		}

		self::debug_log( 'Razorpay HTTP order created: ' . sanitize_text_field( $data['id'] ) );

		return self::prepare_razorpay_response_data( $data, $payload, $key_id );
	}

	/**
	 * Returns Razorpay config from constants or environment variables.
	 *
	 * @param string $name Config key name.
	 * @return string
	 */
	private static function get_razorpay_config_value( $name ) {
		$name = preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) $name ) );

		if ( defined( $name ) ) {
			return sanitize_text_field( (string) constant( $name ) );
		}

		$value = getenv( $name );

		return false !== $value ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Loads Composer autoload so a future Razorpay SDK install is detected.
	 *
	 * @return bool
	 */
	private static function load_razorpay_sdk() {
		if ( class_exists( 'Razorpay\Api\Api' ) ) {
			return true;
		}

		$autoload = GATICREW_EVENTS_BRIDGE_PATH . 'vendor/autoload.php';

		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}

		return class_exists( 'Razorpay\Api\Api' );
	}

	/**
	 * Normalizes SDK order entities into an array.
	 *
	 * @param mixed $razorpay_order SDK order entity.
	 * @return array
	 */
	private static function normalize_razorpay_order_data( $razorpay_order ) {
		if ( is_object( $razorpay_order ) && method_exists( $razorpay_order, 'toArray' ) ) {
			return (array) $razorpay_order->toArray();
		}

		if ( is_array( $razorpay_order ) ) {
			return $razorpay_order;
		}

		$data = json_decode( wp_json_encode( $razorpay_order ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Prepares frontend-safe Razorpay response data.
	 *
	 * @param array  $data Razorpay response data.
	 * @param array  $payload Razorpay request payload.
	 * @param string $key_id Razorpay key ID.
	 * @return array
	 */
	private static function prepare_razorpay_response_data( array $data, array $payload, $key_id ) {
		return array(
			'key'      => sanitize_text_field( $key_id ),
			'order_id' => sanitize_text_field( $data['id'] ),
			'amount'   => isset( $data['amount'] ) ? absint( $data['amount'] ) : absint( $payload['amount'] ),
			'currency' => isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : sanitize_text_field( $payload['currency'] ),
		);
	}

	/**
	 * Extracts a safe Razorpay error message for API clients.
	 *
	 * @param array|null $data Razorpay response data.
	 * @param int        $status_code HTTP status code.
	 * @return string
	 */
	private static function get_razorpay_error_message( $data, $status_code ) {
		if ( is_array( $data ) && ! empty( $data['error']['description'] ) ) {
			return sanitize_text_field( $data['error']['description'] );
		}

		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Razorpay returned HTTP status %d.', 'gaticrew-events-bridge' ),
			absint( $status_code )
		);
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
			self::debug_log( 'WooCommerce order initialization failed' );
			throw new RuntimeException( __( 'WooCommerce order initialization failed.', 'gaticrew-events-bridge' ) );
		}

		$item_id = $order->add_product( $product, absint( $payload['quantity'] ) );
		if ( ! $item_id ) {
			self::debug_log( 'WooCommerce order item add failed' );
			throw new RuntimeException( __( 'WooCommerce could not add the linked product to the order.', 'gaticrew-events-bridge' ) );
		}

		$item = $order->get_item( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			self::debug_log( 'WooCommerce order item could not be loaded after product add' );
			throw new RuntimeException( __( 'WooCommerce order item could not be loaded.', 'gaticrew-events-bridge' ) );
		}

		$item->update_meta_data( '_gaticrew_booking_id', $payload['booking_id'] );
		$item->update_meta_data( '_gaticrew_attendee_names', self::get_attendee_names( $payload['attendees'] ) );
		$item->save();

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
			self::debug_log( 'Attendees repository missing' );
			throw new RuntimeException( __( 'Attendees repository is not available.', 'gaticrew-events-bridge' ) );
		}

		$repository = new GatiCrew_Events_Bridge_Attendees_Repository();
		$order_id   = absint( $order->get_id() );
		$quantity   = absint( $payload['quantity'] );

		foreach ( $payload['attendees'] as $index => $attendee ) {
			$ticket_index = $index + 1;

			if ( $repository->exists_for_order_booking_ticket_index( $order_id, $payload['booking_id'], $ticket_index ) ) {
				self::debug_log( 'Duplicate attendee skipped for order ' . $order_id . ', booking ' . $payload['booking_id'] . ', ticket index ' . $ticket_index );
				continue;
			}

			$attendee_id = $repository->create(
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

			if ( ! $attendee_id ) {
				self::debug_log( 'Attendee insert failed for order ' . $order_id . ', booking ' . $payload['booking_id'] . ', ticket index ' . $ticket_index );
				throw new RuntimeException( __( 'Attendee record could not be created.', 'gaticrew-events-bridge' ) );
			}
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
			} else {
				self::debug_log( 'Runtime dependency missing: ' . $file );
			}
		}
	}

	/**
	 * Builds a consistent REST error.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param array  $errors Field-level errors.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error_response( $code, $message, array $errors = array(), $status = 400 ) {
		self::debug_log(
			'Create-order returning error: ' . sanitize_key( $code ) . ' - ' . sanitize_text_field( $message )
		);

		return new WP_Error(
			sanitize_key( $code ),
			sanitize_text_field( $message ),
			array(
				'status' => absint( $status ),
				'errors' => $errors,
			)
		);
	}

	/**
	 * Writes temporary create-order diagnostics to PHP error logs.
	 *
	 * TODO: Remove these logs after frontend order failures are isolated.
	 *
	 * @param string $message Debug message.
	 * @return void
	 */
	private static function debug_log( $message ) {
		error_log( '[GatiCrew create-order] ' . (string) $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Temporary API callback debug log.
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}

GatiCrew_Events_Bridge_Create_Order_API::init();
