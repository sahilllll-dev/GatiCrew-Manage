<?php
/**
 * Shared CORS helpers for public GatiCrew REST endpoints.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_CORS {
	/**
	 * Returns allowed frontend origins.
	 *
	 * @return array
	 */
	public static function get_allowed_origins() {
		$origins = array(
			'https://gaticrew.com',
			'http://127.0.0.1:5500',
		);

		return array_values(
			array_unique(
				array_map(
					'untrailingslashit',
					array_map( 'esc_url_raw', apply_filters( 'gaticrew_events_bridge_allowed_cors_origins', $origins ) )
				)
			)
		);
	}

	/**
	 * Returns the request origin when it is explicitly allowed.
	 *
	 * @return string
	 */
	public static function get_allowed_request_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : '';

		if ( '' !== $origin && in_array( $origin, self::get_allowed_origins(), true ) ) {
			return $origin;
		}

		return 'https://gaticrew.com';
	}

	/**
	 * Sends CORS headers for REST responses.
	 *
	 * @param string $methods Allowed methods header value.
	 * @return void
	 */
	public static function send_headers( $methods ) {
		header( 'Access-Control-Allow-Origin: ' . self::get_allowed_request_origin() );
		header( 'Access-Control-Allow-Methods: ' . sanitize_text_field( $methods ) );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
		header( 'Vary: Origin', false );
	}

	/**
	 * Adds CORS headers to a REST response object.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param string           $methods Allowed methods header value.
	 * @return WP_REST_Response
	 */
	public static function add_response_headers( WP_REST_Response $response, $methods ) {
		$response->header( 'Access-Control-Allow-Origin', self::get_allowed_request_origin() );
		$response->header( 'Access-Control-Allow-Methods', sanitize_text_field( $methods ) );
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce' );
		$response->header( 'Vary', 'Origin' );

		return $response;
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
