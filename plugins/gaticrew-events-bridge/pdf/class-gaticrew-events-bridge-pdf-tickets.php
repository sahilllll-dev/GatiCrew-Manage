<?php
/**
 * PDF ticket generation and secure download controller.
 *
 * DOMPDF is loaded from the plugin vendor autoloader when available. The
 * controller keeps download authorization separate from rendering so future
 * ticket themes, sponsor passes, and VIP layouts can reuse the same access
 * checks.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_PDF_Tickets {
	/**
	 * Admin-post action for ticket PDF downloads.
	 */
	const DOWNLOAD_ACTION = 'gaticrew_ticket_pdf';

	/**
	 * Public download link lifetime in seconds.
	 */
	const PUBLIC_LINK_TTL = 604800;

	/**
	 * Attendee repository.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Repository
	 */
	private $attendees_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->attendees_repository = new GatiCrew_Events_Bridge_Attendees_Repository();
	}

	/**
	 * Registers PDF download handlers.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
	}

	/**
	 * Builds a signed customer-facing download URL for a thank-you page.
	 *
	 * @param array    $attendee Attendee row.
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function get_public_download_url( array $attendee, WC_Order $order ) {
		$attendee_id = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;
		$order_id    = absint( $order->get_id() );
		$order_key   = sanitize_text_field( $order->get_order_key() );
		$expires     = time() + self::PUBLIC_LINK_TTL;

		if ( ! $attendee_id || ! $order_id || '' === $order_key ) {
			return '';
		}

		$signature = self::create_public_signature( $attendee_id, $order_id, $order_key, $expires );

		return add_query_arg(
			array(
				'action'      => self::DOWNLOAD_ACTION,
				'attendee_id' => $attendee_id,
				'order_id'    => $order_id,
				'key'         => rawurlencode( $order_key ),
				'expires'     => $expires,
				'signature'   => $signature,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Builds a nonce-protected admin download URL.
	 *
	 * @param int $attendee_id Attendee row ID.
	 * @return string
	 */
	public static function get_admin_download_url( $attendee_id ) {
		$attendee_id = absint( $attendee_id );

		if ( ! $attendee_id ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::DOWNLOAD_ACTION,
					'attendee_id' => $attendee_id,
					'context'     => 'admin',
				),
				admin_url( 'admin-post.php' )
			),
			'gaticrew_ticket_pdf_' . $attendee_id
		);
	}

	/**
	 * Handles customer and admin PDF downloads.
	 *
	 * @return void
	 */
	public function handle_download() {
		$attendee_id = isset( $_GET['attendee_id'] ) ? absint( wp_unslash( $_GET['attendee_id'] ) ) : 0;

		if ( ! $attendee_id ) {
			$this->deny_download();
		}

		$attendee = $this->attendees_repository->get_by_id( $attendee_id );

		if ( empty( $attendee ) ) {
			$this->deny_download();
		}

		if ( ! $this->can_download_attendee_ticket( $attendee ) ) {
			$this->deny_download();
		}

		$this->stream_pdf( $attendee );
	}

	/**
	 * Checks whether the current requester can download the attendee ticket.
	 *
	 * @param array $attendee Attendee row.
	 * @return bool
	 */
	private function can_download_attendee_ticket( array $attendee ) {
		$attendee_id = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;

		if ( isset( $_GET['context'] ) && 'admin' === sanitize_key( wp_unslash( $_GET['context'] ) ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

			return current_user_can( GatiCrew_Events_Bridge_Role_Manager::CAP_MANAGE_ATTENDEES ) && wp_verify_nonce( $nonce, 'gaticrew_ticket_pdf_' . $attendee_id );
		}

		return $this->can_customer_download_attendee_ticket( $attendee );
	}

	/**
	 * Validates public customer access with order key, expiry, and HMAC.
	 *
	 * @param array $attendee Attendee row.
	 * @return bool
	 */
	private function can_customer_download_attendee_ticket( array $attendee ) {
		$attendee_id = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;
		$order_id    = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$order_key   = isset( $_GET['key'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['key'] ) ) ) : '';
		$expires     = isset( $_GET['expires'] ) ? absint( wp_unslash( $_GET['expires'] ) ) : 0;
		$signature   = isset( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : '';

		if ( ! $attendee_id || ! $order_id || '' === $order_key || ! $expires || '' === $signature ) {
			return false;
		}

		if ( time() > $expires || $order_id !== absint( $attendee['order_id'] ) ) {
			return false;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return false;
		}

		$expected = self::create_public_signature( $attendee_id, $order_id, $order_key, $expires );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Streams the attendee ticket PDF.
	 *
	 * @param array $attendee Attendee row.
	 * @return void
	 */
	private function stream_pdf( array $attendee ) {
		$stored_pdf = $this->get_stored_pdf_path( $attendee );

		if ( $stored_pdf && file_exists( $stored_pdf ) && is_readable( $stored_pdf ) ) {
			$filename = $this->get_ticket_filename( $attendee );

			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . filesize( $stored_pdf ) );
			readfile( $stored_pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}

		$this->load_dompdf();

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die(
				esc_html__( 'DOMPDF is not installed. Run composer install in the gaticrew-events-bridge plugin folder.', 'gaticrew-events-bridge' ),
				esc_html__( 'PDF library unavailable', 'gaticrew-events-bridge' ),
				array( 'response' => 503 )
			);
		}

		$html     = $this->render_ticket_html( $attendee );
		$filename = $this->get_ticket_filename( $attendee );
		$options  = class_exists( '\Dompdf\Options' ) ? new \Dompdf\Options() : null;

		if ( $options ) {
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'defaultFont', 'DejaVu Sans' );
		}

		$dompdf = $options ? new \Dompdf\Dompdf( $options ) : new \Dompdf\Dompdf();
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$dompdf->stream(
			$filename,
			array(
				'Attachment' => true,
			)
		);
		exit;
	}

	/**
	 * Converts the stored uploads URL to a local path after authorization passes.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private function get_stored_pdf_path( array $attendee ) {
		if ( empty( $attendee['ticket_pdf'] ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$url     = esc_url_raw( $attendee['ticket_pdf'] );

		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) || 0 !== strpos( $url, $uploads['baseurl'] ) ) {
			return '';
		}

		$relative = ltrim( substr( $url, strlen( $uploads['baseurl'] ) ), '/' );

		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . $relative;
	}

	/**
	 * Loads plugin-local DOMPDF autoloader.
	 *
	 * @return void
	 */
	private function load_dompdf() {
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			return;
		}

		$autoload = GATICREW_EVENTS_BRIDGE_PATH . 'vendor/autoload.php';

		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}
	}

	/**
	 * Renders the PDF ticket HTML template.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private function render_ticket_html( array $attendee ) {
		$ticket = $this->prepare_ticket_data( $attendee );

		ob_start();
		include GATICREW_EVENTS_BRIDGE_PATH . 'templates/pdf-ticket.php';

		return (string) ob_get_clean();
	}

	/**
	 * Builds sanitized ticket data for template rendering.
	 *
	 * @param array $attendee Attendee row.
	 * @return array
	 */
	private function prepare_ticket_data( array $attendee ) {
		$event_id       = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
		$event_name     = ! empty( $attendee['event_name'] ) ? sanitize_text_field( $attendee['event_name'] ) : GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
		$event_date     = $event_id ? GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) : '';
		$event_venue    = $event_id ? GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) : '';
		$qr_token       = isset( $attendee['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] ) : '';
		$validation_url = $qr_token ? GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $qr_token ) : '';
		$qr_svg         = $validation_url ? GatiCrew_Events_Bridge_QR_Code::render_svg( $validation_url, 4, 4 ) : '';
		$group          = $qr_token ? $this->attendees_repository->get_group_by_qr_token( $qr_token ) : array( $attendee );
		$attendee_names = $this->get_attendee_names_from_group( $group );

		if ( empty( $attendee_names ) && ! empty( $attendee['attendee_name'] ) ) {
			$attendee_names = array( sanitize_text_field( $attendee['attendee_name'] ) );
		}

		$ticket_quantity = isset( $attendee['ticket_quantity'] ) ? max( 1, absint( $attendee['ticket_quantity'] ) ) : 1;
		$ticket_quantity = max( $ticket_quantity, count( $attendee_names ) );

		return array(
			'brand_name'      => 'GatiCrew',
			'event_name'      => $event_name,
			'event_date'      => $event_date,
			'event_venue'     => $event_venue,
			'booking_id'      => isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '',
			'attendee_name'   => isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '',
			'attendee_names'  => $attendee_names,
			'attendee_email'  => isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '',
			'ticket_quantity' => $ticket_quantity,
			'qr_token'        => $qr_token,
			'qr_data_uri'     => $qr_svg ? 'data:image/svg+xml;base64,' . base64_encode( $qr_svg ) : '',
			'validation_url'  => $validation_url,
			'instructions'    => array(
				__( 'Arrive 30 minutes early', 'gaticrew-events-bridge' ),
				__( 'Carry valid ID', 'gaticrew-events-bridge' ),
				__( 'Helmet mandatory', 'gaticrew-events-bridge' ),
				__( 'Follow crew instructions', 'gaticrew-events-bridge' ),
			),
		);
	}

	/**
	 * Returns all attendee names attached to the PDF booking group.
	 *
	 * @param array $group Attendee rows.
	 * @return array
	 */
	private function get_attendee_names_from_group( array $group ) {
		$names = array();

		foreach ( $group as $attendee ) {
			if ( ! empty( $attendee['attendee_names'] ) && is_array( $attendee['attendee_names'] ) ) {
				foreach ( $attendee['attendee_names'] as $attendee_name ) {
					$attendee_name = sanitize_text_field( $attendee_name );

					if ( '' !== $attendee_name ) {
						$names[] = $attendee_name;
					}
				}

				continue;
			}

			$name = isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '';

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Builds a stable ticket filename.
	 *
	 * @param array $attendee Attendee row.
	 * @return string
	 */
	private function get_ticket_filename( array $attendee ) {
		$booking_id = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : 'ticket';

		return sanitize_file_name( 'gaticrew-ticket-' . strtolower( $booking_id ) . '.pdf' );
	}

	/**
	 * Creates public URL signature.
	 *
	 * @param int    $attendee_id Attendee row ID.
	 * @param int    $order_id Order ID.
	 * @param string $order_key WooCommerce order key.
	 * @param int    $expires Expiry timestamp.
	 * @return string
	 */
	private static function create_public_signature( $attendee_id, $order_id, $order_key, $expires ) {
		$payload = implode( '|', array( absint( $attendee_id ), absint( $order_id ), sanitize_text_field( $order_key ), absint( $expires ) ) );

		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Stops unauthorized downloads without leaking ticket existence.
	 *
	 * @return void
	 */
	private function deny_download() {
		wp_die(
			esc_html__( 'Ticket download is not available.', 'gaticrew-events-bridge' ),
			esc_html__( 'Ticket unavailable', 'gaticrew-events-bridge' ),
			array( 'response' => 403 )
		);
	}
}
