<?php
/**
 * Public QR image endpoint.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_QR_Controller {
	/**
	 * Attendee repository.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Repository
	 */
	private $attendees_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->attendees_repository = new GatiCrew_Events_Bridge_Attendees_Repository();
	}

	/**
	 * Registers public QR image handlers.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_gaticrew_ticket_qr', array( $this, 'render_qr_image' ) );
		add_action( 'admin_post_nopriv_gaticrew_ticket_qr', array( $this, 'render_qr_image' ) );
	}

	/**
	 * Renders a local SVG QR image for a valid attendee token.
	 *
	 * @return void
	 */
	public function render_qr_image() {
		$token = isset( $_GET['token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( rawurldecode( wp_unslash( $_GET['token'] ) ) ) : '';

		if ( '' === $token || ! $this->attendees_repository->get_by_qr_token( $token ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$validation_url = GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $token );

		try {
			$svg = GatiCrew_Events_Bridge_QR_Code::render_svg( $validation_url );
		} catch ( Exception $e ) {
			status_header( 500 );
			nocache_headers();
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		nocache_headers();

		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
