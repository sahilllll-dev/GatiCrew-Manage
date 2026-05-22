<?php
/**
 * Operator QR scanner dashboard.
 *
 * This screen is intentionally separate from attendee management so gate staff
 * can scan quickly without loading the heavier WP_List_Table workflow.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Scanner_Admin {
	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'gaticrew-checkin';

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
	 * Registers dashboard hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the operator check-in menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( class_exists( 'GatiCrew_Events_Bridge_Ticket_Orders_Admin' ) ) {
			$this->screen_hook = add_submenu_page(
				GatiCrew_Events_Bridge_Ticket_Orders_Admin::PARENT_MENU_SLUG,
				__( 'GatiCrew Check-In', 'gaticrew-events-bridge' ),
				__( 'Check-In', 'gaticrew-events-bridge' ),
				GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_CHECKINS,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		} else {
			$this->screen_hook = add_menu_page(
				__( 'GatiCrew Check-In', 'gaticrew-events-bridge' ),
				__( 'GatiCrew Check-In', 'gaticrew-events-bridge' ),
				GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_CHECKINS,
				self::MENU_SLUG,
				array( $this, 'render_page' ),
				'dashicons-visibility',
				57
			);
		}
	}

	/**
	 * Loads scanner assets only on the operator dashboard.
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

		wp_enqueue_script(
			'gaticrew-events-bridge-html5-qrcode',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/vendor/html5-qrcode.min.js',
			array(),
			'2.3.8',
			true
		);

		wp_enqueue_script(
			'gaticrew-events-bridge-checkin-scanner',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/js/checkin-scanner.js',
			array( 'gaticrew-events-bridge-html5-qrcode' ),
			GATICREW_EVENTS_BRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'gaticrew-events-bridge-checkin-scanner',
			'GatiCrewCheckInScanner',
			array(
				'validateUrl' => esc_url_raw( rest_url( 'gaticrew/v1/checkin/validate' ) ),
				'approveUrl'  => esc_url_raw( rest_url( 'gaticrew/v1/checkin/approve' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'messages'    => array(
					'selectEvent'      => __( 'Select an event before scanning.', 'gaticrew-events-bridge' ),
					'starting'         => __( 'Starting camera...', 'gaticrew-events-bridge' ),
					'ready'            => __( 'Scanner ready.', 'gaticrew-events-bridge' ),
					'cameraError'      => __( 'Camera access failed. Check browser permissions and HTTPS/localhost access.', 'gaticrew-events-bridge' ),
					'invalidQr'        => __( 'Invalid Ticket', 'gaticrew-events-bridge' ),
					'validating'       => __( 'Validating ticket...', 'gaticrew-events-bridge' ),
					'approving'        => __( 'Approving check-in...', 'gaticrew-events-bridge' ),
					'networkError'     => __( 'Validation failed. Check your connection and try again.', 'gaticrew-events-bridge' ),
					'stopped'          => __( 'Scanner stopped.', 'gaticrew-events-bridge' ),
					'unsupported'      => __( 'QR scanner library could not be loaded.', 'gaticrew-events-bridge' ),
					'resumeAfterScan'  => __( 'Start scanner again for the next attendee.', 'gaticrew-events-bridge' ),
				),
			)
		);
	}

	/**
	 * Renders the mobile-first operator scanner dashboard.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_CHECKINS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'gaticrew-events-bridge' ) );
		}

		$events = $this->get_events_for_scanner();
		?>
		<div class="wrap gaticrew-checkin-dashboard">
			<h1><?php echo esc_html__( 'GatiCrew Check-In', 'gaticrew-events-bridge' ); ?></h1>

			<div class="gaticrew-checkin-dashboard__layout">
				<section class="gaticrew-checkin-dashboard__scanner" aria-label="<?php echo esc_attr__( 'QR scanner', 'gaticrew-events-bridge' ); ?>">
					<div class="gaticrew-checkin-dashboard__toolbar">
						<label class="gaticrew-checkin-dashboard__event-label" for="gaticrew-checkin-event">
							<?php echo esc_html__( 'Event', 'gaticrew-events-bridge' ); ?>
						</label>
						<select id="gaticrew-checkin-event" class="gaticrew-checkin-dashboard__event-select">
							<option value=""><?php echo esc_html__( 'Select event', 'gaticrew-events-bridge' ); ?></option>
							<?php foreach ( $events as $event ) : ?>
								<?php
								$event_id   = isset( $event['event_id'] ) ? absint( $event['event_id'] ) : 0;
								$event_name = ! empty( $event['event_name'] ) ? sanitize_text_field( $event['event_name'] ) : GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
								?>
								<?php if ( $event_id && '' !== $event_name ) : ?>
									<option value="<?php echo esc_attr( $event_id ); ?>"><?php echo esc_html( $event_name ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="gaticrew-checkin-dashboard__actions">
						<button type="button" class="button button-primary" id="gaticrew-checkin-start">
							<?php echo esc_html__( 'Start Scanner', 'gaticrew-events-bridge' ); ?>
						</button>
						<button type="button" class="button" id="gaticrew-checkin-stop" disabled>
							<?php echo esc_html__( 'Stop Scanner', 'gaticrew-events-bridge' ); ?>
						</button>
					</div>

					<div id="gaticrew-scanner-reader" class="gaticrew-checkin-dashboard__reader">
						<div class="gaticrew-checkin-dashboard__reader-empty">
							<?php echo esc_html__( 'Select an event and start the scanner.', 'gaticrew-events-bridge' ); ?>
						</div>
					</div>

					<div id="gaticrew-checkin-status" class="gaticrew-checkin-dashboard__status" role="status" aria-live="polite"></div>
				</section>

				<section id="gaticrew-checkin-result" class="gaticrew-checkin-result gaticrew-checkin-result--empty" aria-live="polite">
					<div class="gaticrew-checkin-result__header">
						<span class="gaticrew-checkin-result__state" data-gaticrew-result-state><?php echo esc_html__( 'Waiting', 'gaticrew-events-bridge' ); ?></span>
						<h2 data-gaticrew-result-message><?php echo esc_html__( 'No ticket scanned', 'gaticrew-events-bridge' ); ?></h2>
					</div>

					<div class="gaticrew-checkin-result__details" data-gaticrew-result-details hidden>
						<dl>
							<div>
								<dt><?php echo esc_html__( 'Attendees', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="attendee_names"></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="ticket_quantity"></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="booking_id"></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Event', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="event_name"></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Event Date', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="event_date"></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Status', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="attendee_status"></dd>
							</div>
							<div>
								<dt><?php echo esc_html__( 'Customer Email', 'gaticrew-events-bridge' ); ?></dt>
								<dd data-gaticrew-field="customer_email"></dd>
							</div>
						</dl>
					</div>

					<div class="gaticrew-checkin-result__actions">
						<button type="button" class="button button-primary button-hero" id="gaticrew-checkin-approve" hidden>
							<?php echo esc_html__( 'Approve Check-In', 'gaticrew-events-bridge' ); ?>
						</button>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns published linked events plus events that already have attendees.
	 *
	 * @return array
	 */
	private function get_events_for_scanner() {
		$options = array();

		foreach ( $this->repository->get_events_for_filter() as $event ) {
			$event_id = isset( $event['event_id'] ) ? absint( $event['event_id'] ) : 0;

			if ( ! $event_id ) {
				continue;
			}

			$options[ $event_id ] = ! empty( $event['event_name'] )
				? sanitize_text_field( $event['event_name'] )
				: GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
		}

		$linked_events = get_posts(
			array(
				'post_type'              => GatiCrew_Events_Bridge_Events::get_event_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'fields'                 => 'ids',
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $linked_events as $event_id ) {
			$event_id = absint( $event_id );

			if ( ! $event_id || isset( $options[ $event_id ] ) ) {
				continue;
			}

			$title = GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );

			if ( '' !== $title ) {
				$options[ $event_id ] = $title;
			}
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		$events = array();

		foreach ( $options as $event_id => $event_name ) {
			$events[] = array(
				'event_id'   => absint( $event_id ),
				'event_name' => sanitize_text_field( $event_name ),
			);
		}

		return $events;
	}
}
