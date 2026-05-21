<?php
/**
 * Admin-area restrictions for the GatiCrew Manager role.
 *
 * Menu cleanup improves usability, while admin_init routing provides the
 * server-side protection that prevents direct URL access to blocked screens.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Admin_Permissions {
	/**
	 * Registers admin restriction hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'login_redirect', array( $this, 'redirect_manager_login_to_dashboard' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'restrict_admin_menu' ), 999 );
		add_action( 'admin_page_access_denied', array( $this, 'redirect_denied_admin_page' ), 0 );
		add_action( 'admin_init', array( $this, 'redirect_disallowed_admin_pages' ), 1 );
		add_action( 'wp_dashboard_setup', array( $this, 'cleanup_dashboard_widgets' ), 999 );
		add_action( 'admin_bar_menu', array( $this, 'cleanup_admin_bar' ), 999 );
	}

	/**
	 * Sends GatiCrew Managers to Dashboard after login.
	 *
	 * WordPress redirects users without the post-editing capability to
	 * profile.php during default wp-admin logins. This role intentionally does
	 * not store post capabilities, so we choose the dashboard target before core
	 * applies that profile-only fallback.
	 *
	 * @param string           $redirect_to Requested redirect URL.
	 * @param string           $requested_redirect_to Original requested redirect URL.
	 * @param WP_User|WP_Error $user Authenticated user or error.
	 * @return string
	 */
	public function redirect_manager_login_to_dashboard( $redirect_to, $requested_redirect_to, $user ) {
		unset( $requested_redirect_to );

		if ( ! $user instanceof WP_User || empty( $user->roles ) ) {
			return $redirect_to;
		}

		$roles = (array) $user->roles;

		if ( in_array( GatiCrew_Events_Bridge_Role_Manager::ROLE_KEY, $roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
			return admin_url( 'index.php' );
		}

		return $redirect_to;
	}

	/**
	 * Removes unrelated menus for GatiCrew Managers.
	 *
	 * @return void
	 */
	public function restrict_admin_menu() {
		if ( ! GatiCrew_Events_Bridge_Role_Manager::current_user_is_gaticrew_manager() ) {
			return;
		}

		global $menu, $submenu;

		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';

			if ( '' !== $slug && ! $this->is_allowed_menu_slug( $slug ) ) {
				remove_menu_page( $slug );
			}
		}

		foreach ( (array) $submenu as $parent_slug => $items ) {
			if ( ! $this->is_allowed_menu_slug( $parent_slug ) ) {
				unset( $submenu[ $parent_slug ] );
				continue;
			}

			foreach ( (array) $items as $index => $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';

				if ( ! $this->is_allowed_submenu_slug( $parent_slug, $slug ) ) {
					unset( $submenu[ $parent_slug ][ $index ] );
				}
			}
		}
	}

	/**
	 * Redirects direct admin URL access away from blocked screens.
	 *
	 * @return void
	 */
	public function redirect_disallowed_admin_pages() {
		if ( ! GatiCrew_Events_Bridge_Role_Manager::current_user_is_gaticrew_manager() ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$pagenow = $this->get_current_admin_file();

		if ( $this->is_allowed_admin_request( $pagenow ) ) {
			return;
		}

		$this->redirect_to_admin_root();
	}

	/**
	 * Redirects pages denied by WordPress' menu capability check.
	 *
	 * Core performs this check before admin_init. This hook keeps blocked core
	 * pages from becoming 403 screens while avoiding loops by only redirecting
	 * requests that are not in our explicit allowlist.
	 *
	 * @return void
	 */
	public function redirect_denied_admin_page() {
		if ( ! GatiCrew_Events_Bridge_Role_Manager::current_user_is_gaticrew_manager() ) {
			return;
		}

		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$pagenow = $this->get_current_admin_file();

		if ( $this->is_allowed_admin_request( $pagenow ) ) {
			return;
		}

		$this->redirect_to_admin_root();
	}

	/**
	 * Sends blocked requests to the admin root once.
	 *
	 * The target resolves to the Dashboard, which is explicitly allowed for this
	 * role. If the current request is already the admin root/dashboard, no
	 * redirect is issued.
	 *
	 * @return void
	 */
	private function redirect_to_admin_root() {
		if ( $this->is_admin_root_request() ) {
			return;
		}

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Removes noisy dashboard widgets and adds an operations shortcut panel.
	 *
	 * @return void
	 */
	public function cleanup_dashboard_widgets() {
		if ( ! GatiCrew_Events_Bridge_Role_Manager::current_user_is_gaticrew_manager() ) {
			return;
		}

		remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
		remove_meta_box( 'woocommerce_dashboard_status', 'dashboard', 'normal' );
		remove_meta_box( 'woocommerce_dashboard_recent_reviews', 'dashboard', 'normal' );
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		wp_add_dashboard_widget(
			'gaticrew_manager_dashboard',
			__( 'GatiCrew Operations', 'gaticrew-events-bridge' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Removes admin-bar entries that point to blocked areas.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public function cleanup_admin_bar( $admin_bar ) {
		if ( ! GatiCrew_Events_Bridge_Role_Manager::current_user_is_gaticrew_manager() ) {
			return;
		}

		foreach ( array( 'wp-logo', 'comments', 'updates', 'customize', 'themes', 'menus' ) as $node_id ) {
			$admin_bar->remove_node( $node_id );
		}

		$admin_bar->remove_node( 'new-post' );
		$admin_bar->remove_node( 'new-page' );
		$admin_bar->remove_node( 'new-media' );
	}

	/**
	 * Renders the simplified dashboard shortcut widget.
	 *
	 * @return void
	 */
	public function render_dashboard_widget() {
		$links = array(
			array(
				'label' => __( 'Events', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'edit.php?post_type=tribe_events' ),
			),
			array(
				'label' => __( 'Products', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'edit.php?post_type=product' ),
			),
			array(
				'label' => __( 'Orders', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=wc-orders' ),
			),
			array(
				'label' => __( 'GatiCrew Attendees', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=' . GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG ),
			),
			array(
				'label' => __( 'GatiCrew Check-In', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=' . GatiCrew_Events_Bridge_Scanner_Admin::MENU_SLUG ),
			),
		);

		echo '<p>' . esc_html__( 'Use these shortcuts for event operations.', 'gaticrew-events-bridge' ) . '</p>';
		echo '<ul class="gaticrew-manager-dashboard-links">';

		foreach ( $links as $link ) {
			printf(
				'<li><a class="button" href="%1$s">%2$s</a></li>',
				esc_url( $link['url'] ),
				esc_html( $link['label'] )
			);
		}

		echo '</ul>';
	}

	/**
	 * Checks top-level admin menu slugs.
	 *
	 * @param string $slug Menu slug.
	 * @return bool
	 */
	private function is_allowed_menu_slug( $slug ) {
		$allowed = array(
			'index.php',
			'profile.php',
			'edit.php?post_type=tribe_events',
			'edit.php?post_type=product',
			'edit.php?post_type=shop_order',
			'wc-orders',
			'woocommerce',
			GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG,
			GatiCrew_Events_Bridge_Scanner_Admin::MENU_SLUG,
			'gaticrew-check-in',
		);

		return in_array( $slug, $allowed, true ) || $this->is_orders_admin_slug( $slug ) || $this->is_ticket_admin_slug( $slug );
	}

	/**
	 * Checks allowed submenu slugs under permitted top-level menus.
	 *
	 * @param string $parent_slug Parent menu slug.
	 * @param string $slug Submenu slug.
	 * @return bool
	 */
	private function is_allowed_submenu_slug( $parent_slug, $slug ) {
		if ( 'profile.php' === $parent_slug ) {
			return 'profile.php' === $slug;
		}

		if ( 'woocommerce' === $parent_slug ) {
			return $this->is_orders_admin_slug( $slug );
		}

		if ( 'edit.php?post_type=product' === $parent_slug ) {
			return in_array(
				$slug,
				array(
					'edit.php?post_type=product',
					'post-new.php?post_type=product',
					'edit-tags.php?taxonomy=product_cat&post_type=product',
					'edit-tags.php?taxonomy=product_tag&post_type=product',
					'product_attributes',
				),
				true
			);
		}

		if ( 'edit.php?post_type=tribe_events' === $parent_slug ) {
			return in_array(
				$slug,
				array(
					'edit.php?post_type=tribe_events',
					'post-new.php?post_type=tribe_events',
					'edit-tags.php?taxonomy=tribe_events_cat&post_type=tribe_events',
					'edit.php?post_type=tribe_venue',
					'post-new.php?post_type=tribe_venue',
					'edit.php?post_type=tribe_organizer',
					'post-new.php?post_type=tribe_organizer',
				),
				true
			) || $this->is_ticket_admin_slug( $slug );
		}

		return in_array( $parent_slug, array( GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG, GatiCrew_Events_Bridge_Scanner_Admin::MENU_SLUG, 'gaticrew-check-in' ), true );
	}

	/**
	 * Allows only the narrow set of admin screens needed by event operators.
	 *
	 * @param string $pagenow Current admin PHP file.
	 * @return bool
	 */
	private function is_allowed_admin_request( $pagenow ) {
		// Core admin service endpoints must never be redirected, otherwise AJAX,
		// REST-backed screens, asset loading, or profile saves can loop.
		if ( in_array( $pagenow, array( 'index.php', 'profile.php', 'admin-ajax.php', 'async-upload.php', 'load-scripts.php', 'load-styles.php' ), true ) ) {
			return true;
		}

		if ( 'admin-post.php' === $pagenow ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			return in_array( $action, array( 'gaticrew_ticket_pdf', 'gaticrew_ticket_qr' ), true );
		}

		if ( in_array( $pagenow, array( 'edit.php', 'post-new.php' ), true ) ) {
			$post_type = $this->get_request_post_type();
			$page      = $this->get_request_page();

			if ( '' !== $page ) {
				return $this->is_allowed_admin_page( $page, $post_type );
			}

			return $this->is_allowed_post_type_route( $post_type, 'post-new.php' === $pagenow );
		}

		if ( 'post.php' === $pagenow ) {
			$post_type = $this->get_request_post_type_from_post_screen();
			return $this->is_allowed_post_type_route( $post_type, false );
		}

		if ( in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true ) ) {
			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
			return in_array( $taxonomy, array( 'product_cat', 'product_tag', 'tribe_events_cat' ), true );
		}

		if ( 'admin.php' === $pagenow ) {
			return $this->is_allowed_admin_page( $this->get_request_page(), '' );
		}

		return false;
	}

	/**
	 * Checks allowed admin.php or edit.php?page=... plugin routes.
	 *
	 * This deliberately whitelists route names first. WordPress and third-party
	 * plugins can resolve capabilities at different times during admin bootstrap,
	 * so route gating here should not reject a known-allowed screen just because
	 * another plugin has not fully initialized its screen object yet.
	 *
	 * @param string $page Admin page slug.
	 * @param string $post_type Optional post type context.
	 * @return bool
	 */
	private function is_allowed_admin_page( $page, $post_type = '' ) {
		if ( '' === $page ) {
			return false;
		}

		if ( GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG === $page ) {
			return current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES );
		}

		if ( in_array( $page, array( GatiCrew_Events_Bridge_Scanner_Admin::MENU_SLUG, 'gaticrew-check-in' ), true ) ) {
			return current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_CHECKINS );
		}

		if ( 'product_attributes' === $page && ( '' === $post_type || 'product' === $post_type ) ) {
			return current_user_can( 'manage_product_terms' );
		}

		if ( $this->is_orders_admin_slug( $page ) ) {
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			return ! in_array( $action, array( 'new', 'create' ), true );
		}

		if ( 'wc-admin' === $page ) {
			$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
			return 0 === strpos( $path, '/orders' ) && 0 !== strpos( $path, '/orders/new' );
		}

		return $this->is_ticket_admin_slug( $page );
	}

	/**
	 * Checks allowed post type admin routes.
	 *
	 * @param string $post_type Requested post type.
	 * @param bool   $is_creation Whether this is a new-item route.
	 * @return bool
	 */
	private function is_allowed_post_type_route( $post_type, $is_creation ) {
		if ( '' === $post_type ) {
			return false;
		}

		$post_type = sanitize_key( $post_type );

		if ( $this->is_ticket_post_type( $post_type ) ) {
			return current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_TICKETS );
		}

		$allowed_post_types = $is_creation
			? $this->get_allowed_post_types_for_creation()
			: $this->get_allowed_post_types_for_lists();

		return in_array( $post_type, $allowed_post_types, true );
	}

	/**
	 * Returns the post type from list/new requests.
	 *
	 * @return string
	 */
	private function get_request_post_type() {
		return isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
	}

	/**
	 * Returns the requested admin page slug.
	 *
	 * @return string
	 */
	private function get_request_page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	}

	/**
	 * Returns the current admin filename without stripping the .php extension.
	 *
	 * sanitize_key() removes dots, which turns edit.php into editphp and causes
	 * every valid WordPress admin screen to miss the whitelist. WordPress owns
	 * $pagenow, so keeping a tight filename character allowlist is sufficient.
	 *
	 * @return string
	 */
	private function get_current_admin_file() {
		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$pagenow = strtolower( basename( $pagenow ) );

		return preg_replace( '/[^a-z0-9_.-]/', '', $pagenow );
	}

	/**
	 * Resolves the post type for edit screens.
	 *
	 * @return string
	 */
	private function get_request_post_type_from_post_screen() {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

		if ( $post_id ) {
			$post_type = get_post_type( $post_id );
			return $post_type ? sanitize_key( $post_type ) : '';
		}

		return $this->get_request_post_type();
	}

	/**
	 * Post types exposed in list and new screens.
	 *
	 * @return array
	 */
	private function get_allowed_post_types_for_lists() {
		return array( 'tribe_events', 'tribe_venue', 'tribe_organizer', 'product', 'shop_order', 'tec_tc_order' );
	}

	/**
	 * Post types exposed in new item screens.
	 *
	 * @return array
	 */
	private function get_allowed_post_types_for_creation() {
		return array( 'tribe_events', 'tribe_venue', 'tribe_organizer', 'product' );
	}

	/**
	 * Detects WooCommerce orders screens across classic and HPOS admin routes.
	 *
	 * @param string $slug Menu/page slug.
	 * @return bool
	 */
	private function is_orders_admin_slug( $slug ) {
		$slug = rawurldecode( (string) $slug );

		return in_array( $slug, array( 'wc-orders', 'edit.php?post_type=shop_order' ), true )
			|| 0 === strpos( $slug, 'wc-orders--' )
			|| ( 0 === strpos( $slug, 'wc-admin' ) && false !== strpos( $slug, '/orders' ) && false === strpos( $slug, '/orders/new' ) );
	}

	/**
	 * Allows ticket-specific plugin screens while excluding settings-style pages.
	 *
	 * @param string $slug Menu/page slug.
	 * @return bool
	 */
	private function is_ticket_admin_slug( $slug ) {
		$slug = strtolower( (string) $slug );

		if ( '' === $slug || false === strpos( $slug, 'ticket' ) ) {
			return false;
		}

		foreach ( array( 'settings', 'addon', 'license', 'help', 'tools' ) as $blocked_fragment ) {
			if ( false !== strpos( $slug, $blocked_fragment ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Detects ticket post type routes used by Event Tickets providers.
	 *
	 * Some ticket screens are registered as post type routes instead of
	 * admin.php?page=... routes. Keep this narrowly scoped to ticket/attendee
	 * commerce objects so normal posts, pages, media, and comments remain blocked.
	 *
	 * @param string $post_type Requested post type.
	 * @return bool
	 */
	private function is_ticket_post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );

		$known_ticket_post_types = array(
			'tec_tc_ticket',
			'tec_tc_attendee',
			'tribe_rsvp_tickets',
			'tribe_rsvp_attendees',
			'tribe_tpp_tickets',
			'tribe_tpp_attendees',
		);

		if ( in_array( $post_type, $known_ticket_post_types, true ) ) {
			return true;
		}

		return ( false !== strpos( $post_type, 'ticket' ) || false !== strpos( $post_type, 'attendee' ) )
			&& false === strpos( $post_type, 'settings' );
	}

	/**
	 * Detects the Dashboard/admin-root request to prevent redirect loops.
	 *
	 * @return bool
	 */
	private function is_admin_root_request() {
		$pagenow = $this->get_current_admin_file();

		if ( 'index.php' === $pagenow ) {
			return true;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$admin_path  = wp_parse_url( admin_url(), PHP_URL_PATH );

		if ( ! $request_uri || ! $admin_path ) {
			return false;
		}

		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! $request_path ) {
			return false;
		}

		return trailingslashit( $request_path ) === trailingslashit( $admin_path );
	}
}
