<?php
/**
 * Full WooCommerce thank-you template replacement for GatiCrew event orders.
 *
 * @package GatiCrew_Events_Bridge
 *
 * @var WC_Order $order WooCommerce order object passed by WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order instanceof WC_Order ) {
	return;
}

GatiCrew_Events_Bridge::instance()->get_order_manager()->render_custom_thankyou_template( $order );
