<?php
/**
 * Dependency and activation safety checks.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Dependencies {
	/**
	 * Required plugin basenames and user-facing labels.
	 *
	 * @return array
	 */
	public static function required_plugins() {
		return array(
			'woocommerce/woocommerce.php'                 => __( 'WooCommerce', 'gaticrew-events-bridge' ),
			'the-events-calendar/the-events-calendar.php' => __( 'The Events Calendar', 'gaticrew-events-bridge' ),
		);
	}

	/**
	 * Runs on plugin activation and blocks activation if dependencies are absent.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( self::requirements_met() ) {
			GatiCrew_Events_Bridge_Schema::create_tables();
			GatiCrew_Events_Bridge_Role_Manager::sync_role();
			require_once GATICREW_EVENTS_BRIDGE_PATH . 'checkin/class-gaticrew-events-bridge-checkin-controller.php';
			require_once GATICREW_EVENTS_BRIDGE_PATH . 'public/class-gaticrew-events-bridge-login-router.php';
			GatiCrew_Events_Bridge_Checkin_Controller::register_rewrite_rules();
			GatiCrew_Events_Bridge_Login_Router::register_rewrite_rules();
			flush_rewrite_rules();
			return;
		}

		deactivate_plugins( GATICREW_EVENTS_BRIDGE_BASENAME );

		$message = sprintf(
			/* translators: %s is a comma-separated list of plugin names. */
			__( 'GatiCrew Events Bridge requires the following active plugins: %s.', 'gaticrew-events-bridge' ),
			esc_html( implode( ', ', self::missing_plugin_names() ) )
		);

		wp_die(
			wp_kses_post( $message ),
			esc_html__( 'Plugin activation failed', 'gaticrew-events-bridge' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Determines whether all required plugins are active.
	 *
	 * @return bool
	 */
	public static function requirements_met() {
		return empty( self::missing_plugin_names() );
	}

	/**
	 * Builds the list of missing dependency labels.
	 *
	 * @return array
	 */
	public static function missing_plugin_names() {
		$missing = array();

		foreach ( self::required_plugins() as $basename => $label ) {
			if ( ! self::is_dependency_active( $basename ) ) {
				$missing[] = $label;
			}
		}

		return $missing;
	}

	/**
	 * Renders an admin notice when a dependency is deactivated after this plugin.
	 *
	 * @return void
	 */
	public static function render_missing_plugins_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = self::missing_plugin_names();

		if ( empty( $missing ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'GatiCrew Events Bridge is inactive.', 'gaticrew-events-bridge' ); ?></strong>
				<?php
				printf(
					/* translators: %s is a comma-separated list of plugin names. */
					esc_html__( 'Please activate: %s.', 'gaticrew-events-bridge' ),
					esc_html( implode( ', ', $missing ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Checks plugin activation using WordPress APIs and loaded plugin classes.
	 *
	 * @param string $basename Plugin basename.
	 * @return bool
	 */
	private static function is_dependency_active( $basename ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$is_active = is_plugin_active( $basename );

		if ( function_exists( 'is_plugin_active_for_network' ) ) {
			$is_active = $is_active || is_plugin_active_for_network( $basename );
		}

		if ( 'woocommerce/woocommerce.php' === $basename ) {
			return $is_active || class_exists( 'WooCommerce' );
		}

		if ( 'the-events-calendar/the-events-calendar.php' === $basename ) {
			return $is_active || defined( 'TRIBE_EVENTS_FILE' ) || class_exists( 'Tribe__Events__Main' );
		}

		return $is_active;
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
