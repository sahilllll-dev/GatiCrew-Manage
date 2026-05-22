<?php
/**
 * Global GatiCrew admin design system.
 *
 * This module skins wp-admin as a modern operations dashboard while preserving
 * WordPress screens, hooks, actions, and plugin compatibility. It intentionally
 * works through body classes, enqueue hooks, dashboard widgets, and progressive
 * JS enhancement instead of modifying WordPress core files.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Admin_UI {
	/**
	 * Registers admin UI hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'admin_body_class', array( $this, 'add_admin_body_classes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 1 );
		add_action( 'admin_init', array( $this, 'suppress_event_tickets_plus_commerce_notice' ), 100 );
		add_action( 'admin_notices', array( $this, 'suppress_event_tickets_plus_commerce_notice' ), 0 );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widgets' ), 40 );
		add_action( 'wp_dashboard_setup', array( $this, 'keep_command_center_dashboard_only' ), 999 );
		add_action( 'admin_bar_menu', array( $this, 'cleanup_admin_bar' ), 999 );
		add_filter( 'admin_footer_text', array( $this, 'filter_footer_text' ) );
		add_filter( 'get_user_option_metaboxhidden_dashboard', array( $this, 'keep_command_center_visible' ) );
		add_filter( 'get_user_option_meta-box-order_dashboard', array( $this, 'keep_command_center_in_main_column' ) );
	}

	/**
	 * Adds a scoped class so the SaaS skin can be disabled or narrowed later.
	 *
	 * @param string $classes Existing classes.
	 * @return string
	 */
	public function add_admin_body_classes( $classes ) {
		$current_user = wp_get_current_user();
		$role         = ! empty( $current_user->roles[0] ) ? sanitize_html_class( $current_user->roles[0] ) : 'operator';

		$classes .= ' gaticrew-admin-shell gaticrew-role-' . $role;

		if ( $this->is_gaticrew_screen() ) {
			$classes .= ' gaticrew-admin-screen';
		}

		return $classes;
	}

	/**
	 * Loads global admin design-system assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'gaticrew-events-bridge-admin-dashboard',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/css/admin-dashboard.css',
			array(),
			GATICREW_EVENTS_BRIDGE_VERSION
		);

		wp_enqueue_script(
			'gaticrew-events-bridge-admin-dashboard',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/js/admin-dashboard.js',
			array(),
			GATICREW_EVENTS_BRIDGE_VERSION,
			true
		);

		$user = wp_get_current_user();

		wp_localize_script(
			'gaticrew-events-bridge-admin-dashboard',
			'GatiCrewAdminUI',
			array(
				'brand'       => __( 'GatiCrew', 'gaticrew-events-bridge' ),
				'product'     => __( 'Operations Platform', 'gaticrew-events-bridge' ),
				'displayName' => $user instanceof WP_User && $user->exists() ? $user->display_name : __( 'Operator', 'gaticrew-events-bridge' ),
				'roleLabel'   => $this->get_current_user_role_label( $user ),
				'initials'    => $this->get_user_initials( $user ),
				'profileUrl'  => admin_url( 'profile.php' ),
				'accountUrl'  => admin_url( 'profile.php#account-management' ),
				'logoutUrl'   => wp_logout_url(),
			)
		);
	}

	/**
	 * Adds GatiCrew KPI widgets to the main Dashboard.
	 *
	 * @return void
	 */
	public function register_dashboard_widgets() {
		$this->remove_default_dashboard_widgets();

		wp_add_dashboard_widget(
			'gaticrew_platform_overview',
			__( 'GatiCrew Command Center', 'gaticrew-events-bridge' ),
			array( $this, 'render_platform_overview_widget' )
		);

		$this->force_command_center_main_column();
	}

	/**
	 * Keeps the Dashboard focused on the GatiCrew Command Center only.
	 *
	 * WordPress and WooCommerce register several default dashboard cards. The
	 * GatiCrew admin shell uses the Dashboard as an operations landing screen,
	 * so every non-GatiCrew dashboard metabox is removed server-side.
	 *
	 * @return void
	 */
	public function keep_command_center_dashboard_only() {
		$this->remove_default_dashboard_widgets();
		$this->remove_non_command_center_dashboard_widgets();
		$this->force_command_center_main_column();
	}

	/**
	 * Prevents saved Screen Options from hiding the Command Center widget.
	 *
	 * WordPress stores hidden dashboard widgets per user. If the widget was
	 * hidden before the SaaS dashboard redesign, it can remain checked/unchecked
	 * independently of our server-side dashboard setup.
	 *
	 * @param mixed $hidden_widgets Saved hidden widget IDs.
	 * @return mixed
	 */
	public function keep_command_center_visible( $hidden_widgets ) {
		if ( ! is_array( $hidden_widgets ) ) {
			return $hidden_widgets;
		}

		return array_values( array_diff( $hidden_widgets, array( 'gaticrew_platform_overview' ) ) );
	}

	/**
	 * Normalizes saved dashboard ordering so the widget is in the main column.
	 *
	 * WordPress remembers metabox placement per user. Because the redesigned
	 * dashboard hides secondary columns, a widget saved into the side column can
	 * be active in Screen Options but invisible in the main dashboard area.
	 *
	 * @param mixed $order Saved dashboard order.
	 * @return mixed
	 */
	public function keep_command_center_in_main_column( $order ) {
		if ( ! is_array( $order ) ) {
			return $order;
		}

		foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $context ) {
			$widget_ids = $this->parse_dashboard_order_ids( isset( $order[ $context ] ) ? $order[ $context ] : '' );
			$widget_ids = array_values( array_diff( $widget_ids, array( 'gaticrew_platform_overview' ) ) );

			$order[ $context ] = implode( ',', $widget_ids );
		}

		$normal_ids = $this->parse_dashboard_order_ids( isset( $order['normal'] ) ? $order['normal'] : '' );
		array_unshift( $normal_ids, 'gaticrew_platform_overview' );

		$order['normal'] = implode( ',', array_values( array_unique( $normal_ids ) ) );

		return $order;
	}

	/**
	 * Removes common WordPress, WooCommerce, and plugin dashboard widgets.
	 *
	 * @return void
	 */
	private function remove_default_dashboard_widgets() {
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		$dashboard_widgets = array(
			'dashboard_activity',
			'dashboard_browser_nag',
			'dashboard_incoming_links',
			'dashboard_plugins',
			'dashboard_primary',
			'dashboard_quick_press',
			'dashboard_recent_comments',
			'dashboard_recent_drafts',
			'dashboard_right_now',
			'dashboard_secondary',
			'dashboard_site_health',
			'dashboard_php_nag',
			'woocommerce_dashboard_status',
			'woocommerce_dashboard_recent_reviews',
			'wc_admin_dashboard_setup',
			'tribe_dashboard_widget',
			'tribe_events_dashboard_widget',
		);

		foreach ( $dashboard_widgets as $widget_id ) {
			foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $context ) {
				remove_meta_box( $widget_id, 'dashboard', $context );
			}
		}
	}

	/**
	 * Removes any dashboard metabox that is not the Command Center.
	 *
	 * @return void
	 */
	private function remove_non_command_center_dashboard_widgets() {
		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}

			foreach ( $priorities as $priority => $widgets ) {
				if ( ! is_array( $widgets ) ) {
					continue;
				}

				foreach ( array_keys( $widgets ) as $widget_id ) {
					if ( 'gaticrew_platform_overview' === $widget_id ) {
						continue;
					}

					unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ][ $widget_id ] );
				}
			}
		}
	}

	/**
	 * Forces the Command Center metabox into dashboard normal/core.
	 *
	 * This protects the redesigned single-column dashboard from old user
	 * metabox placements and late plugin/widget registration.
	 *
	 * @return void
	 */
	private function force_command_center_main_column() {
		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes['dashboard'] ) || ! is_array( $wp_meta_boxes['dashboard'] ) ) {
			return;
		}

		$command_center_widget = null;

		foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
			if ( ! is_array( $priorities ) ) {
				continue;
			}

			foreach ( $priorities as $priority => $widgets ) {
				if ( ! is_array( $widgets ) || empty( $widgets['gaticrew_platform_overview'] ) ) {
					continue;
				}

				$command_center_widget = $widgets['gaticrew_platform_overview'];
				unset( $wp_meta_boxes['dashboard'][ $context ][ $priority ]['gaticrew_platform_overview'] );
			}
		}

		if ( null === $command_center_widget ) {
			$command_center_widget = array(
				'id'       => 'gaticrew_platform_overview',
				'title'    => __( 'GatiCrew Command Center', 'gaticrew-events-bridge' ),
				'callback' => array( $this, 'render_platform_overview_widget' ),
				'args'     => null,
			);
		}

		if ( empty( $wp_meta_boxes['dashboard']['normal'] ) || ! is_array( $wp_meta_boxes['dashboard']['normal'] ) ) {
			$wp_meta_boxes['dashboard']['normal'] = array();
		}

		if ( empty( $wp_meta_boxes['dashboard']['normal']['core'] ) || ! is_array( $wp_meta_boxes['dashboard']['normal']['core'] ) ) {
			$wp_meta_boxes['dashboard']['normal']['core'] = array();
		}

		$wp_meta_boxes['dashboard']['normal']['core']['gaticrew_platform_overview'] = $command_center_widget;
	}

	/**
	 * Parses the comma-separated dashboard metabox order option.
	 *
	 * @param mixed $value Saved context order.
	 * @return array
	 */
	private function parse_dashboard_order_ids( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		return array_filter(
			array_map(
				'sanitize_key',
				array_map( 'trim', explode( ',', $value ) )
			)
		);
	}

	/**
	 * Removes WordPress branding clutter from the topbar.
	 *
	 * This keeps the admin bar functional while making it feel like a minimal
	 * operations shell instead of a stock WordPress toolbar.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public function cleanup_admin_bar( $admin_bar ) {
		if ( ! $admin_bar instanceof WP_Admin_Bar ) {
			return;
		}

		foreach ( array( 'wp-logo', 'site-name', 'comments', 'updates', 'customize', 'themes', 'menus', 'my-account', 'user-info', 'edit-profile', 'logout', 'gaticrew-platform' ) as $node_id ) {
			$admin_bar->remove_node( $node_id );
		}
	}

	/**
	 * Suppresses the Event Tickets Plus WooCommerce upsell notice.
	 *
	 * GatiCrew intentionally bridges TEC/Event Tickets with WooCommerce through
	 * custom order, attendee, QR, and check-in flows. The upstream notice is a
	 * generic upsell warning for normal Event Tickets installs, so we remove its
	 * exact notice slug without touching other Event Tickets admin notices.
	 *
	 * @return void
	 */
	public function suppress_event_tickets_plus_commerce_notice() {
		$notice_slugs = array(
			'event-tickets-plus-missing-woocommerce-support',
			'event-tickets-plus-missing-easydigitaldownloads-support',
		);

		foreach ( $notice_slugs as $notice_slug ) {
			$this->remove_tribe_admin_notice( $notice_slug );
		}

		if ( ! class_exists( 'Tribe__Admin__Notices' ) ) {
			return;
		}

		$registered_notices = Tribe__Admin__Notices::instance()->get();

		if ( ! is_array( $registered_notices ) ) {
			return;
		}

		foreach ( array_keys( $registered_notices ) as $notice_slug ) {
			$notice_slug = sanitize_key( $notice_slug );

			if ( 0 !== strpos( $notice_slug, 'event-tickets-plus-missing-' ) ) {
				continue;
			}

			if ( '-support' !== substr( $notice_slug, -8 ) ) {
				continue;
			}

			$this->remove_tribe_admin_notice( $notice_slug );
		}
	}

	/**
	 * Removes a TEC common admin notice and any already-attached render action.
	 *
	 * @param string $notice_slug Notice slug.
	 * @return void
	 */
	private function remove_tribe_admin_notice( $notice_slug ) {
		$notice_slug = sanitize_key( $notice_slug );

		if ( function_exists( 'tec_remove_notice' ) ) {
			tec_remove_notice( $notice_slug );
		} elseif ( class_exists( 'Tribe__Admin__Notices' ) ) {
			Tribe__Admin__Notices::instance()->remove( $notice_slug );
		}

		if ( class_exists( 'Tribe__Admin__Notices' ) ) {
			remove_action(
				'admin_notices',
				array( Tribe__Admin__Notices::instance(), 'render_' . $notice_slug ),
				10
			);
		}
	}

	/**
	 * Renders premium KPI cards for the admin landing dashboard.
	 *
	 * @return void
	 */
	public function render_platform_overview_widget() {
		$stats = $this->get_dashboard_stats();
		?>
		<div class="gaticrew-kpi-grid">
			<?php foreach ( $stats as $stat ) : ?>
				<div class="gaticrew-kpi-card">
					<div class="gaticrew-kpi-card__icon" aria-hidden="true"><?php echo esc_html( $stat['icon'] ); ?></div>
					<div class="gaticrew-kpi-card__content">
						<span class="gaticrew-kpi-card__label"><?php echo esc_html( $stat['label'] ); ?></span>
						<strong class="gaticrew-kpi-card__value"><?php echo esc_html( $stat['value'] ); ?></strong>
						<span class="gaticrew-kpi-card__trend"><?php echo esc_html( $stat['trend'] ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Renders fast actions for operators and administrators.
	 *
	 * @return void
	 */
	public function render_shortcuts_widget() {
		$links = array(
			array(
				'label' => __( 'Ticket Orders', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=' . GatiCrew_Events_Bridge_Ticket_Orders_Admin::MENU_SLUG ),
				'cap'   => GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES,
			),
			array(
				'label' => __( 'Attendees', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=' . GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG ),
				'cap'   => GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES,
			),
			array(
				'label' => __( 'Check-In Scanner', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=' . GatiCrew_Events_Bridge_Scanner_Admin::MENU_SLUG ),
				'cap'   => GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_CHECKINS,
			),
			array(
				'label' => __( 'Events', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'edit.php?post_type=tribe_events' ),
				'cap'   => 'edit_tribe_events',
			),
			array(
				'label' => __( 'Products', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'edit.php?post_type=product' ),
				'cap'   => 'edit_products',
			),
			array(
				'label' => __( 'Woo Orders', 'gaticrew-events-bridge' ),
				'url'   => admin_url( 'admin.php?page=wc-orders' ),
				'cap'   => 'edit_shop_orders',
			),
		);
		?>
		<div class="gaticrew-shortcut-grid">
			<?php foreach ( $links as $link ) : ?>
				<?php if ( current_user_can( $link['cap'] ) || current_user_can( 'manage_options' ) ) : ?>
					<a class="gaticrew-shortcut-card" href="<?php echo esc_url( $link['url'] ); ?>">
						<span><?php echo esc_html( $link['label'] ); ?></span>
						<i aria-hidden="true">&rarr;</i>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Replaces the default footer copy with brand-safe platform text.
	 *
	 * @return string
	 */
	public function filter_footer_text() {
		return esc_html__( 'GatiCrew Operations Platform', 'gaticrew-events-bridge' );
	}

	/**
	 * Returns dashboard stats with guarded, low-cost queries.
	 *
	 * @return array
	 */
	private function get_dashboard_stats() {
		$active_events = $this->count_active_events();
		$ticket_orders = $this->count_ticket_orders();
		$tickets_sold  = $this->count_tickets_sold();
		$checkins      = $this->count_checkins();
		$attendance    = $tickets_sold > 0 ? round( ( $checkins / $tickets_sold ) * 100 ) . '%' : '0%';
		$revenue       = $this->get_ticket_revenue();

		return array(
			array(
				'icon'  => 'INR',
				'label' => __( 'Ticket Revenue', 'gaticrew-events-bridge' ),
				'value' => $revenue,
				'trend' => __( 'WooCommerce powered', 'gaticrew-events-bridge' ),
			),
			array(
				'icon'  => 'EV',
				'label' => __( 'Active Events', 'gaticrew-events-bridge' ),
				'value' => number_format_i18n( $active_events ),
				'trend' => __( 'Published calendar inventory', 'gaticrew-events-bridge' ),
			),
			array(
				'icon'  => 'TK',
				'label' => __( 'Tickets Sold', 'gaticrew-events-bridge' ),
				'value' => number_format_i18n( $tickets_sold ),
				'trend' => sprintf(
					/* translators: %d: ticket order count. */
					_n( '%d booking', '%d bookings', $ticket_orders, 'gaticrew-events-bridge' ),
					$ticket_orders
				),
			),
			array(
				'icon'  => '%',
				'label' => __( 'Attendance Rate', 'gaticrew-events-bridge' ),
				'value' => $attendance,
				'trend' => sprintf(
					/* translators: %d: check-in count. */
					_n( '%d check-in recorded', '%d check-ins recorded', $checkins, 'gaticrew-events-bridge' ),
					$checkins
				),
			),
		);
	}

	/**
	 * Counts published event posts.
	 *
	 * @return int
	 */
	private function count_active_events() {
		$post_type = class_exists( 'GatiCrew_Events_Bridge_Events' ) ? GatiCrew_Events_Bridge_Events::get_event_post_type() : 'tribe_events';
		$count     = wp_count_posts( $post_type );

		return isset( $count->publish ) ? absint( $count->publish ) : 0;
	}

	/**
	 * Counts GatiCrew ticket orders.
	 *
	 * @return int
	 */
	private function count_ticket_orders() {
		$query = $this->query_ticket_orders( 1, 1 );

		return isset( $query->total ) ? absint( $query->total ) : 0;
	}

	/**
	 * Counts attendee rows as sold tickets.
	 *
	 * @return int
	 */
	private function count_tickets_sold() {
		global $wpdb;

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	}

	/**
	 * Counts checked-in attendee rows.
	 *
	 * @return int
	 */
	private function count_checkins() {
		global $wpdb;

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE checked_in = 1" ) );
	}

	/**
	 * Returns paid ticket revenue.
	 *
	 * @return string
	 */
	private function get_ticket_revenue() {
		$total = 0.0;
		$query = $this->query_ticket_orders( 1, 100 );

		if ( ! empty( $query->orders ) ) {
			foreach ( (array) $query->orders as $order ) {
				if ( $order instanceof WC_Order && $order->is_paid() ) {
					$total += (float) $order->get_total();
				}
			}
		}

		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $total ) );
		}

		return number_format_i18n( $total, 2 );
	}

	/**
	 * Queries ticket orders by plugin marker meta.
	 *
	 * @param int $page Page.
	 * @param int $limit Limit.
	 * @return object
	 */
	private function query_ticket_orders( $page, $limit ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return (object) array(
				'orders' => array(),
				'total'  => 0,
			);
		}

		return wc_get_orders(
			array(
				'limit'      => max( 1, absint( $limit ) ),
				'page'       => max( 1, absint( $page ) ),
				'paginate'   => true,
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array(
					array(
						'key'     => GatiCrew_Events_Bridge::ORDER_META_TICKET_ORDER,
						'value'   => 'yes',
						'compare' => '=',
					),
				),
			)
		);
	}

	/**
	 * Detects plugin-owned screens for contextual CSS.
	 *
	 * @return bool
	 */
	private function is_gaticrew_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 0 === strpos( $page, 'gaticrew' );
	}

	/**
	 * Gets the current user's primary role label.
	 *
	 * @param WP_User $user Current user.
	 * @return string
	 */
	private function get_current_user_role_label( $user ) {
		if ( ! $user instanceof WP_User || empty( $user->roles ) ) {
			return __( 'Operations', 'gaticrew-events-bridge' );
		}

		$role_key = reset( $user->roles );
		$roles    = wp_roles();
		$role     = $roles && isset( $roles->roles[ $role_key ]['name'] ) ? $roles->roles[ $role_key ]['name'] : $role_key;

		return translate_user_role( $role );
	}

	/**
	 * Builds avatar initials for the sidebar profile block.
	 *
	 * @param WP_User $user Current user.
	 * @return string
	 */
	private function get_user_initials( $user ) {
		$name = $user instanceof WP_User && $user->exists() ? $user->display_name : '';
		$name = trim( wp_strip_all_tags( $name ) );

		if ( '' === $name ) {
			return 'GC';
		}

		$parts    = preg_split( '/\s+/', $name );
		$initials = '';

		foreach ( array_slice( $parts, 0, 2 ) as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
		}

		return $initials ? $initials : 'GC';
	}
}
