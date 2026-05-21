<?php
/**
 * Admin attendee management screen.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Attendees_Admin {
	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'gaticrew-attendees';

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
	 * Registers admin menu hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'set_screen_option_gaticrew_attendees_per_page', array( $this, 'set_attendees_per_page_option' ), 10, 3 );
	}

	/**
	 * Adds the GatiCrew Attendees admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->screen_hook = add_menu_page(
			__( 'GatiCrew Attendees', 'gaticrew-events-bridge' ),
			__( 'GatiCrew Attendees', 'gaticrew-events-bridge' ),
			GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-tickets-alt',
			56
		);

		add_action( 'load-' . $this->screen_hook, array( $this, 'add_screen_options' ) );
	}

	/**
	 * Adds per-page screen option for the attendee table.
	 *
	 * @return void
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Attendees per page', 'gaticrew-events-bridge' ),
				'default' => 20,
				'option'  => 'gaticrew_attendees_per_page',
			)
		);
	}

	/**
	 * Persists attendee per-page screen option.
	 *
	 * @param mixed  $status Screen option status.
	 * @param string $option Option name.
	 * @param int    $value Requested value.
	 * @return int
	 */
	public function set_attendees_per_page_option( $status, $option, $value ) {
		unset( $status );

		if ( 'gaticrew_attendees_per_page' !== $option ) {
			return (int) $value;
		}

		return min( 100, max( 1, absint( $value ) ) );
	}

	/**
	 * Loads admin CSS for the attendee screen.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
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
	 * Renders the attendee listing screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gaticrew-events-bridge' ) );
		}

		require_once GATICREW_EVENTS_BRIDGE_PATH . 'admin/class-gaticrew-events-bridge-attendees-list-table.php';

		$this->process_bulk_action();

		if ( $this->is_details_request() ) {
			$this->render_details_page();
			return;
		}

		$list_table = new GatiCrew_Events_Bridge_Attendees_List_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap gaticrew-attendees-admin">
			<h1><?php echo esc_html__( 'GatiCrew Attendees', 'gaticrew-events-bridge' ); ?></h1>
			<?php $this->render_admin_notice(); ?>
			<?php $list_table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<?php
				$list_table->search_box( __( 'Search Attendees', 'gaticrew-events-bridge' ), 'gaticrew-attendees' );
				$list_table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles secure attendee bulk actions.
	 *
	 * @return void
	 */
	private function process_bulk_action() {
		$action = $this->get_requested_action();

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( 'bulk-gaticrew_attendees' );

		$ids = $this->get_requested_attendee_ids();

		if ( empty( $ids ) ) {
			$this->redirect_after_action( $action, 0 );
		}

		switch ( $action ) {
			case 'delete':
				$count = $this->repository->delete_by_ids( $ids );
				break;
			case 'mark_confirmed':
				$count = $this->repository->update_status_by_ids( $ids, GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CONFIRMED );
				break;
			case 'mark_cancelled':
				$count = $this->repository->update_status_by_ids( $ids, GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CANCELLED );
				break;
			case 'mark_checked_in':
				$count = $this->repository->update_status_by_ids( $ids, GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN );
				break;
			case 'admin_checkin':
				$count = $this->repository->update_status_by_ids( $ids, GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN );
				break;
			default:
				return;
		}

		$this->redirect_after_action( $action, $count );
	}

	/**
	 * Returns the requested bulk action.
	 *
	 * @return string
	 */
	private function get_requested_action() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( '' === $action || '-1' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		}

		if ( '-1' === $action ) {
			return '';
		}

		return in_array( $action, array( 'delete', 'mark_confirmed', 'mark_cancelled', 'mark_checked_in', 'admin_checkin' ), true ) ? $action : '';
	}

	/**
	 * Returns selected attendee IDs.
	 *
	 * @return array
	 */
	private function get_requested_attendee_ids() {
		$ids = isset( $_REQUEST['attendee'] ) ? (array) wp_unslash( $_REQUEST['attendee'] ) : array();

		return array_values(
			array_filter(
				array_map( 'absint', $ids )
			)
		);
	}

	/**
	 * Redirects after mutations to avoid duplicate form submission.
	 *
	 * @param string $action Completed action.
	 * @param int    $count Affected row count.
	 * @return void
	 */
	private function redirect_after_action( $action, $count ) {
		$url = remove_query_arg(
			array( '_wpnonce', '_wp_http_referer', 'action', 'action2', 'attendee', 'updated', 'bulk_action' )
		);

		$url = add_query_arg(
			array(
				'updated'     => absint( $count ),
				'bulk_action' => sanitize_key( $action ),
			),
			$url
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Renders action feedback.
	 *
	 * @return void
	 */
	private function render_admin_notice() {
		if ( ! isset( $_GET['updated'], $_GET['bulk_action'] ) ) {
			return;
		}

		$count  = absint( $_GET['updated'] );
		$action = sanitize_key( wp_unslash( $_GET['bulk_action'] ) );

		$messages = array(
			'delete'          => _n( '%d attendee deleted.', '%d attendees deleted.', $count, 'gaticrew-events-bridge' ),
			'mark_confirmed'  => _n( '%d attendee marked confirmed.', '%d attendees marked confirmed.', $count, 'gaticrew-events-bridge' ),
			'mark_cancelled'  => _n( '%d attendee marked cancelled.', '%d attendees marked cancelled.', $count, 'gaticrew-events-bridge' ),
			'mark_checked_in' => _n( '%d attendee marked checked in.', '%d attendees marked checked in.', $count, 'gaticrew-events-bridge' ),
			'admin_checkin'   => _n( '%d attendee marked checked in.', '%d attendees marked checked in.', $count, 'gaticrew-events-bridge' ),
		);

		if ( ! isset( $messages[ $action ] ) ) {
			return;
		}

		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( sprintf( $messages[ $action ], $count ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Determines whether the current request is the attendee details screen.
	 *
	 * @return bool
	 */
	private function is_details_request() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		return 'view' === $action && ! empty( $_GET['attendee'] );
	}

	/**
	 * Renders a single attendee details and QR ticket view.
	 *
	 * @return void
	 */
	private function render_details_page() {
		$attendee_id = isset( $_GET['attendee'] ) ? absint( wp_unslash( $_GET['attendee'] ) ) : 0;

		if ( ! $attendee_id || ! check_admin_referer( 'gaticrew_view_attendee_' . $attendee_id ) ) {
			wp_die( esc_html__( 'Invalid attendee request.', 'gaticrew-events-bridge' ) );
		}

		$attendee = $this->repository->get_by_id( $attendee_id );

		if ( empty( $attendee ) ) {
			wp_die( esc_html__( 'Attendee not found.', 'gaticrew-events-bridge' ) );
		}

		$booking_id     = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';
		$attendee_group = $this->repository->get_group_by_order_booking( isset( $attendee['order_id'] ) ? absint( $attendee['order_id'] ) : 0, $booking_id );
		$attendee_group = empty( $attendee_group ) ? array( $attendee ) : $attendee_group;
		$token          = isset( $attendee['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] ) : '';
		$attendee_names = $this->get_names_from_group( $attendee_group );
		$event_id       = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
		$event_name     = ! empty( $attendee['event_name'] ) ? sanitize_text_field( $attendee['event_name'] ) : GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
		$event_date     = $event_id ? GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) : '';
		$booking_status = ! empty( $attendee['booking_status'] ) ? sanitize_key( $attendee['booking_status'] ) : '';
		$statuses       = GatiCrew_Events_Bridge_Attendees_Repository::get_statuses();
		$status_label   = isset( $statuses[ $booking_status ] ) ? $statuses[ $booking_status ] : $booking_status;
		$back_url       = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$checkin_url    = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => self::MENU_SLUG,
					'action'     => 'admin_checkin',
					'attendee'   => $attendee_id,
				),
				admin_url( 'admin.php' )
			),
			'bulk-gaticrew_attendees'
		);
		$pdf_url        = GatiCrew_Events_Bridge_PDF_Tickets::get_admin_download_url( $attendee_id );
		?>
		<div class="wrap gaticrew-attendees-admin gaticrew-attendee-details">
			<h1><?php echo esc_html__( 'Attendee Details', 'gaticrew-events-bridge' ); ?></h1>
			<p>
				<a class="button" href="<?php echo esc_url( $back_url ); ?>">
					<?php echo esc_html__( 'Back to Attendees', 'gaticrew-events-bridge' ); ?>
				</a>
			</p>

			<div class="gaticrew-attendee-details__grid">
				<div class="gaticrew-attendee-details__panel">
					<h2><?php echo esc_html__( 'Booking', 'gaticrew-events-bridge' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $booking_id ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Attendees', 'gaticrew-events-bridge' ); ?></th>
								<td>
									<ol class="gaticrew-admin-attendee-group">
										<?php foreach ( $attendee_names as $attendee_name ) : ?>
											<li><?php echo esc_html( $attendee_name ); ?></li>
										<?php endforeach; ?>
									</ol>
								</td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Email', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $attendee['attendee_email'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Phone', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $attendee['attendee_phone'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( max( absint( $attendee['quantity'] ), count( $attendee_names ) ) ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Status', 'gaticrew-events-bridge' ); ?></th>
								<td>
									<mark class="gaticrew-attendee-status gaticrew-attendee-status--<?php echo esc_attr( $booking_status ); ?>">
										<span><?php echo esc_html( $status_label ); ?></span>
									</mark>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="gaticrew-attendee-details__panel">
					<h2><?php echo esc_html__( 'Event', 'gaticrew-events-bridge' ); ?></h2>
					<table class="widefat striped">
						<tbody>
							<tr>
								<th><?php echo esc_html__( 'Event Name', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $event_name ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Event Date', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $event_date ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Order ID', 'gaticrew-events-bridge' ); ?></th>
								<td>#<?php echo esc_html( absint( $attendee['order_id'] ) ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="gaticrew-attendee-details__panel gaticrew-attendee-details__qr-panel">
					<h2><?php echo esc_html__( 'QR Ticket', 'gaticrew-events-bridge' ); ?></h2>
					<?php if ( $token ) : ?>
						<img class="gaticrew-attendee-details__qr" src="<?php echo esc_url( ! empty( $attendee['qr_code'] ) ? $attendee['qr_code'] : GatiCrew_Events_Bridge_QR_Tokens::get_qr_image_url( $token ) ); ?>" alt="<?php echo esc_attr__( 'QR Ticket', 'gaticrew-events-bridge' ); ?>">
						<p><code><?php echo esc_html( $token ); ?></code></p>
						<p>
							<a href="<?php echo esc_url( GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $token ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html__( 'Open validation URL', 'gaticrew-events-bridge' ); ?>
							</a>
						</p>
						<?php if ( $pdf_url ) : ?>
							<p>
								<a class="button" href="<?php echo esc_url( $pdf_url ); ?>">
									<?php echo esc_html__( 'Download Ticket PDF', 'gaticrew-events-bridge' ); ?>
								</a>
							</p>
						<?php endif; ?>
						<?php if ( GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN !== $booking_status && GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CANCELLED !== $booking_status ) : ?>
							<p>
								<a class="button button-primary" href="<?php echo esc_url( $checkin_url ); ?>">
									<?php echo esc_html__( 'Mark Checked In', 'gaticrew-events-bridge' ); ?>
								</a>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p><?php echo esc_html__( 'QR token is pending for this attendee.', 'gaticrew-events-bridge' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Extracts all attendee names from one booking row or legacy group rows.
	 *
	 * @param array $group Attendee rows.
	 * @return array
	 */
	private function get_names_from_group( array $group ) {
		$names = array();

		foreach ( $group as $attendee ) {
			if ( ! empty( $attendee['attendee_names'] ) && is_array( $attendee['attendee_names'] ) ) {
				foreach ( $attendee['attendee_names'] as $name ) {
					$name = sanitize_text_field( $name );

					if ( '' !== $name ) {
						$names[] = $name;
					}
				}

				continue;
			}

			$name = isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '';

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( $names );
	}
}
