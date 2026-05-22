<?php
/**
 * Premium GatiCrew login screen.
 *
 * This module only changes the WordPress login presentation layer. The native
 * login form, nonce handling, password reset, remember-me, and authentication
 * flow remain controlled by WordPress core.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Login_UI {
	/**
	 * Registers login-screen hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'login_body_class', array( $this, 'add_login_body_class' ), 10, 2 );
		add_filter( 'login_headerurl', array( $this, 'filter_login_header_url' ) );
		add_filter( 'login_headertext', array( $this, 'filter_login_header_text' ) );
		add_filter( 'login_title', array( $this, 'filter_login_title' ), 10, 2 );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'login_header', array( $this, 'render_visual_panel' ) );
	}

	/**
	 * Adds a scoped class for the redesigned login shell.
	 *
	 * @param array  $classes Login body classes.
	 * @param string $action Login action.
	 * @return array
	 */
	public function add_login_body_class( $classes, $action ) {
		$classes[] = 'gaticrew-login-shell';
		$classes[] = 'gaticrew-login-action-' . sanitize_html_class( $action );

		return $classes;
	}

	/**
	 * Loads the login design system stylesheet.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$asset_path = GATICREW_EVENTS_BRIDGE_PATH . 'assets/css/login.css';
		$version    = file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : GATICREW_EVENTS_BRIDGE_VERSION;

		wp_enqueue_style(
			'gaticrew-events-bridge-login',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/css/login.css',
			array( 'login' ),
			$version
		);
	}

	/**
	 * Sends the text logo to the public website.
	 *
	 * @return string
	 */
	public function filter_login_header_url() {
		return home_url( '/' );
	}

	/**
	 * Replaces the WordPress logo text with the GatiCrew brand.
	 *
	 * @return string
	 */
	public function filter_login_header_text() {
		return __( 'GatiCrew', 'gaticrew-events-bridge' );
	}

	/**
	 * Gives the browser tab a platform-specific title.
	 *
	 * @param string $login_title Filtered login title.
	 * @param string $title Original page title.
	 * @return string
	 */
	public function filter_login_title( $login_title, $title ) {
		unset( $login_title );

		$title = $title ? wp_strip_all_tags( $title ) : __( 'Log In', 'gaticrew-events-bridge' );

		return sprintf(
			/* translators: %s: Login screen title. */
			__( '%s - GatiCrew Operations', 'gaticrew-events-bridge' ),
			$title
		);
	}

	/**
	 * Renders the left-side marketing panel.
	 *
	 * The panel is intentionally outside #login so CSS can create a real split
	 * layout while WordPress keeps owning the authentication form markup.
	 *
	 * @return void
	 */
	public function render_visual_panel() {
		if ( isset( $_REQUEST['interim-login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		?>
		<section class="gaticrew-login-visual" aria-label="<?php echo esc_attr__( 'GatiCrew operations platform', 'gaticrew-events-bridge' ); ?>">
			<a class="gaticrew-login-back" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span aria-hidden="true">&larr;</span>
				<?php echo esc_html__( 'Back to website', 'gaticrew-events-bridge' ); ?>
			</a>

			<div class="gaticrew-login-ambient" aria-hidden="true">
				<span class="gaticrew-login-ambient__ring gaticrew-login-ambient__ring--one"></span>
				<span class="gaticrew-login-ambient__ring gaticrew-login-ambient__ring--two"></span>
				<span class="gaticrew-login-ambient__beam"></span>
			</div>

			<div class="gaticrew-login-visual__content">
				<span class="gaticrew-login-eyebrow"><?php echo esc_html__( 'GatiCrew Operations', 'gaticrew-events-bridge' ); ?></span>
				<h2><?php echo wp_kses_post( __( 'Run events smarter.<br>Manage tickets, check-ins & operations in one place.', 'gaticrew-events-bridge' ) ); ?></h2>
				<p><?php echo esc_html__( 'A focused command center for ride crews, event teams, ticketing staff, and gate operators.', 'gaticrew-events-bridge' ); ?></p>
			</div>
		</section>
		<?php
	}
}
