<?php
/**
 * Ticket order admin separation.
 *
 * WooCommerce remains the payment/order system of record. This module only
 * separates event-ticket bookings from ordinary ecommerce orders in wp-admin.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Ticket_Orders_Admin {
	/**
	 * Parent menu slug for the grouped GatiCrew admin area.
	 */
	const PARENT_MENU_SLUG = 'gaticrew-ticket-orders';

	/**
	 * Ticket orders screen slug.
	 */
	const MENU_SLUG = 'gaticrew-ticket-orders';

	/**
	 * One-time backfill option for older ticket bookings.
	 */
	const BACKFILL_OPTION = 'gaticrew_ticket_order_meta_backfilled_1';

	/**
	 * Attendee repository.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Repository
	 */
	private $repository;

	/**
	 * Screen hook suffix.
	 *
	 * @var string
	 */
	private $screen_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository = new GatiCrew_Events_Bridge_Attendees_Repository();
	}

	/**
	 * Registers admin menu and order-list filters.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'backfill_ticket_order_meta' ), 20 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'pre_get_posts', array( $this, 'exclude_ticket_orders_from_legacy_orders_list' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'exclude_ticket_orders_from_hpos_orders_list' ) );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'exclude_ticket_orders_from_hpos_orders_list' ) );
	}

	/**
	 * Adds the grouped GatiCrew menu with Ticket Orders as the landing screen.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->screen_hook = add_menu_page(
			__( 'GatiCrew', 'gaticrew-events-bridge' ),
			__( 'GatiCrew', 'gaticrew-events-bridge' ),
			GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES,
			self::PARENT_MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-tickets-alt',
			55
		);

		add_submenu_page(
			self::PARENT_MENU_SLUG,
			__( 'Ticket Orders', 'gaticrew-events-bridge' ),
			__( 'Ticket Orders', 'gaticrew-events-bridge' ),
			GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads admin CSS on the ticket order screen.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->screen_hook && 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'gaticrew-events-bridge-admin',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/css/admin.css',
			array(),
			GATICREW_EVENTS_BRIDGE_VERSION
		);
	}

	/**
	 * Renders the Ticket Orders list or quick-view screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gaticrew-events-bridge' ) );
		}

		if ( $this->is_quick_view_request() ) {
			$this->render_quick_view();
			return;
		}

		$this->render_list_page();
	}

	/**
	 * Hides ticket bookings from the HPOS WooCommerce Orders table only.
	 *
	 * @param array $query_args WooCommerce order query args.
	 * @return array
	 */
	public function exclude_ticket_orders_from_hpos_orders_list( $query_args ) {
		if ( ! $this->is_woocommerce_orders_list_request() ) {
			return $query_args;
		}

		return $this->append_non_ticket_order_meta_query( (array) $query_args );
	}

	/**
	 * Hides ticket bookings from the legacy shop_order post list only.
	 *
	 * @param WP_Query $query Admin query.
	 * @return void
	 */
	public function exclude_ticket_orders_from_legacy_orders_list( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		$pagenow   = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$post_type = $query->get( 'post_type' );

		if ( 'edit.php' !== $pagenow || 'shop_order' !== $post_type ) {
			return;
		}

		$query->set( 'meta_query', $this->get_non_ticket_order_meta_query( (array) $query->get( 'meta_query' ) ) );
	}

	/**
	 * Backfills clear ticket-order meta on bookings created before this module.
	 *
	 * This is deliberately one-time and based on plugin-owned attendee rows so
	 * legacy event bookings move out of WooCommerce Orders without touching
	 * ordinary product orders.
	 *
	 * @return void
	 */
	public function backfill_ticket_order_meta() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES ) ) {
			return;
		}

		global $wpdb;

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			update_option( self::BACKFILL_OPTION, current_time( 'mysql' ), false );
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT order_id,
				MIN(event_id) AS event_id,
				MIN(booking_id) AS booking_id,
				COUNT(*) AS attendee_count
			FROM {$table}
			WHERE order_id > 0
			GROUP BY order_id",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$order_id = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
			$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_TICKET_ORDER, 'yes' );
			$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_ATTENDEE_COUNT, max( 1, absint( $row['attendee_count'] ) ) );

			if ( ! $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, true ) && ! empty( $row['booking_id'] ) ) {
				$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $row['booking_id'] ) );
			}

			if ( ! $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, true ) && ! empty( $row['event_id'] ) ) {
				$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, absint( $row['event_id'] ) );
			}

			$order->save();
		}

		update_option( self::BACKFILL_OPTION, current_time( 'mysql' ), false );
	}

	/**
	 * Renders the ticket order list.
	 *
	 * @return void
	 */
	private function render_list_page() {
		$page       = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page   = 20;
		$query      = $this->get_ticket_orders( $page, $per_page );
		$orders     = isset( $query->orders ) ? (array) $query->orders : array();
		$total      = isset( $query->total ) ? absint( $query->total ) : count( $orders );
		$total_page = isset( $query->max_num_pages ) ? absint( $query->max_num_pages ) : 1;

		?>
		<div class="wrap gaticrew-ticket-orders-admin">
			<h1><?php echo esc_html__( 'Ticket Orders', 'gaticrew-events-bridge' ); ?></h1>

			<ul class="subsubsub">
				<li>
					<?php
					printf(
						/* translators: %d: ticket order count. */
						esc_html__( 'All ticket orders (%d)', 'gaticrew-events-bridge' ),
						absint( $total )
					);
					?>
				</li>
			</ul>

			<table class="wp-list-table widefat fixed striped table-view-list gaticrew-ticket-orders-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Woo Order ID', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Customer', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Event Name', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Payment Status', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Booking Status', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Created Date', 'gaticrew-events-bridge' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'gaticrew-events-bridge' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $orders ) ) : ?>
						<tr>
							<td colspan="9"><?php echo esc_html__( 'No ticket orders found.', 'gaticrew-events-bridge' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $orders as $order ) : ?>
							<?php $this->render_order_row( $order ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $page, $total_page, $total ); ?>
		</div>
		<?php
	}

	/**
	 * Renders one ticket-order table row.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private function render_order_row( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order_id     = absint( $order->get_id() );
		$attendees    = $this->repository->get_by_order( $order_id );
		$booking_id   = $this->get_order_booking_id( $order, $attendees );
		$event_name   = $this->get_order_event_name( $order, $attendees );
		$quantity     = $this->get_order_ticket_quantity( $order, $attendees );
		$status       = $this->get_booking_status( $attendees );
		$quick_url    = wp_nonce_url(
			add_query_arg(
				array(
					'page'     => self::MENU_SLUG,
					'action'   => 'view',
					'order_id' => $order_id,
				),
				admin_url( 'admin.php' )
			),
			'gaticrew_ticket_order_view_' . $order_id
		);
		?>
		<tr>
			<td><strong><?php echo esc_html( $booking_id ); ?></strong></td>
			<td><a href="<?php echo esc_url( $this->get_order_edit_url( $order_id ) ); ?>">#<?php echo esc_html( $order_id ); ?></a></td>
			<td><?php $this->render_customer_cell( $order ); ?></td>
			<td><?php echo esc_html( $event_name ); ?></td>
			<td><?php echo esc_html( $quantity ); ?></td>
			<td><?php echo esc_html( $this->get_payment_status_label( $order ) ); ?></td>
			<td><?php echo wp_kses_post( $this->get_status_badge_html( $status ) ); ?></td>
			<td><?php echo esc_html( $this->get_order_created_date( $order ) ); ?></td>
			<td><a class="button button-small" href="<?php echo esc_url( $quick_url ); ?>"><?php echo esc_html__( 'Quick View', 'gaticrew-events-bridge' ); ?></a></td>
		</tr>
		<?php
	}

	/**
	 * Renders a secure quick-view panel for one ticket order.
	 *
	 * @return void
	 */
	private function render_quick_view() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		if ( ! $order_id || ! check_admin_referer( 'gaticrew_ticket_order_view_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid ticket order request.', 'gaticrew-events-bridge' ) );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order || ! $this->is_ticket_order( $order ) ) {
			wp_die( esc_html__( 'Ticket order not found.', 'gaticrew-events-bridge' ) );
		}

		$attendees  = $this->repository->get_by_order( $order_id );
		$booking_id = $this->get_order_booking_id( $order, $attendees );
		$event_id   = $this->get_order_event_id( $order, $attendees );
		?>
		<div class="wrap gaticrew-ticket-orders-admin">
			<h1><?php echo esc_html__( 'Ticket Order Quick View', 'gaticrew-events-bridge' ); ?></h1>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
					<?php echo esc_html__( 'Back to Ticket Orders', 'gaticrew-events-bridge' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( $this->get_order_edit_url( $order_id ) ); ?>">
					<?php echo esc_html__( 'Open Woo Order', 'gaticrew-events-bridge' ); ?>
				</a>
			</p>

			<div class="gaticrew-ticket-order-details">
				<section class="gaticrew-ticket-order-details__panel">
					<h2><?php echo esc_html__( 'Booking', 'gaticrew-events-bridge' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $booking_id ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Woo Order', 'gaticrew-events-bridge' ); ?></th>
								<td><a href="<?php echo esc_url( $this->get_order_edit_url( $order_id ) ); ?>">#<?php echo esc_html( $order_id ); ?></a></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Customer', 'gaticrew-events-bridge' ); ?></th>
								<td><?php $this->render_customer_cell( $order ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $this->get_order_ticket_quantity( $order, $attendees ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Payment Status', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $this->get_payment_status_label( $order ) ); ?></td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="gaticrew-ticket-order-details__panel">
					<h2><?php echo esc_html__( 'Event', 'gaticrew-events-bridge' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th><?php echo esc_html__( 'Event Name', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $this->get_order_event_name( $order, $attendees ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Event Date', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $event_id ? GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) : $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_DATE, true ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Venue', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $event_id ? GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) : $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_VENUE, true ) ); ?></td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="gaticrew-ticket-order-details__panel gaticrew-ticket-order-details__panel--wide">
					<h2><?php echo esc_html__( 'Attendees and Check-In', 'gaticrew-events-bridge' ); ?></h2>
					<?php $this->render_attendees_quick_table( $attendees ); ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders attendee/check-in rows inside quick view.
	 *
	 * @param array $attendees GatiCrew attendee rows.
	 * @return void
	 */
	private function render_attendees_quick_table( array $attendees ) {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Attendee', 'gaticrew-events-bridge' ); ?></th>
					<th><?php echo esc_html__( 'Email', 'gaticrew-events-bridge' ); ?></th>
					<th><?php echo esc_html__( 'QR / Check-In Status', 'gaticrew-events-bridge' ); ?></th>
					<th><?php echo esc_html__( 'TEC Attendee', 'gaticrew-events-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $attendees ) ) : ?>
					<tr>
						<td colspan="4"><?php echo esc_html__( 'No attendee rows found for this ticket order.', 'gaticrew-events-bridge' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $attendees as $attendee ) : ?>
						<tr>
							<td><?php echo esc_html( $this->get_attendee_names_label( $attendee ) ); ?></td>
							<td><?php echo esc_html( isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '' ); ?></td>
							<td><?php $this->render_qr_status_cell( $attendee ); ?></td>
							<td>
								<?php
								$tec_id = isset( $attendee['tec_attendee_post_id'] ) ? absint( $attendee['tec_attendee_post_id'] ) : 0;
								echo $tec_id ? esc_html( '#' . $tec_id ) : esc_html__( 'Pending sync', 'gaticrew-events-bridge' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders QR sync and check-in status for quick view.
	 *
	 * @param array $attendee Attendee row.
	 * @return void
	 */
	private function render_qr_status_cell( array $attendee ) {
		$qr_image = ! empty( $attendee['tec_qr_image_url'] ) ? esc_url_raw( $attendee['tec_qr_image_url'] ) : '';

		if ( '' === $qr_image && ! empty( $attendee['qr_code'] ) ) {
			$qr_image = esc_url_raw( $attendee['qr_code'] );
		}

		echo wp_kses_post( $this->get_status_badge_html( isset( $attendee['status'] ) ? $attendee['status'] : '' ) );

		if ( $qr_image ) {
			printf(
				'<img class="gaticrew-ticket-order-qr-thumb" src="%1$s" alt="%2$s" />',
				esc_url( $qr_image ),
				esc_attr__( 'Ticket QR code', 'gaticrew-events-bridge' )
			);
		}

		if ( ! empty( $attendee['tec_security_code'] ) ) {
			echo '<br><code>' . esc_html( sanitize_text_field( $attendee['tec_security_code'] ) ) . '</code>';
		}
	}

	/**
	 * Queries WooCommerce ticket orders only.
	 *
	 * @param int $page Current page.
	 * @param int $per_page Rows per page.
	 * @return object
	 */
	private function get_ticket_orders( $page, $per_page ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return (object) array(
				'orders'        => array(),
				'total'         => 0,
				'max_num_pages' => 1,
			);
		}

		return wc_get_orders(
			array(
				'limit'      => max( 1, absint( $per_page ) ),
				'page'       => max( 1, absint( $page ) ),
				'paginate'   => true,
				'type'       => 'shop_order',
				'orderby'    => 'date',
				'order'      => 'DESC',
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
	 * Adds the "not a GatiCrew ticket order" clause to a Woo order query.
	 *
	 * @param array $query_args Existing query args.
	 * @return array
	 */
	private function append_non_ticket_order_meta_query( array $query_args ) {
		$query_args['meta_query'] = $this->get_non_ticket_order_meta_query( isset( $query_args['meta_query'] ) ? (array) $query_args['meta_query'] : array() );

		return $query_args;
	}

	/**
	 * Builds the meta query that keeps ordinary ecommerce orders visible.
	 *
	 * @param array $meta_query Existing meta query.
	 * @return array
	 */
	private function get_non_ticket_order_meta_query( array $meta_query = array() ) {
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => GatiCrew_Events_Bridge::ORDER_META_TICKET_ORDER,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => GatiCrew_Events_Bridge::ORDER_META_TICKET_ORDER,
				'value'   => 'yes',
				'compare' => '!=',
			),
		);

		return $meta_query;
	}

	/**
	 * Detects the real WooCommerce Orders list, excluding edit and GatiCrew pages.
	 *
	 * @return bool
	 */
	private function is_woocommerce_orders_list_request() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'admin.php' === $pagenow && 'wc-orders' === $page ) {
			return ! in_array( $action, array( 'edit', 'new', 'create' ), true );
		}

		return 'edit.php' === $pagenow && 'shop_order' === $this->get_request_post_type();
	}

	/**
	 * Checks whether this order is marked as a GatiCrew ticket order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function is_ticket_order( WC_Order $order ) {
		return 'yes' === sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_TICKET_ORDER, true ) );
	}

	/**
	 * Detects quick-view requests.
	 *
	 * @return bool
	 */
	private function is_quick_view_request() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		return 'view' === $action && ! empty( $_GET['order_id'] );
	}

	/**
	 * Returns the current post_type request parameter.
	 *
	 * @return string
	 */
	private function get_request_post_type() {
		return isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
	}

	/**
	 * Renders pagination links.
	 *
	 * @param int $page Current page.
	 * @param int $total_pages Total pages.
	 * @param int $total_items Total items.
	 * @return void
	 */
	private function render_pagination( $page, $total_pages, $total_items ) {
		if ( 1 >= $total_pages ) {
			return;
		}

		$base_url = remove_query_arg( array( 'paged', '_wpnonce', 'action', 'order_id' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %d: ticket order count. */
						esc_html__( '%d items', 'gaticrew-events-bridge' ),
						absint( $total_items )
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $base_url ),
							'format'    => '',
							'current'   => max( 1, absint( $page ) ),
							'total'     => max( 1, absint( $total_pages ) ),
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
						)
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Gets the booking ID for display.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $attendees Attendee rows.
	 * @return string
	 */
	private function get_order_booking_id( WC_Order $order, array $attendees ) {
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, true ) );

		if ( $booking_id ) {
			return $booking_id;
		}

		return ! empty( $attendees[0]['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendees[0]['booking_id'] ) : '';
	}

	/**
	 * Gets the event ID for display.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $attendees Attendee rows.
	 * @return int
	 */
	private function get_order_event_id( WC_Order $order, array $attendees ) {
		$event_id = absint( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, true ) );

		if ( $event_id ) {
			return $event_id;
		}

		return ! empty( $attendees[0]['event_id'] ) ? absint( $attendees[0]['event_id'] ) : 0;
	}

	/**
	 * Gets event name for display.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $attendees Attendee rows.
	 * @return string
	 */
	private function get_order_event_name( WC_Order $order, array $attendees ) {
		$event_id = $this->get_order_event_id( $order, $attendees );

		if ( $event_id ) {
			return GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
		}

		$name = sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_NAME, true ) );

		return '' !== $name ? $name : __( 'Event unavailable', 'gaticrew-events-bridge' );
	}

	/**
	 * Gets ticket quantity for display.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param array    $attendees Attendee rows.
	 * @return int
	 */
	private function get_order_ticket_quantity( WC_Order $order, array $attendees ) {
		$count = absint( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_ATTENDEE_COUNT, true ) );

		if ( $count ) {
			return $count;
		}

		if ( ! empty( $attendees ) ) {
			return count( $attendees );
		}

		return absint( $order->get_meta( '_gaticrew_quantity', true ) );
	}

	/**
	 * Gets the aggregate booking status.
	 *
	 * @param array $attendees Attendee rows.
	 * @return string
	 */
	private function get_booking_status( array $attendees ) {
		if ( empty( $attendees ) ) {
			return GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED;
		}

		$statuses = wp_list_pluck( $attendees, 'status' );

		if ( in_array( GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CANCELLED, $statuses, true ) ) {
			return GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CANCELLED;
		}

		if ( in_array( GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN, $statuses, true ) ) {
			return GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN;
		}

		return GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED;
	}

	/**
	 * Gets a human order payment/status label.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_payment_status_label( WC_Order $order ) {
		if ( $order->is_paid() ) {
			return __( 'Paid', 'gaticrew-events-bridge' );
		}

		return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : ucfirst( $order->get_status() );
	}

	/**
	 * Builds a status badge.
	 *
	 * @param string $status Booking status.
	 * @return string
	 */
	private function get_status_badge_html( $status ) {
		$status   = sanitize_key( (string) $status );
		$statuses = GatiCrew_Events_Bridge_Attendees_Repository::get_statuses();
		$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ] : __( 'Confirmed', 'gaticrew-events-bridge' );
		$class    = 'gaticrew-attendee-status gaticrew-attendee-status--' . sanitize_html_class( $status ? $status : 'confirmed' );

		return sprintf( '<span class="%1$s">%2$s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Renders customer name and email.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private function render_customer_cell( WC_Order $order ) {
		$name  = trim( $order->get_formatted_billing_full_name() );
		$email = sanitize_email( $order->get_billing_email() );

		if ( '' === $name ) {
			$name = sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_NAME, true ) );
		}

		echo esc_html( $name ? $name : __( 'Guest', 'gaticrew-events-bridge' ) );

		if ( $email ) {
			echo '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		}
	}

	/**
	 * Gets an attendee names label.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private function get_attendee_names_label( array $attendee ) {
		$names = ! empty( $attendee['attendee_names'] ) && is_array( $attendee['attendee_names'] ) ? $attendee['attendee_names'] : array();

		if ( empty( $names ) && ! empty( $attendee['attendee_name'] ) ) {
			$names[] = sanitize_text_field( $attendee['attendee_name'] );
		}

		return implode( ', ', array_map( 'sanitize_text_field', $names ) );
	}

	/**
	 * Gets created date for display.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function get_order_created_date( WC_Order $order ) {
		$date = $order->get_date_created();

		return $date ? $date->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
	}

	/**
	 * Gets a WooCommerce order edit URL across HPOS and legacy storage.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return string
	 */
	private function get_order_edit_url( $order_id ) {
		$order_id = absint( $order_id );

		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_container' ) ) {
			try {
				$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );

				if ( is_object( $controller ) && method_exists( $controller, 'custom_orders_table_usage_is_enabled' ) && $controller->custom_orders_table_usage_is_enabled() ) {
					return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
				}
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}

		$link = get_edit_post_link( $order_id, 'raw' );

		return $link ? $link : admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
	}
}
