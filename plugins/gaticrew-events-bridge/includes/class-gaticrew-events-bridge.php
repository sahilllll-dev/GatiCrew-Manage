<?php
/**
 * Main plugin orchestrator.
 *
 * This class wires together the small feature modules so each concern remains
 * isolated: admin UI, REST output, dependency checks, and shared helpers.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-events.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-products.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-bookings.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'includes/class-gaticrew-events-bridge-ticket-assets.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'database/class-gaticrew-events-bridge-schema.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'roles/class-gaticrew-events-bridge-role-manager.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'permissions/class-gaticrew-events-bridge-admin-permissions.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'qr/class-gaticrew-events-bridge-qr-tokens.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'qr/class-gaticrew-events-bridge-qr-code.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'attendees/class-gaticrew-events-bridge-attendees-repository.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'qr/class-gaticrew-events-bridge-qr-controller.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'checkin/class-gaticrew-events-bridge-checkin-controller.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'pdf/class-gaticrew-events-bridge-pdf-tickets.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'orders/class-gaticrew-events-bridge-order-manager.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'admin/class-gaticrew-events-bridge-admin.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'admin/class-gaticrew-events-bridge-attendees-admin.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'scanner/class-gaticrew-events-bridge-scanner-admin.php';
require_once GATICREW_EVENTS_BRIDGE_PATH . 'api/class-gaticrew-events-bridge-checkin-rest-controller.php';

final class GatiCrew_Events_Bridge {
	/**
	 * Event meta key used to store the linked WooCommerce product ID.
	 */
	const META_KEY_TICKET_PRODUCT_ID = '_gaticrew_ticket_product_id';

	/**
	 * WooCommerce order meta keys populated when a linked event product is purchased.
	 */
	const ORDER_META_EVENT_ID       = '_gaticrew_event_id';
	const ORDER_META_EVENT_NAME     = '_gaticrew_event_name';
	const ORDER_META_BOOKING_ID     = '_gaticrew_booking_id';
	const ORDER_META_EVENT_DATE     = '_gaticrew_event_date';
	const ORDER_META_EVENT_VENUE    = '_gaticrew_event_venue';
	const ORDER_META_CUSTOMER_NAME  = '_gaticrew_customer_name';
	const ORDER_META_CUSTOMER_EMAIL = '_gaticrew_customer_email';
	const ORDER_META_CUSTOMER_PHONE = '_gaticrew_customer_phone';
	const ORDER_META_ATTENDEE_NAMES = '_gaticrew_attendee_names';

	/**
	 * Singleton instance.
	 *
	 * @var GatiCrew_Events_Bridge|null
	 */
	private static $instance = null;

	/**
	 * Admin module.
	 *
	 * @var GatiCrew_Events_Bridge_Admin
	 */
	private $admin;

	/**
	 * Live check-in REST controller.
	 *
	 * @var GatiCrew_Events_Bridge_Checkin_REST_Controller
	 */
	private $checkin_rest_controller;

	/**
	 * WooCommerce order module.
	 *
	 * @var GatiCrew_Events_Bridge_Order_Manager
	 */
	private $order_manager;

	/**
	 * Attendee admin module.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Admin
	 */
	private $attendees_admin;

	/**
	 * Operator scanner admin module.
	 *
	 * @var GatiCrew_Events_Bridge_Scanner_Admin
	 */
	private $scanner_admin;

	/**
	 * QR image controller.
	 *
	 * @var GatiCrew_Events_Bridge_QR_Controller
	 */
	private $qr_controller;

	/**
	 * Check-in validation controller.
	 *
	 * @var GatiCrew_Events_Bridge_Checkin_Controller
	 */
	private $checkin_controller;

	/**
	 * PDF ticket download controller.
	 *
	 * @var GatiCrew_Events_Bridge_PDF_Tickets
	 */
	private $pdf_tickets;

	/**
	 * Manager role admin restrictions.
	 *
	 * @var GatiCrew_Events_Bridge_Admin_Permissions
	 */
	private $admin_permissions;

	/**
	 * Gets the plugin instance.
	 *
	 * @return GatiCrew_Events_Bridge
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks for all modules.
	 *
	 * @return void
	 */
	public function init() {
		GatiCrew_Events_Bridge_Schema::maybe_upgrade();
		GatiCrew_Events_Bridge_Role_Manager::sync_role();

		add_action( 'init', array( $this, 'register_event_meta' ) );

		$this->admin_permissions       = new GatiCrew_Events_Bridge_Admin_Permissions();
		$this->admin                   = new GatiCrew_Events_Bridge_Admin();
		$this->checkin_rest_controller = new GatiCrew_Events_Bridge_Checkin_REST_Controller();
		$this->order_manager           = new GatiCrew_Events_Bridge_Order_Manager();
		$this->attendees_admin         = new GatiCrew_Events_Bridge_Attendees_Admin();
		$this->scanner_admin           = new GatiCrew_Events_Bridge_Scanner_Admin();
		$this->qr_controller           = new GatiCrew_Events_Bridge_QR_Controller();
		$this->checkin_controller      = new GatiCrew_Events_Bridge_Checkin_Controller();
		$this->pdf_tickets             = new GatiCrew_Events_Bridge_PDF_Tickets();

		$this->admin_permissions->init();
		$this->admin->init();
		$this->checkin_rest_controller->init();
		$this->order_manager->init();
		$this->attendees_admin->init();
		$this->scanner_admin->init();
		$this->qr_controller->init();
		$this->checkin_controller->init();
		$this->pdf_tickets->init();
	}

	/**
	 * Exposes the order module to plugin-owned WooCommerce templates.
	 *
	 * @return GatiCrew_Events_Bridge_Order_Manager
	 */
	public function get_order_manager() {
		return $this->order_manager;
	}

	/**
	 * Registers the event meta field with WordPress for consistent sanitization.
	 *
	 * @return void
	 */
	public function register_event_meta() {
		register_post_meta(
			GatiCrew_Events_Bridge_Events::get_event_post_type(),
			self::META_KEY_TICKET_PRODUCT_ID,
			array(
				'type'              => 'integer',
				'description'       => __( 'Linked WooCommerce ticket product ID.', 'gaticrew-events-bridge' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => false,
				'auth_callback'     => array( $this, 'can_edit_event_meta' ),
			)
		);
	}

	/**
	 * Keeps direct meta writes limited to users who can edit events.
	 *
	 * @return bool
	 */
	public function can_edit_event_meta( $allowed = false, $meta_key = '', $post_id = 0 ) {
		unset( $allowed, $meta_key, $post_id );

		return current_user_can( 'edit_tribe_events' );
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		_doing_it_wrong( __METHOD__, esc_html__( 'Unserializing this class is not allowed.', 'gaticrew-events-bridge' ), '1.0.0' );
	}
}
