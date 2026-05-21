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
		$token    = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $request->get_param( 'token' ) );
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
		$token      = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $request->get_param( 'token' ) );
		$event_id   = absint( $request->get_param( 'event_id' ) );
		$validation = $this->build_validation_response( $token, $event_id );

		if ( empty( $validation['can_approve'] ) ) {
			return rest_ensure_response( $validation );
		}

		$result = $this->repository->mark_checked_in_by_token( $token );

		if ( ! empty( $result['success'] ) ) {
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
				'description'       => __( 'Scanned QR token.', 'gaticrew-events-bridge' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => array( 'GatiCrew_Events_Bridge_QR_Tokens', 'sanitize_token' ),
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
		return '' !== GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $value );
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

		$attendee = $this->repository->get_by_qr_token( $token );

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
