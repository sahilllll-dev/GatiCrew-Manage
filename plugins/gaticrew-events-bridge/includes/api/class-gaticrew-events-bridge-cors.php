<?php
/**
 * Shared CORS helpers for public GatiCrew REST endpoints.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_CORS {
	/**
	 * Registers CORS hardening hooks for public GatiCrew REST endpoints.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'disable_core_cors_for_gaticrew_routes' ), 0, 4 );
	}

	/**
	 * Returns allowed frontend origins.
	 *
	 * @return array
	 */
	public static function get_allowed_origins() {
		$origins = array(
			'https://gaticrew.com',
			'http://127.0.0.1:5500',
			'http://localhost:5500',
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
	 * Prevents WordPress core from reflecting arbitrary Origin headers on
	 * GatiCrew REST routes. The plugin sends a stricter allow-list based CORS
	 * header later in each endpoint's response flow.
	 *
	 * @param bool             $served Whether the request has already been served.
	 * @param WP_HTTP_Response $result REST result.
	 * @param WP_REST_Request  $request REST request.
	 * @param WP_REST_Server   $server REST server.
	 * @return bool
	 */
	public static function disable_core_cors_for_gaticrew_routes( $served, $result, $request, $server ) {
		unset( $result, $server );

		if ( $request instanceof WP_REST_Request && 0 === strpos( $request->get_route(), '/gaticrew/v1/' ) ) {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}

		return $served;
	}

	/**
	 * Returns the request origin only when it is explicitly approved.
	 *
	 * @return string
	 */
	public static function get_allowed_request_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : '';

		if ( '' !== $origin && in_array( $origin, self::get_allowed_origins(), true ) ) {
			return $origin;
		}

		return '';
	}

	/**
	 * Sends CORS headers for REST responses.
	 *
	 * @param string $methods Allowed methods header value.
	 * @return void
	 */
	public static function send_headers( $methods ) {
		$origin = self::get_allowed_request_origin();

		if ( '' !== $origin ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Credentials: true' );
		}

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
		$origin = self::get_allowed_request_origin();

		if ( '' !== $origin ) {
			$response->header( 'Access-Control-Allow-Origin', $origin );
			$response->header( 'Access-Control-Allow-Credentials', 'true' );
		}

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

GatiCrew_Events_Bridge_CORS::init();
