<?php
/**
 * WooCommerce product helper methods.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Products {
	/**
	 * Validates that a product ID points to a real WooCommerce product.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool
	 */
	public static function is_valid_product_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		$post_type = get_post_type( $product_id );

		return in_array( $post_type, array( 'product', 'product_variation' ), true ) && 'trash' !== get_post_status( $product_id );
	}

	/**
	 * Returns product API data without leaking unpublished product details.
	 *
	 * @param int $product_id Product post ID.
	 * @return array
	 */
	public static function get_public_product_data( $product_id ) {
		$product_id = absint( $product_id );

		$data = array(
			'linked_woocommerce_product_id' => $product_id ? $product_id : null,
			'product_name'                  => null,
			'product_price'                 => null,
			'product_permalink'             => null,
			'product_stock_quantity'        => null,
		);

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return $data;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'trash' === get_post_status( $product_id ) ) {
			return $data;
		}

		$stock_quantity = $product->get_stock_quantity();

		$data['product_name']           = wp_strip_all_tags( $product->get_name() );
		$data['product_price']          = wc_format_decimal( $product->get_price(), wc_get_price_decimals() );
		$data['product_permalink']      = get_permalink( $product_id );
		$data['product_stock_quantity'] = null === $stock_quantity ? null : (int) $stock_quantity;

		return $data;
	}

	/**
	 * Builds a compact selected option label for the admin product dropdown.
	 *
	 * @param int $product_id Product post ID.
	 * @return string
	 */
	public static function get_admin_product_label( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return '';
		}

		return sprintf(
			/* translators: 1: product ID, 2: product name. */
			__( '#%1$d - %2$s', 'gaticrew-events-bridge' ),
			$product_id,
			wp_strip_all_tags( $product->get_name() )
		);
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
