<?php
/**
 * Database schema management.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Schema {
	/**
	 * Attendee table version for future migrations.
	 */
	const ATTENDEES_TABLE_VERSION = '2.0.0';

	/**
	 * Returns the attendees table name with the active WordPress prefix.
	 *
	 * @return string
	 */
	public static function get_attendees_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'gaticrew_attendees';
	}

	/**
	 * Creates or updates plugin tables using dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_attendees_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		self::drop_legacy_order_event_unique_key( $table_name );
		self::drop_legacy_booking_unique_key( $table_name );
		self::drop_legacy_qr_token_unique_key( $table_name );

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id varchar(32) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attendee_names longtext NOT NULL,
			attendee_email varchar(191) NOT NULL DEFAULT '',
			attendee_phone varchar(40) NOT NULL DEFAULT '',
			quantity int(10) unsigned NOT NULL DEFAULT 1,
			qr_code text NULL,
			ticket_pdf text NULL,
			status varchar(40) NOT NULL DEFAULT 'confirmed',
			checked_in tinyint(1) unsigned NOT NULL DEFAULT 0,
			checked_in_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			ticket_index int(10) unsigned NOT NULL DEFAULT 1,
			attendee_name varchar(191) NOT NULL DEFAULT '',
			ticket_quantity int(10) unsigned NOT NULL DEFAULT 1,
			booking_status varchar(40) NOT NULL DEFAULT 'confirmed',
			qr_token varchar(64) DEFAULT NULL,
			qr_status varchar(40) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY order_id (order_id),
			KEY order_booking (order_id,booking_id),
			KEY event_id (event_id),
			KEY product_id (product_id),
			KEY attendee_email (attendee_email),
			KEY status (status),
			KEY booking_status (booking_status),
			KEY qr_token (qr_token),
			KEY qr_status (qr_status),
			KEY checked_in (checked_in),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		self::sync_legacy_rows_to_booking_schema( $table_name );

		update_option( 'gaticrew_events_bridge_attendees_table_version', self::ATTENDEES_TABLE_VERSION, false );
	}

	/**
	 * Backfills new booking-row columns for databases upgraded from early builds.
	 *
	 * @param string $table_name Attendee table name.
	 * @return void
	 */
	private static function sync_legacy_rows_to_booking_schema( $table_name ) {
		global $wpdb;

		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ) {
			return;
		}

		$rows = $wpdb->get_results(
			"SELECT id, booking_id, attendee_name, ticket_quantity, booking_status, qr_token, qr_status
			FROM {$table_name}
			WHERE attendee_names = '' OR attendee_names IS NULL OR status = '' OR status IS NULL",
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$status     = ! empty( $row['booking_status'] ) ? sanitize_key( $row['booking_status'] ) : 'confirmed';
			$checked_in = 'checked-in' === $status ? 1 : 0;
			$booking_id = ! empty( $row['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $row['booking_id'] ) : '';

			if ( ! $booking_id && ! empty( $row['qr_token'] ) ) {
				$booking_id = GatiCrew_Events_Bridge_Bookings::generate_booking_id();
			}

			$wpdb->update(
				$table_name,
				array(
					'booking_id'     => $booking_id,
					'attendee_names' => wp_json_encode( array_filter( array( sanitize_text_field( $row['attendee_name'] ) ) ) ),
					'quantity'       => ! empty( $row['ticket_quantity'] ) ? max( 1, absint( $row['ticket_quantity'] ) ) : 1,
					'status'         => $status,
					'checked_in'     => $checked_in,
					'checked_in_at'  => $checked_in ? current_time( 'mysql' ) : null,
					'qr_token'       => $booking_id,
					'qr_status'      => $checked_in ? 'used' : ( ! empty( $row['qr_status'] ) ? sanitize_key( $row['qr_status'] ) : 'active' ),
				),
				array( 'id' => absint( $row['id'] ) ),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Legacy schema kept only in comments for reference.
	 *
	 * @return void
	 */
	private static function unused_legacy_schema_reference() {
		/*
			ticket_index int(10) unsigned NOT NULL DEFAULT 1,
			attendee_name varchar(191) NOT NULL,
			attendee_email varchar(191) NOT NULL,
			attendee_phone varchar(40) NOT NULL DEFAULT '',
			ticket_quantity int(10) unsigned NOT NULL DEFAULT 1,
			booking_status varchar(40) NOT NULL DEFAULT 'confirmed',
			qr_token varchar(64) DEFAULT NULL,
			qr_status varchar(40) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_booking_ticket (order_id,booking_id,ticket_index),
			KEY booking_id (booking_id),
			KEY qr_token (qr_token),
			KEY order_id (order_id),
			KEY order_booking (order_id,booking_id),
			KEY event_id (event_id),
			KEY attendee_email (attendee_email),
			KEY booking_status (booking_status),
			KEY qr_status (qr_status),
			KEY created_at (created_at)
		*/
	}

	/**
	 * Runs schema updates when an existing installation receives a plugin upgrade.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current_version = get_option( 'gaticrew_events_bridge_attendees_table_version' );

		if ( self::ATTENDEES_TABLE_VERSION !== $current_version ) {
			self::create_tables();
		}
	}

	/**
	 * Removes the previous order/event unique key so one order can store one row
	 * per linked event order item, including multiple items for the same event.
	 *
	 * @param string $table_name Attendee table name.
	 * @return void
	 */
	private static function drop_legacy_order_event_unique_key( $table_name ) {
		global $wpdb;

		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table_name} WHERE Key_name = %s",
				'order_event'
			)
		);

		if ( $exists ) {
			$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX order_event" );
		}
	}

	/**
	 * Removes the old booking_id unique key so group bookings can store one row
	 * per attendee while sharing a booking ID.
	 *
	 * @param string $table_name Attendee table name.
	 * @return void
	 */
	private static function drop_legacy_booking_unique_key( $table_name ) {
		global $wpdb;

		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table_name} WHERE Key_name = %s AND Non_unique = 0",
				'booking_id'
			)
		);

		if ( $exists ) {
			$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX booking_id" );
		}
	}

	/**
	 * Removes the old qr_token unique key so all attendees in a group booking can
	 * share one QR pass.
	 *
	 * @param string $table_name Attendee table name.
	 * @return void
	 */
	private static function drop_legacy_qr_token_unique_key( $table_name ) {
		global $wpdb;

		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ) {
			return;
		}

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW INDEX FROM {$table_name} WHERE Key_name = %s AND Non_unique = 0",
				'qr_token'
			)
		);

		if ( $exists ) {
			$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX qr_token" );
		}
	}

	/**
	 * Backfills QR tokens for attendee rows created before QR support existed.
	 *
	 * @param string $table_name Attendee table name.
	 * @return void
	 */
	private static function populate_missing_qr_tokens( $table_name ) {
		global $wpdb;

		if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ) {
			return;
		}

		$has_qr_token = $wpdb->get_var( "SHOW COLUMNS FROM {$table_name} LIKE 'qr_token'" );

		if ( ! $has_qr_token ) {
			return;
		}

		$ids = $wpdb->get_col( "SELECT id FROM {$table_name} WHERE qr_token IS NULL OR qr_token = ''" );

		foreach ( (array) $ids as $id ) {
			$wpdb->update(
				$table_name,
				array(
					'qr_token'  => self::generate_unique_qr_token( $table_name ),
					'qr_status' => 'active',
				),
				array( 'id' => absint( $id ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Generates a unique QR token during schema migration.
	 *
	 * @param string $table_name Attendee table name.
	 * @return string
	 */
	private static function generate_unique_qr_token( $table_name ) {
		global $wpdb;

		for ( $attempt = 0; $attempt < 30; $attempt++ ) {
			$token = self::generate_qr_token();
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE qr_token = %s LIMIT 1",
					$token
				)
			);

			if ( ! $exists ) {
				return $token;
			}
		}

		return 'GCQR-' . strtoupper( wp_generate_password( 20, false, false ) );
	}

	/**
	 * Generates a secure migration token without depending on runtime classes.
	 *
	 * @return string
	 */
	private static function generate_qr_token() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$token    = '';

		for ( $i = 0; $i < 12; $i++ ) {
			$token .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return 'GCQR-' . $token;
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
