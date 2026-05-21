<?php
/**
 * Professional attendee management list table.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class GatiCrew_Events_Bridge_Attendees_List_Table extends WP_List_Table {
	/**
	 * Attendee repository.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Repository
	 */
	private $repository;

	/**
	 * Current sanitized filters.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'gaticrew_attendee',
				'plural'   => 'gaticrew_attendees',
				'ajax'     => false,
			)
		);

		$this->repository = new GatiCrew_Events_Bridge_Attendees_Repository();
	}

	/**
	 * Returns sanitized filters for the current request.
	 *
	 * @return array
	 */
	public function get_current_filters() {
		if ( ! empty( $this->filters ) ) {
			return $this->filters;
		}

		$this->filters = array(
			'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'event_id' => isset( $_REQUEST['event_id'] ) ? absint( wp_unslash( $_REQUEST['event_id'] ) ) : 0,
			'status'   => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'date'     => isset( $_REQUEST['date_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['date_filter'] ) ) : '',
			'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at',
			'order'    => isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC',
		);

		if ( ! array_key_exists( $this->filters['status'], GatiCrew_Events_Bridge_Attendees_Repository::get_statuses() ) ) {
			$this->filters['status'] = '';
		}

		if ( ! in_array( $this->filters['date'], array( '', 'today', 'last_7_days', 'this_month' ), true ) ) {
			$this->filters['date'] = '';
		}

		return $this->filters;
	}

	/**
	 * Returns list table columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'             => '<input type="checkbox" />',
			'booking_id'     => __( 'Booking ID', 'gaticrew-events-bridge' ),
			'attendee_names' => __( 'Attendee Names', 'gaticrew-events-bridge' ),
			'attendee_email' => __( 'Email', 'gaticrew-events-bridge' ),
			'attendee_phone' => __( 'Phone', 'gaticrew-events-bridge' ),
			'event_name'     => __( 'Event', 'gaticrew-events-bridge' ),
			'quantity'       => __( 'Quantity', 'gaticrew-events-bridge' ),
			'status'         => __( 'Status', 'gaticrew-events-bridge' ),
			'checked_in'     => __( 'Checked-in', 'gaticrew-events-bridge' ),
			'order_id'       => __( 'Order ID', 'gaticrew-events-bridge' ),
			'created_at'     => __( 'Created Date', 'gaticrew-events-bridge' ),
		);
	}

	/**
	 * Returns sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'booking_id'    => array( 'booking_id', false ),
			'attendee_name' => array( 'attendee_name', false ),
			'event_name'    => array( 'event_name', false ),
			'created_at'    => array( 'created_at', true ),
		);
	}

	/**
	 * Returns bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'mark_confirmed'  => __( 'Mark Confirmed', 'gaticrew-events-bridge' ),
			'mark_cancelled'  => __( 'Mark Cancelled', 'gaticrew-events-bridge' ),
			'mark_checked_in' => __( 'Mark Checked In', 'gaticrew-events-bridge' ),
			'delete'          => __( 'Delete', 'gaticrew-events-bridge' ),
		);
	}

	/**
	 * Returns status view links with totals.
	 *
	 * @return array
	 */
	protected function get_views() {
		$filters = $this->get_current_filters();
		$counts  = $this->repository->count_by_status( $filters );
		$statuses = array_merge(
			array( '' => __( 'All', 'gaticrew-events-bridge' ) ),
			GatiCrew_Events_Bridge_Attendees_Repository::get_statuses()
		);
		$views = array();

		foreach ( $statuses as $status => $label ) {
			$count_key = '' === $status ? 'all' : $status;
			$count     = isset( $counts[ $count_key ] ) ? absint( $counts[ $count_key ] ) : 0;
			$url       = remove_query_arg( array( 'paged', 'status' ) );

			if ( '' !== $status ) {
				$url = add_query_arg( 'status', $status, $url );
			}

			$class = $filters['status'] === $status ? ' class="current"' : '';

			$views[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * Prepares attendee rows and pagination.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'gaticrew_attendees_per_page', 20 );
		$page     = $this->get_pagenum();
		$filters  = $this->get_current_filters();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'booking_id' );
		$this->items           = $this->repository->get_admin_items(
			array_merge(
				$filters,
				array(
					'per_page' => $per_page,
					'page'     => $page,
				)
			)
		);

		$total_items = $this->repository->count_admin_items( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Renders checkbox column.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_cb( $item ) {
		$id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;

		return sprintf(
			'<input type="checkbox" name="attendee[]" value="%d" />',
			$id
		);
	}

	/**
	 * Renders default sanitized column output.
	 *
	 * @param array  $item Row item.
	 * @param string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		if ( ! isset( $item[ $column_name ] ) ) {
			return '';
		}

		return esc_html( $item[ $column_name ] );
	}

	/**
	 * Renders booking ID with row actions.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_booking_id( $item ) {
		$id         = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		$booking_id = isset( $item['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $item['booking_id'] ) : '';
		$actions    = array();

		if ( $id ) {
			$actions['view'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'     => GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG,
								'action'   => 'view',
								'attendee' => $id,
							),
							admin_url( 'admin.php' )
						),
						'gaticrew_view_attendee_' . $id
					)
				),
				esc_html__( 'View', 'gaticrew-events-bridge' )
			);
			$actions['pdf'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( GatiCrew_Events_Bridge_PDF_Tickets::get_admin_download_url( $id ) ),
				esc_html__( 'PDF', 'gaticrew-events-bridge' )
			);
			$actions['delete'] = sprintf(
				'<a href="%1$s" class="submitdelete">%2$s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'     => GatiCrew_Events_Bridge_Attendees_Admin::MENU_SLUG,
								'action'   => 'delete',
								'attendee' => $id,
							),
							admin_url( 'admin.php' )
						),
						'bulk-gaticrew_attendees'
					)
				),
				esc_html__( 'Delete', 'gaticrew-events-bridge' )
			);
		}

		return '<strong>' . esc_html( $booking_id ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Renders all attendee names stored on the booking row.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_attendee_names( $item ) {
		$names = ! empty( $item['attendee_names'] ) && is_array( $item['attendee_names'] ) ? $item['attendee_names'] : array();

		if ( empty( $names ) && ! empty( $item['attendee_name'] ) ) {
			$names[] = sanitize_text_field( $item['attendee_name'] );
		}

		if ( empty( $names ) ) {
			return '';
		}

		return '<ol class="gaticrew-attendee-names"><li>' . implode( '</li><li>', array_map( 'esc_html', $names ) ) . '</li></ol>';
	}

	/**
	 * Renders ticket quantity for the booking row.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_quantity( $item ) {
		$count = isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;

		return sprintf(
			'<span class="gaticrew-booking-group-count">%s</span>',
			esc_html(
				sprintf(
					_n( '%d ticket', '%d tickets', $count, 'gaticrew-events-bridge' ),
					$count
				)
			)
		);
	}

	/**
	 * Adds a visual class to rows that belong to group bookings.
	 *
	 * @param object|array $item Row item.
	 * @return void
	 */
	public function single_row( $item ) {
		$count = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
		$class = $count > 1 ? ' class="gaticrew-attendee-row--group"' : '';

		echo '<tr' . $class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Renders attendee email as a mailto link.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_attendee_email( $item ) {
		$email = isset( $item['attendee_email'] ) ? sanitize_email( $item['attendee_email'] ) : '';

		if ( ! $email ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( 'mailto:' . $email ),
			esc_html( $email )
		);
	}

	/**
	 * Renders event name.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_event_name( $item ) {
		$event_id   = isset( $item['event_id'] ) ? absint( $item['event_id'] ) : 0;
		$event_name = isset( $item['event_name'] ) ? sanitize_text_field( $item['event_name'] ) : '';

		if ( $event_id && $event_name ) {
			return sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( get_edit_post_link( $event_id ) ),
				esc_html( $event_name )
			);
		}

		return esc_html( $event_name );
	}

	/**
	 * Renders booking status badge.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_status( $item ) {
		$status   = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : '';
		$statuses = GatiCrew_Events_Bridge_Attendees_Repository::get_statuses();
		$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;

		return sprintf(
			'<mark class="gaticrew-attendee-status gaticrew-attendee-status--%1$s"><span>%2$s</span></mark>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Renders checked-in state.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_checked_in( $item ) {
		$checked_in = ! empty( $item['checked_in'] );
		$checked_at = ! empty( $item['checked_in_at'] ) ? sanitize_text_field( $item['checked_in_at'] ) : '';

		if ( ! $checked_in ) {
			return esc_html__( 'No', 'gaticrew-events-bridge' );
		}

		$label = esc_html__( 'Yes', 'gaticrew-events-bridge' );

		if ( $checked_at ) {
			$timestamp = strtotime( $checked_at );
			$label .= $timestamp ? '<br><small>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ) . '</small>' : '';
		}

		return '<span class="gaticrew-checked-in">' . $label . '</span>';
	}

	/**
	 * Renders QR ticket preview.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_qr_ticket( $item ) {
		$token = isset( $item['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $item['qr_token'] ) : '';

		if ( '' === $token ) {
			return '&mdash;';
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img class="gaticrew-attendee-qr-thumb" src="%2$s" alt="%3$s"></a>',
			esc_url( GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $token ) ),
			esc_url( GatiCrew_Events_Bridge_QR_Tokens::get_qr_image_url( $token ) ),
			esc_attr__( 'QR Ticket', 'gaticrew-events-bridge' )
		);
	}

	/**
	 * Renders the WooCommerce order link.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_order_id( $item ) {
		$order_id = isset( $item['order_id'] ) ? absint( $item['order_id'] ) : 0;

		if ( ! $order_id ) {
			return '';
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( $order instanceof WC_Order ) {
			return sprintf(
				'<a href="%1$s">#%2$d</a>',
				esc_url( $order->get_edit_order_url() ),
				$order_id
			);
		}

		return esc_html( '#' . $order_id );
	}

	/**
	 * Renders created date.
	 *
	 * @param array $item Row item.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$created_at = isset( $item['created_at'] ) ? sanitize_text_field( $item['created_at'] ) : '';
		$timestamp  = $created_at ? strtotime( $created_at ) : false;

		if ( ! $timestamp ) {
			return esc_html( $created_at );
		}

		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
	}

	/**
	 * Renders event, status, and date filters above the table.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = $this->get_current_filters();
		?>
		<div class="alignleft actions gaticrew-attendees-filters">
			<?php $this->render_event_filter( $filters['event_id'] ); ?>
			<?php $this->render_status_filter( $filters['status'] ); ?>
			<?php $this->render_date_filter( $filters['date'] ); ?>
			<?php submit_button( __( 'Filter', 'gaticrew-events-bridge' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Renders the event filter dropdown.
	 *
	 * @param int $selected_event_id Selected event ID.
	 * @return void
	 */
	private function render_event_filter( $selected_event_id ) {
		$events = $this->repository->get_events_for_filter();
		?>
		<label class="screen-reader-text" for="gaticrew-filter-event"><?php echo esc_html__( 'Filter by event', 'gaticrew-events-bridge' ); ?></label>
		<select id="gaticrew-filter-event" name="event_id">
			<option value="0"><?php echo esc_html__( 'All events', 'gaticrew-events-bridge' ); ?></option>
			<?php foreach ( $events as $event ) : ?>
				<?php
				$event_id   = isset( $event['event_id'] ) ? absint( $event['event_id'] ) : 0;
				$event_name = isset( $event['event_name'] ) ? sanitize_text_field( $event['event_name'] ) : '';
				?>
				<option value="<?php echo esc_attr( $event_id ); ?>" <?php selected( $selected_event_id, $event_id ); ?>>
					<?php echo esc_html( $event_name ? $event_name : '#' . $event_id ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders the status filter dropdown.
	 *
	 * @param string $selected_status Selected status.
	 * @return void
	 */
	private function render_status_filter( $selected_status ) {
		?>
		<label class="screen-reader-text" for="gaticrew-filter-status"><?php echo esc_html__( 'Filter by status', 'gaticrew-events-bridge' ); ?></label>
		<select id="gaticrew-filter-status" name="status">
			<option value=""><?php echo esc_html__( 'All statuses', 'gaticrew-events-bridge' ); ?></option>
			<?php foreach ( GatiCrew_Events_Bridge_Attendees_Repository::get_statuses() as $status => $label ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $selected_status, $status ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Renders the date filter dropdown.
	 *
	 * @param string $selected_date Selected date filter.
	 * @return void
	 */
	private function render_date_filter( $selected_date ) {
		$options = array(
			''            => __( 'All dates', 'gaticrew-events-bridge' ),
			'today'       => __( 'Today', 'gaticrew-events-bridge' ),
			'last_7_days' => __( 'Last 7 days', 'gaticrew-events-bridge' ),
			'this_month'  => __( 'This month', 'gaticrew-events-bridge' ),
		);
		?>
		<label class="screen-reader-text" for="gaticrew-filter-date"><?php echo esc_html__( 'Filter by date', 'gaticrew-events-bridge' ); ?></label>
		<select id="gaticrew-filter-date" name="date_filter">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected_date, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
