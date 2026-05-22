<?php
/**
 * Authenticated live check-in REST API.
 *
 * The public QR route remains useful for manual validation, while this
 * controller powers fast operator scanning from the WordPress admin.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Checkin_REST_Controller extends WP_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'gaticrew/v1';

	/**
	 * REST route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'checkin';

	/**
	 * Attendee repository.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new GatiCrew_Events_Bridge_Attendees_Repository();
	}

	/**
	 * Registers REST hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers live validation and approval endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_ticket' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->get_checkin_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve_ticket' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $this->get_checkin_args(),
			)
		);
	}

	/**
	 * Restricts scanner operations to GatiCrew check-in operators.
	 *
	 * @return bool
	 */
	public function permissions_check() {
		return current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_CHECKINS );
	}

	/**
	 * Validates a scanned QR token against the selected event.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function validate_ticket( WP_REST_Request $request ) {
		$token    = $this->sanitize_scanned_value( $request->get_param( 'token' ) );
		$event_id = absint( $request->get_param( 'event_id' ) );

		return rest_ensure_response( $this->build_validation_response( $token, $event_id ) );
	}

	/**
	 * Marks a valid selected-event attendee as checked in.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function approve_ticket( WP_REST_Request $request ) {
		$token      = $this->sanitize_scanned_value( $request->get_param( 'token' ) );
		$event_id   = absint( $request->get_param( 'event_id' ) );
		$validation = $this->build_validation_response( $token, $event_id );

		if ( empty( $validation['can_approve'] ) ) {
			return rest_ensure_response( $validation );
		}

		$attendee = $this->resolve_attendee_from_scan( $token, $event_id );

		if ( empty( $attendee ) || ! is_array( $attendee ) ) {
			return rest_ensure_response( $this->build_state_response( 'invalid_token', null ) );
		}

		$booking_token = ! empty( $attendee['booking_id'] )
			? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] )
			: ( ! empty( $attendee['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] ) : '' );

		$result = $this->repository->mark_checked_in_by_token( $booking_token );

		if ( ! empty( $result['success'] ) ) {
			$this->mark_event_tickets_group_checked_in( $result['group'], $event_id );

			return rest_ensure_response(
				array(
					'success'     => true,
					'state'       => 'approved',
					'code'        => 'checked_in',
					'message'     => __( 'Check-In Approved', 'gaticrew-events-bridge' ),
					'can_approve' => false,
					'attendee'    => $this->format_attendee( $result['attendee'], isset( $result['group'] ) && is_array( $result['group'] ) ? $result['group'] : array() ),
				)
			);
		}

		$code     = isset( $result['code'] ) ? sanitize_key( $result['code'] ) : 'checkin_failed';
		$attendee = isset( $result['attendee'] ) && is_array( $result['attendee'] ) ? $result['attendee'] : null;

		return rest_ensure_response( $this->build_state_response( $code, $attendee ) );
	}

	/**
	 * Shared endpoint arguments.
	 *
	 * @return array
	 */
	private function get_checkin_args() {
		return array(
			'token'    => array(
				'description'       => __( 'Scanned QR token or official Event Tickets QR URL.', 'gaticrew-events-bridge' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_scanned_value' ),
				'validate_callback' => array( $this, 'validate_token_arg' ),
			),
			'event_id' => array(
				'description'       => __( 'Selected event ID for gate validation.', 'gaticrew-events-bridge' ),
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => array( $this, 'validate_event_id_arg' ),
			),
		);
	}

	/**
	 * Validates token input after REST sanitization.
	 *
	 * @param mixed $value Argument value.
	 * @return bool
	 */
	public function validate_token_arg( $value ) {
		return '' !== $this->sanitize_scanned_value( $value );
	}

	/**
	 * Sanitizes a scanner payload while preserving official TEC QR URLs.
	 *
	 * @param mixed $value Raw scanned value.
	 * @return string
	 */
	public function sanitize_scanned_value( $value ) {
		$value = html_entity_decode( wp_unslash( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$value = trim( wp_strip_all_tags( $value ) );

		return substr( $value, 0, 2048 );
	}

	/**
	 * Validates selected event input.
	 *
	 * @param mixed $value Argument value.
	 * @return bool
	 */
	public function validate_event_id_arg( $value ) {
		return absint( $value ) > 0;
	}

	/**
	 * Builds scanner validation state without mutating attendee data.
	 *
	 * @param string $token QR token.
	 * @param int    $event_id Selected event ID.
	 * @return array
	 */
	private function build_validation_response( $token, $event_id ) {
		if ( '' === $token || ! $event_id ) {
			return $this->build_state_response( 'invalid_token', null );
		}

		$attendee = $this->resolve_attendee_from_scan( $token, $event_id );

		if ( empty( $attendee ) || ! is_array( $attendee ) ) {
			return $this->build_state_response( 'invalid_token', null );
		}

		if ( absint( $attendee['event_id'] ) !== $event_id ) {
			return $this->build_state_response( 'wrong_event', null );
		}

		$booking_status = ! empty( $attendee['booking_status'] ) ? sanitize_key( $attendee['booking_status'] ) : '';
		$qr_status      = ! empty( $attendee['qr_status'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_status( $attendee['qr_status'] ) : '';

		if ( GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CANCELLED === $booking_status || GatiCrew_Events_Bridge_QR_Tokens::STATUS_REVOKED === $qr_status ) {
			return $this->build_state_response( 'cancelled', $attendee );
		}

		if ( GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN === $booking_status || GatiCrew_Events_Bridge_QR_Tokens::STATUS_USED === $qr_status ) {
			return $this->build_state_response( 'already_checked_in', $attendee );
		}

		if ( ! empty( $attendee['tec_attendee_post_id'] ) && $this->is_event_tickets_attendee_checked_in( absint( $attendee['tec_attendee_post_id'] ) ) ) {
			$this->repository->mark_checked_in_by_token( ! empty( $attendee['booking_id'] ) ? $attendee['booking_id'] : $attendee['qr_token'] );
			$attendee = ! empty( $attendee['booking_id'] ) ? $this->repository->get_by_qr_token( $attendee['booking_id'] ) : $attendee;

			return $this->build_state_response( 'already_checked_in', $attendee );
		}

		return array(
			'success'     => true,
			'state'       => 'ready',
			'code'        => 'ready',
			'message'     => __( 'Ready for Check-In', 'gaticrew-events-bridge' ),
			'can_approve' => true,
			'attendee'    => $this->format_attendee( $attendee, $this->repository->get_group_by_qr_token( $token ) ),
		);
	}

	/**
	 * Resolves old GatiCrew tokens and official Event Tickets QR URLs.
	 *
	 * @param string $scanned_value Raw scanner payload.
	 * @param int    $selected_event_id Selected scanner event ID.
	 * @return array|null
	 */
	private function resolve_attendee_from_scan( $scanned_value, $selected_event_id ) {
		$tec_payload = $this->parse_event_tickets_qr_payload( $scanned_value );

		if ( ! empty( $tec_payload ) ) {
			return $this->resolve_event_tickets_attendee( $tec_payload, $selected_event_id );
		}

		$token = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $scanned_value );

		return '' !== $token ? $this->repository->get_by_qr_token( $token ) : null;
	}

	/**
	 * Parses official Event Tickets QR URLs into attendee validation fields.
	 *
	 * @param string $scanned_value Raw scanner payload.
	 * @return array
	 */
	private function parse_event_tickets_qr_payload( $scanned_value ) {
		$scanned_value = $this->sanitize_scanned_value( $scanned_value );

		if ( '' === $scanned_value ) {
			return array();
		}

		$query = wp_parse_url( $scanned_value, PHP_URL_QUERY );

		if ( ! is_string( $query ) || '' === $query ) {
			return array();
		}

		$params = array();
		wp_parse_str( $query, $params );

		$has_tec_marker = ! empty( $params['event_qr_code'] ) || ! empty( $params['ticket_id'] ) || ! empty( $params['attendee_id'] );

		if ( ! $has_tec_marker ) {
			return array();
		}

		$attendee_id   = ! empty( $params['ticket_id'] ) ? absint( $params['ticket_id'] ) : ( ! empty( $params['attendee_id'] ) ? absint( $params['attendee_id'] ) : 0 );
		$event_id      = ! empty( $params['event_id'] ) ? absint( $params['event_id'] ) : 0;
		$security_code = ! empty( $params['security_code'] ) ? sanitize_text_field( wp_unslash( $params['security_code'] ) ) : '';

		return array(
			'attendee_id'   => $attendee_id,
			'event_id'      => $event_id,
			'security_code' => $security_code,
		);
	}

	/**
	 * Resolves and validates a real Event Tickets attendee from QR params.
	 *
	 * @param array $payload Parsed TEC QR payload.
	 * @param int   $selected_event_id Selected scanner event ID.
	 * @return array|null
	 */
	private function resolve_event_tickets_attendee( array $payload, $selected_event_id ) {
		$attendee_id   = ! empty( $payload['attendee_id'] ) ? absint( $payload['attendee_id'] ) : 0;
		$qr_event_id   = ! empty( $payload['event_id'] ) ? absint( $payload['event_id'] ) : 0;
		$security_code = ! empty( $payload['security_code'] ) ? sanitize_text_field( $payload['security_code'] ) : '';

		if ( ! $attendee_id || '' === $security_code ) {
			return null;
		}

		if ( $qr_event_id && absint( $selected_event_id ) !== $qr_event_id ) {
			return array( 'event_id' => $qr_event_id, '_wrong_event' => true );
		}

		$post = get_post( $attendee_id );

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->get_event_tickets_attendee_post_types(), true ) || 'trash' === $post->post_status ) {
			return null;
		}

		$stored_security_code = $this->get_event_tickets_security_code( $attendee_id );

		if ( '' === $stored_security_code || ! hash_equals( $stored_security_code, $security_code ) ) {
			return null;
		}

		$tec_event_id = $this->get_event_tickets_attendee_event_id( $attendee_id );

		if ( absint( $selected_event_id ) !== $tec_event_id ) {
			return array( 'event_id' => $tec_event_id, '_wrong_event' => true );
		}

		$attendee = $this->repository->get_by_tec_attendee_post_id( $attendee_id );

		if ( empty( $attendee ) ) {
			$attendee = $this->repository->get_by_tec_security_code( $security_code, $tec_event_id );
		}

		if ( empty( $attendee ) ) {
			$attendee = $this->get_gaticrew_attendee_from_tec_meta( $attendee_id, $tec_event_id, $security_code );
		}

		return $attendee;
	}

	/**
	 * Finds a GatiCrew row from bridge meta stored on the TEC attendee post.
	 *
	 * @param int    $tec_attendee_id Event Tickets attendee post ID.
	 * @param int    $event_id Event post ID.
	 * @param string $security_code Official TEC security code.
	 * @return array|null
	 */
	private function get_gaticrew_attendee_from_tec_meta( $tec_attendee_id, $event_id, $security_code ) {
		$booking_id   = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( get_post_meta( $tec_attendee_id, '_gaticrew_booking_id', true ) );
		$order_id     = absint( get_post_meta( $tec_attendee_id, '_gaticrew_woo_order_id', true ) );
		$ticket_index = absint( get_post_meta( $tec_attendee_id, '_gaticrew_ticket_index', true ) );
		$attendee     = null;

		if ( $order_id && '' !== $booking_id ) {
			$ticket_index = $ticket_index ? $ticket_index : 1;
			$attendee     = $this->repository->get_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index );

			if ( empty( $attendee ) ) {
				$group = $this->repository->get_group_by_order_booking( $order_id, $booking_id );

				if ( ! empty( $group[0] ) ) {
					$attendee = $group[0];
				}
			}
		}

		if ( empty( $attendee ) ) {
			$attendee = $this->repository->get_by_event_identity(
				$event_id,
				$this->get_event_tickets_attendee_name( $tec_attendee_id ),
				$this->get_event_tickets_attendee_email( $tec_attendee_id )
			);

			if ( ! empty( $attendee ) ) {
				$order_id     = ! empty( $attendee['order_id'] ) ? absint( $attendee['order_id'] ) : 0;
				$booking_id   = ! empty( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';
				$ticket_index = ! empty( $attendee['ticket_index'] ) ? max( 1, absint( $attendee['ticket_index'] ) ) : 1;
			}
		}

		if ( ! empty( $attendee ) && is_array( $attendee ) ) {
			$qr_url = $this->build_event_tickets_qr_url( $tec_attendee_id, $event_id, $security_code );

			$this->repository->update_tec_ticket_data(
				$order_id,
				$booking_id,
				$ticket_index,
				array(
					'tec_attendee_post_id' => $tec_attendee_id,
					'tec_security_code'    => $security_code,
					'tec_qr_url'           => $qr_url,
					'tec_qr_image_url'     => $this->build_event_tickets_qr_image_url( $qr_url ),
				)
			);

			$attendee = $this->repository->get_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index );
		}

		return is_array( $attendee ) ? $attendee : null;
	}

	/**
	 * Reads attendee name from official Event Tickets meta.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return string
	 */
	private function get_event_tickets_attendee_name( $attendee_id ) {
		foreach ( array( '_tec_tickets_commerce_full_name', '_tribe_tickets_full_name', '_tribe_wooticket_full_name', '_tribe_rsvp_full_name', '_tribe_tpp_full_name' ) as $key ) {
			$value = get_post_meta( absint( $attendee_id ), $key, true );

			if ( '' !== (string) $value ) {
				return sanitize_text_field( $value );
			}
		}

		$post = get_post( absint( $attendee_id ) );

		return $post instanceof WP_Post ? sanitize_text_field( $post->post_title ) : '';
	}

	/**
	 * Reads attendee email from official Event Tickets meta.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return string
	 */
	private function get_event_tickets_attendee_email( $attendee_id ) {
		foreach ( array( '_tec_tickets_commerce_email', '_tribe_tickets_email', '_tribe_wooticket_email', '_tribe_rsvp_email', '_tribe_tpp_email' ) as $key ) {
			$value = get_post_meta( absint( $attendee_id ), $key, true );

			if ( '' !== (string) $value ) {
				return sanitize_email( $value );
			}
		}

		return '';
	}

	/**
	 * Returns Event Tickets attendee post types supported by the scanner.
	 *
	 * @return array
	 */
	private function get_event_tickets_attendee_post_types() {
		return array(
			'tec_tc_attendee',
			'tribe_wooticket',
			'tribe_rsvp_attendees',
			'tribe_tpp_attendees',
		);
	}

	/**
	 * Reads the official Event Tickets attendee security code.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return string
	 */
	private function get_event_tickets_security_code( $attendee_id ) {
		$provider = $this->get_event_tickets_provider( $attendee_id );
		$keys     = array(
			'_tec_tickets_commerce_security_code',
			'_tribe_wooticket_security_code',
			'_tribe_rsvp_security_code',
			'_tribe_tpp_security_code',
			'_tribe_tickets_security_code',
		);

		if ( is_object( $provider ) && ! empty( $provider->security_code ) ) {
			array_unshift( $keys, sanitize_text_field( $provider->security_code ) );
		}

		foreach ( array_unique( $keys ) as $key ) {
			$value = get_post_meta( absint( $attendee_id ), sanitize_text_field( $key ), true );

			if ( '' !== (string) $value ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}

	/**
	 * Reads the event ID attached to an official Event Tickets attendee.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return int
	 */
	private function get_event_tickets_attendee_event_id( $attendee_id ) {
		$keys = array(
			'_tec_tickets_commerce_event',
			'_tribe_wooticket_event',
			'_tribe_rsvp_event',
			'_tribe_tpp_event',
			'_tribe_tickets_post_id',
		);

		foreach ( $keys as $key ) {
			$value = absint( get_post_meta( absint( $attendee_id ), $key, true ) );

			if ( $value ) {
				return $value;
			}
		}

		return 0;
	}

	/**
	 * Returns the Event Tickets provider for an attendee post.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return object|null
	 */
	private function get_event_tickets_provider( $attendee_id ) {
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
	 * Checks official Event Tickets checked-in state.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @return bool
	 */
	private function is_event_tickets_attendee_checked_in( $attendee_id ) {
		$provider = $this->get_event_tickets_provider( $attendee_id );
		$keys     = array( '_tribe_qr_status', '_tec_tickets_commerce_checked_in', '_tribe_wooticket_checkedin', '_tribe_rsvp_checkedin', '_tribe_tpp_checkedin', '_tribe_tickets_checkedin' );

		if ( is_object( $provider ) && ! empty( $provider->checkin_key ) ) {
			array_unshift( $keys, sanitize_text_field( $provider->checkin_key ) );
		}

		foreach ( array_unique( $keys ) as $key ) {
			$value = get_post_meta( absint( $attendee_id ), sanitize_text_field( $key ), true );

			if ( function_exists( 'tribe_is_truthy' ) ? tribe_is_truthy( $value ) : filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Marks all official TEC attendees in a GatiCrew booking group checked in.
	 *
	 * @param array $group GatiCrew attendee rows.
	 * @param int   $event_id Event post ID.
	 * @return void
	 */
	private function mark_event_tickets_group_checked_in( $group, $event_id ) {
		foreach ( (array) $group as $attendee ) {
			$tec_attendee_id = ! empty( $attendee['tec_attendee_post_id'] ) ? absint( $attendee['tec_attendee_post_id'] ) : 0;

			if ( ! $tec_attendee_id ) {
				continue;
			}

			$this->mark_event_tickets_attendee_checked_in( $tec_attendee_id, $event_id );
		}
	}

	/**
	 * Marks one official Event Tickets attendee checked in using provider logic.
	 *
	 * @param int $attendee_id Event Tickets attendee post ID.
	 * @param int $event_id Event post ID.
	 * @return void
	 */
	private function mark_event_tickets_attendee_checked_in( $attendee_id, $event_id ) {
		$provider = $this->get_event_tickets_provider( $attendee_id );

		if ( is_object( $provider ) && method_exists( $provider, 'checkin' ) ) {
			try {
				$provider->checkin( absint( $attendee_id ), true, absint( $event_id ) );
				return;
			} catch ( Throwable $exception ) {}
		}

		update_post_meta( absint( $attendee_id ), '_tribe_qr_status', 1 );

		if ( 'tec_tc_attendee' === get_post_type( $attendee_id ) ) {
			update_post_meta( absint( $attendee_id ), '_tec_tickets_commerce_checked_in', 1 );
		}
	}

	/**
	 * Builds TEC's official QR check-in URL.
	 *
	 * @param int    $attendee_id Event Tickets attendee post ID.
	 * @param int    $event_id Event post ID.
	 * @param string $security_code Event Tickets security code.
	 * @return string
	 */
	private function build_event_tickets_qr_url( $attendee_id, $event_id, $security_code ) {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\TEC\Tickets\QR\Connector' ) || '' === (string) $security_code ) {
			return '';
		}

		try {
			$connector = tribe( \TEC\Tickets\QR\Connector::class );

			if ( is_object( $connector ) && method_exists( $connector, 'get_checkin_url' ) ) {
				return esc_url_raw( $connector->get_checkin_url( absint( $attendee_id ), absint( $event_id ), sanitize_text_field( $security_code ) ) );
			}
		} catch ( Throwable $exception ) {}

		return '';
	}

	/**
	 * Builds TEC's QR image URL from an official check-in URL.
	 *
	 * @param string $qr_url Event Tickets QR check-in URL.
	 * @return string
	 */
	private function build_event_tickets_qr_image_url( $qr_url ) {
		if ( ! function_exists( 'tribe' ) || ! class_exists( '\TEC\Tickets\QR\Connector' ) || '' === (string) $qr_url ) {
			return '';
		}

		try {
			$connector = tribe( \TEC\Tickets\QR\Connector::class );

			if ( is_object( $connector ) && method_exists( $connector, 'get_image_url_for_link' ) ) {
				$image_url = $connector->get_image_url_for_link( esc_url_raw( $qr_url ) );

				return $image_url ? esc_url_raw( $image_url ) : '';
			}
		} catch ( Throwable $exception ) {}

		return '';
	}

	/**
	 * Maps repository/check-in result codes to scanner UI states.
	 *
	 * @param string     $code Result code.
	 * @param array|null $attendee Attendee row.
	 * @return array
	 */
	private function build_state_response( $code, $attendee ) {
		$code = sanitize_key( $code );

		switch ( $code ) {
			case 'already_checked_in':
				return array(
					'success'     => false,
					'state'       => 'already_used',
					'code'        => $code,
					'message'     => __( 'Already Checked In', 'gaticrew-events-bridge' ),
					'can_approve' => false,
					'attendee'    => $this->format_attendee( $attendee ),
				);
			case 'cancelled':
				return array(
					'success'     => false,
					'state'       => 'cancelled',
					'code'        => $code,
					'message'     => __( 'Ticket Cancelled', 'gaticrew-events-bridge' ),
					'can_approve' => false,
					'attendee'    => $this->format_attendee( $attendee ),
				);
			case 'wrong_event':
			case 'invalid_token':
			default:
				return array(
					'success'     => false,
					'state'       => 'invalid',
					'code'        => $code ? $code : 'invalid_token',
					'message'     => __( 'Invalid Ticket', 'gaticrew-events-bridge' ),
					'can_approve' => false,
					'attendee'    => null,
				);
		}
	}

	/**
	 * Formats attendee rows for scanner output.
	 *
	 * @param array|null $attendee Raw attendee row.
	 * @return array|null
	 */
	private function format_attendee( $attendee, array $group = array() ) {
		if ( empty( $attendee ) || ! is_array( $attendee ) ) {
			return null;
		}

		$event_id       = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
		$booking_status = ! empty( $attendee['booking_status'] ) ? sanitize_key( $attendee['booking_status'] ) : '';
		$statuses       = GatiCrew_Events_Bridge_Attendees_Repository::get_statuses();
		$status_label   = isset( $statuses[ $booking_status ] ) ? $statuses[ $booking_status ] : $booking_status;
		$event_name     = ! empty( $attendee['event_name'] )
			? sanitize_text_field( $attendee['event_name'] )
			: GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
		$group          = empty( $group ) ? $this->get_attendee_group_for_response( $attendee ) : $group;
		$attendees      = array();

		foreach ( $group as $group_attendee ) {
			if ( ! empty( $group_attendee['attendee_names'] ) && is_array( $group_attendee['attendee_names'] ) ) {
				foreach ( $group_attendee['attendee_names'] as $group_name ) {
					$group_name = sanitize_text_field( $group_name );

					if ( '' !== $group_name ) {
						$attendees[] = $group_name;
					}
				}

				continue;
			}

			$name = isset( $group_attendee['attendee_name'] ) ? sanitize_text_field( $group_attendee['attendee_name'] ) : '';

			if ( '' !== $name ) {
				$attendees[] = $name;
			}
		}

		return array(
			'id'                  => isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0,
			'event_id'            => $event_id,
			'attendee_name'       => isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '',
			'attendee_names'      => $attendees,
			'attendee_count'      => max( 1, count( $attendees ) ),
			'booking_id'          => isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '',
			'event_name'          => $event_name,
			'event_date'          => GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ),
			'attendee_status'     => sanitize_text_field( $status_label ),
			'attendee_status_key' => $booking_status,
			'customer_email'      => isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '',
			'ticket_quantity'     => max( isset( $attendee['ticket_quantity'] ) ? absint( $attendee['ticket_quantity'] ) : 1, count( $attendees ) ),
		);
	}

	/**
	 * Returns group rows attached to the attendee QR token.
	 *
	 * @param array $attendee Attendee row.
	 * @return array
	 */
	private function get_attendee_group_for_response( array $attendee ) {
		$token = isset( $attendee['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] ) : '';

		if ( '' === $token ) {
			return array( $attendee );
		}

		$group = $this->repository->get_group_by_qr_token( $token );

		return empty( $group ) ? array( $attendee ) : $group;
	}
}
