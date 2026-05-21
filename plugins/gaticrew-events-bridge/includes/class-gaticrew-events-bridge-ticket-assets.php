<?php
/**
 * Stored ticket asset generation.
 *
 * QR SVGs and PDF tickets are generated once per booking and saved in the
 * WordPress uploads directory. The download controller still enforces access
 * checks before streaming PDFs to customers or managers.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Ticket_Assets {
	/**
	 * Upload subdirectory for generated ticket assets.
	 */
	const UPLOAD_DIR = 'gaticrew-tickets';

	/**
	 * Generates QR and PDF assets for one attendee booking row.
	 *
	 * @param array $attendee Normalized attendee booking row.
	 * @return array
	 */
	public static function generate_for_attendee( array $attendee ) {
		$booking_id = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';

		if ( '' === $booking_id ) {
			return array();
		}

		$qr_code = self::generate_qr_code( $attendee );
		$ticket_pdf = self::generate_pdf_ticket( $attendee, $qr_code );

		return array(
			'qr_code'    => $qr_code,
			'ticket_pdf' => $ticket_pdf,
		);
	}

	/**
	 * Generates and stores a QR SVG for the booking.
	 *
	 * @param array $attendee Attendee booking row.
	 * @return string Public QR image URL.
	 */
	public static function generate_qr_code( array $attendee ) {
		$booking_id = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';

		if ( '' === $booking_id ) {
			return '';
		}

		$target = self::get_upload_target( 'qr', 'gaticrew-qr-' . strtolower( $booking_id ) . '.svg' );

		if ( empty( $target['path'] ) || empty( $target['url'] ) ) {
			return '';
		}

		$validation_url = GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $booking_id );
		$svg            = $validation_url ? GatiCrew_Events_Bridge_QR_Code::render_svg( $validation_url, 4, 4 ) : '';

		if ( '' === $svg ) {
			return '';
		}

		file_put_contents( $target['path'], $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return esc_url_raw( $target['url'] );
	}

	/**
	 * Generates and stores a PDF ticket for the booking.
	 *
	 * @param array  $attendee Attendee booking row.
	 * @param string $qr_code_url Stored QR image URL.
	 * @return string Public PDF file URL.
	 */
	public static function generate_pdf_ticket( array $attendee, $qr_code_url = '' ) {
		self::load_dompdf();

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			return '';
		}

		$booking_id = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';

		if ( '' === $booking_id ) {
			return '';
		}

		$target = self::get_upload_target( 'pdf', self::get_private_pdf_filename( $booking_id ) );

		if ( empty( $target['path'] ) || empty( $target['url'] ) ) {
			return '';
		}

		$ticket = self::prepare_ticket_data( $attendee, $qr_code_url );
		$html   = self::render_pdf_html( $ticket );

		if ( '' === $html ) {
			return '';
		}

		$options = class_exists( '\Dompdf\Options' ) ? new \Dompdf\Options() : null;

		if ( $options ) {
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isHtml5ParserEnabled', true );
			$options->set( 'defaultFont', 'DejaVu Sans' );
		}

		$dompdf = $options ? new \Dompdf\Dompdf( $options ) : new \Dompdf\Dompdf();
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		file_put_contents( $target['path'], $dompdf->output() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return esc_url_raw( $target['url'] );
	}

	/**
	 * Builds the QR data URI used inside PDFs.
	 *
	 * @param string $booking_id Booking ID.
	 * @return string SVG data URI.
	 */
	public static function get_qr_data_uri_for_booking( $booking_id ) {
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $booking_id );

		if ( '' === $booking_id ) {
			return '';
		}

		$validation_url = GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $booking_id );
		$svg            = $validation_url ? GatiCrew_Events_Bridge_QR_Code::render_svg( $validation_url, 4, 4 ) : '';

		return $svg ? 'data:image/svg+xml;base64,' . base64_encode( $svg ) : '';
	}

	/**
	 * Builds sanitized data consumed by the PDF template.
	 *
	 * @param array  $attendee Attendee booking row.
	 * @param string $qr_code_url Stored QR image URL.
	 * @return array
	 */
	private static function prepare_ticket_data( array $attendee, $qr_code_url = '' ) {
		$event_id       = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
		$booking_id     = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';
		$attendee_names = self::sanitize_attendee_names( isset( $attendee['attendee_names'] ) ? $attendee['attendee_names'] : array() );

		if ( empty( $attendee_names ) && ! empty( $attendee['attendee_name'] ) ) {
			$attendee_names[] = sanitize_text_field( $attendee['attendee_name'] );
		}

		// Frontend-created group bookings store one attendee per row; pull the
		// whole booking group so the stored PDF reflects every ticket holder.
		$group_names = self::get_group_attendee_names( $attendee );

		if ( ! empty( $group_names ) ) {
			$attendee_names = $group_names;
		}

		$ticket_quantity = isset( $attendee['quantity'] ) ? max( 1, absint( $attendee['quantity'] ) ) : ( isset( $attendee['ticket_quantity'] ) ? max( 1, absint( $attendee['ticket_quantity'] ) ) : 1 );

		return array(
			'brand_name'      => 'GatiCrew',
			'event_name'      => $event_id ? GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ) : '',
			'event_date'      => $event_id ? GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) : '',
			'event_venue'     => $event_id ? GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) : '',
			'booking_id'      => $booking_id,
			'attendee_name'   => ! empty( $attendee_names[0] ) ? $attendee_names[0] : '',
			'attendee_names'  => $attendee_names,
			'attendee_email'  => isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '',
			'ticket_quantity' => max( $ticket_quantity, count( $attendee_names ) ),
			'qr_token'        => $booking_id,
			'qr_data_uri'     => self::get_qr_data_uri_for_booking( $booking_id ),
			'qr_code_url'     => esc_url_raw( $qr_code_url ),
			'validation_url'  => GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $booking_id ),
			'instructions'    => array(
				__( 'Arrive 30 minutes early', 'gaticrew-events-bridge' ),
				__( 'Carry valid ID', 'gaticrew-events-bridge' ),
				__( 'Helmet mandatory', 'gaticrew-events-bridge' ),
				__( 'Follow crew instructions', 'gaticrew-events-bridge' ),
			),
		);
	}

	/**
	 * Renders the plugin PDF template.
	 *
	 * @param array $ticket Sanitized ticket data.
	 * @return string
	 */
	private static function render_pdf_html( array $ticket ) {
		ob_start();
		include GATICREW_EVENTS_BRIDGE_PATH . 'templates/pdf-ticket.php';

		return (string) ob_get_clean();
	}

	/**
	 * Returns a writable uploads path and public URL.
	 *
	 * @param string $subdir Asset subdirectory.
	 * @param string $filename Asset filename.
	 * @return array
	 */
	private static function get_upload_target( $subdir, $filename ) {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return array();
		}

		$subdir   = sanitize_key( $subdir );
		$filename = sanitize_file_name( $filename );
		$dir      = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_DIR . '/' . $subdir;
		$url      = trailingslashit( $uploads['baseurl'] ) . self::UPLOAD_DIR . '/' . $subdir . '/' . $filename;

		if ( ! wp_mkdir_p( $dir ) ) {
			return array();
		}

		return array(
			'path' => trailingslashit( $dir ) . $filename,
			'url'  => $url,
		);
	}

	/**
	 * Creates a non-guessable PDF filename for stored ticket files.
	 *
	 * @param string $booking_id Booking ID.
	 * @return string
	 */
	private static function get_private_pdf_filename( $booking_id ) {
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $booking_id );
		$signature  = substr( hash_hmac( 'sha256', $booking_id, wp_salt( 'auth' ) ), 0, 16 );

		return 'gaticrew-ticket-' . strtolower( $booking_id ) . '-' . $signature . '.pdf';
	}

	/**
	 * Loads plugin-local DOMPDF autoloader.
	 *
	 * @return void
	 */
	private static function load_dompdf() {
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			return;
		}

		$autoload = GATICREW_EVENTS_BRIDGE_PATH . 'vendor/autoload.php';

		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}
	}

	/**
	 * Returns attendee names from every row in the same booking group.
	 *
	 * @param array $attendee Attendee booking row.
	 * @return array
	 */
	private static function get_group_attendee_names( array $attendee ) {
		if ( ! class_exists( 'GatiCrew_Events_Bridge_Attendees_Repository' ) ) {
			return array();
		}

		$order_id   = isset( $attendee['order_id'] ) ? absint( $attendee['order_id'] ) : 0;
		$booking_id = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';

		if ( ! $order_id || '' === $booking_id ) {
			return array();
		}

		$repository = new GatiCrew_Events_Bridge_Attendees_Repository();
		$group      = $repository->get_group_by_order_booking( $order_id, $booking_id );
		$names      = array();

		foreach ( $group as $row ) {
			$row_names = self::sanitize_attendee_names( isset( $row['attendee_names'] ) ? $row['attendee_names'] : array() );

			if ( empty( $row_names ) && ! empty( $row['attendee_name'] ) ) {
				$row_names[] = sanitize_text_field( $row['attendee_name'] );
			}

			foreach ( $row_names as $name ) {
				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
			}
		}

		return array_values( $names );
	}

	/**
	 * Sanitizes attendee names.
	 *
	 * @param mixed $names Raw attendee names.
	 * @return array
	 */
	private static function sanitize_attendee_names( $names ) {
		if ( is_string( $names ) ) {
			$decoded = json_decode( $names, true );
			$names   = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $names ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );

			if ( '' !== $name ) {
				$sanitized[] = $name;
			}
		}

		return array_values( $sanitized );
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
