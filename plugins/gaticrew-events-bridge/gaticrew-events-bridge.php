<?php
/**
 * Plugin Name: GatiCrew Events Bridge
 * Description: Links The Events Calendar events to WooCommerce products for lightweight ticket sales.
 * Version: 2.0.0
 * Author: GatiCrew
 * Text Domain: gaticrew-events-bridge
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

define( 'GATICREW_EVENTS_BRIDGE_VERSION', '2.0.0' );
define( 'GATICREW_EVENTS_BRIDGE_FILE', __FILE__ );
define( 'GATICREW_EVENTS_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GATICREW_EVENTS_BRIDGE_URL', plugin_dir_url( __FILE__ ) );
define( 'GATICREW_EVENTS_BRIDGE_BASENAME', plugin_basename( __FILE__ ) );

require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-dependencies.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-bookings.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-events.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-products.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/api/events-api.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'database/class-gaticrew-events-bridge-schema.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'roles/class-gaticrew-events-bridge-role-manager.php';

register_activation_hook(
	__FILE__,
	array( 'GatiCrew_Events_Bridge_Dependencies', 'activate' )
);

/**
 * Removes plugin rewrite rules from WordPress' cached rule set.
 *
 * @return void
 */
function gaticrew_events_bridge_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'gaticrew_events_bridge_deactivate' );

/**
 * Boots the plugin only after WordPress has loaded active plugins.
 */
function gaticrew_events_bridge_bootstrap() {
	if ( ! GatiCrew_Events_Bridge_Dependencies::requirements_met() ) {
		add_action( 'admin_notices', array( 'GatiCrew_Events_Bridge_Dependencies', 'render_missing_plugins_notice' ) );
		return;
	}

	require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge.php';

	GatiCrew_Events_Bridge::instance()->init();
}
add_action( 'plugins_loaded', 'gaticrew_events_bridge_bootstrap', 20 );
