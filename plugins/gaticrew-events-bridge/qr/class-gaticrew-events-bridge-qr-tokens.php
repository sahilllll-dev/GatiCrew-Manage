<?php
/**
 * QR token helpers.
 *
 * Tokens are treated as bearer credentials for check-in validation, so this
 * class centralizes generation, sanitization, and public URL construction.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_QR_Tokens {
	/**
	 * Public token prefix.
	 */
	const TOKEN_PREFIX = 'GCQR-';

	/**
	 * QR lifecycle statuses.
	 */
	const STATUS_ACTIVE  = 'active';
	const STATUS_USED    = 'used';
	const STATUS_REVOKED = 'revoked';

	/**
	 * Returns allowed QR statuses.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return array(
			self::STATUS_ACTIVE  => __( 'Active', 'gaticrew-events-bridge' ),
			self::STATUS_USED    => __( 'Used', 'gaticrew-events-bridge' ),
			self::STATUS_REVOKED => __( 'Revoked', 'gaticrew-events-bridge' ),
		);
	}

	/**
	 * Generates a secure random token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$length   = 12;
		$token    = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$token .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return self::TOKEN_PREFIX . $token;
	}

	/**
	 * Sanitizes and validates a QR token, booking ID, or attendee row ID.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function sanitize_token( $token ) {
		$token = strtoupper( sanitize_text_field( (string) $token ) );
		$token = preg_replace( '/[^A-Z0-9-]/', '', $token );

		if ( ! is_string( $token ) || ! preg_match( '/^(GCQR-[A-Z0-9]{8,32}|GC-[0-9]{4}-[A-Z0-9]{4,32}|[0-9]{1,20})$/', $token ) ) {
			return '';
		}

		return $token;
	}

	/**
	 * Sanitizes QR status values.
	 *
	 * @param string $status Raw QR status.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$status = sanitize_key( (string) $status );

		return array_key_exists( $status, self::get_statuses() ) ? $status : '';
	}

	/**
	 * Returns the public check-in validation URL encoded into the QR code.
	 *
	 * @param string $token QR token.
	 * @return string
	 */
	public static function get_validation_url( $token ) {
		$token = self::sanitize_token( $token );

		if ( '' === $token ) {
			return '';
		}

		$base_url = apply_filters( 'gaticrew_events_bridge_checkin_base_url', 'https://manage.gaticrew.com/checkin' );
		$base_url = untrailingslashit( esc_url_raw( $base_url ) );

		if ( '' === $base_url ) {
			$base_url = untrailingslashit( home_url( '/checkin' ) );
		}

		return $base_url . '/' . rawurlencode( $token );
	}

	/**
	 * Returns a local SVG endpoint URL for rendering the ticket QR image.
	 *
	 * @param string $token QR token.
	 * @return string
	 */
	public static function get_qr_image_url( $token ) {
		$token = self::sanitize_token( $token );

		if ( '' === $token ) {
			return '';
		}

		return add_query_arg(
			array(
				'action' => 'gaticrew_ticket_qr',
				'token'  => rawurlencode( $token ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
