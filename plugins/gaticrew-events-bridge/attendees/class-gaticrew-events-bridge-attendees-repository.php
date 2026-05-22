<?php
/**
 * Attendee persistence layer.
 *
 * The attendee table stores one booking row per linked WooCommerce order item.
 * Attendee names live as a JSON array on that booking row, which keeps group
 * passes, QR validation, PDF generation, and admin bulk actions aligned around
 * one booking identity.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Attendees_Repository {
	/**
	 * Allowed booking statuses.
	 */
	const STATUS_CONFIRMED  = 'confirmed';
	const STATUS_CANCELLED  = 'cancelled';
	const STATUS_CHECKED_IN = 'checked-in';

	/**
	 * Returns available attendee statuses.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return array(
			self::STATUS_CONFIRMED  => __( 'Confirmed', 'gaticrew-events-bridge' ),
			self::STATUS_CANCELLED  => __( 'Cancelled', 'gaticrew-events-bridge' ),
			self::STATUS_CHECKED_IN => __( 'Checked In', 'gaticrew-events-bridge' ),
		);
	}

	/**
	 * Creates one booking row unless the order/booking pair already exists.
	 *
	 * @param array $data Raw attendee booking data.
	 * @return int Attendee booking row ID.
	 */
	public function create( array $data ) {
		global $wpdb;

		$prepared = $this->prepare_attendee_data( $data );

		if ( empty( $prepared['order_id'] ) || empty( $prepared['event_id'] ) || empty( $prepared['booking_id'] ) ) {
			return 0;
		}

		$allow_group_duplicate = ! empty( $data['allow_group_duplicate'] );
		$existing_id           = $allow_group_duplicate
			? $this->get_id_by_order_booking_ticket_index( $prepared['order_id'], $prepared['booking_id'], $prepared['ticket_index'] )
			: $this->get_id_by_order_booking( $prepared['order_id'], $prepared['booking_id'] );

		if ( $existing_id ) {
			return $existing_id;
		}

		$inserted = $wpdb->insert(
			GatiCrew_Events_Bridge_Schema::get_attendees_table_name(),
			$prepared,
			array(
				'%s',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $inserted ) {
			return $allow_group_duplicate
				? $this->get_id_by_order_booking_ticket_index( $prepared['order_id'], $prepared['booking_id'], $prepared['ticket_index'] )
				: $this->get_id_by_order_booking( $prepared['order_id'], $prepared['booking_id'] );
		}

		$attendee_id = absint( $wpdb->insert_id );
		$attendee    = $this->get_by_id( $attendee_id );

		if ( $attendee && class_exists( 'GatiCrew_Events_Bridge_Ticket_Assets' ) ) {
			$assets = GatiCrew_Events_Bridge_Ticket_Assets::generate_for_attendee( $attendee );

			if ( ! empty( $assets['qr_code'] ) || ! empty( $assets['ticket_pdf'] ) ) {
				$wpdb->update(
					GatiCrew_Events_Bridge_Schema::get_attendees_table_name(),
					array(
						'qr_code'    => ! empty( $assets['qr_code'] ) ? esc_url_raw( $assets['qr_code'] ) : '',
						'ticket_pdf' => ! empty( $assets['ticket_pdf'] ) ? esc_url_raw( $assets['ticket_pdf'] ) : '',
					),
					array( 'id' => $attendee_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		return $attendee_id;
	}

	/**
	 * Checks if an attendee booking exists for an order/event pair.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	public function exists_for_order_event( $order_id, $event_id ) {
		return $this->get_id_by_order_event( $order_id, $event_id ) > 0;
	}

	/**
	 * Checks if an attendee booking exists for an order/booking pair.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id Booking ID.
	 * @return bool
	 */
	public function exists_for_order_booking( $order_id, $booking_id ) {
		return $this->get_id_by_order_booking( $order_id, $booking_id ) > 0;
	}

	/**
	 * Backward-compatible duplicate check for older per-ticket callers.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id Booking ID.
	 * @param int    $ticket_index One-based ticket index.
	 * @return bool
	 */
	public function exists_for_order_booking_ticket_index( $order_id, $booking_id, $ticket_index ) {
		return $this->get_id_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index ) > 0;
	}

	/**
	 * Gets attendee booking rows for a WooCommerce order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function get_by_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			return array();
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE a.order_id = %d
				ORDER BY a.created_at ASC, a.id ASC",
				$order_id
			),
			ARRAY_A
		);

		return $this->normalize_rows( $rows );
	}

	/**
	 * Gets one attendee booking by row ID.
	 *
	 * @param int $attendee_id Attendee row ID.
	 * @return array|null
	 */
	public function get_by_id( $attendee_id ) {
		global $wpdb;

		$attendee_id = absint( $attendee_id );

		if ( ! $attendee_id ) {
			return null;
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE a.id = %d
				LIMIT 1",
				$attendee_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Gets one GatiCrew attendee row by the official TEC attendee post ID.
	 *
	 * @param int $tec_attendee_post_id Event Tickets attendee post ID.
	 * @return array|null
	 */
	public function get_by_tec_attendee_post_id( $tec_attendee_post_id ) {
		global $wpdb;

		$tec_attendee_post_id = absint( $tec_attendee_post_id );

		if ( ! $tec_attendee_post_id ) {
			return null;
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE a.tec_attendee_post_id = %d
				LIMIT 1",
				$tec_attendee_post_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Gets one GatiCrew attendee row by the official TEC security code.
	 *
	 * @param string $security_code Event Tickets security code.
	 * @param int    $event_id Optional event ID constraint.
	 * @return array|null
	 */
	public function get_by_tec_security_code( $security_code, $event_id = 0 ) {
		global $wpdb;

		$security_code = sanitize_text_field( (string) $security_code );
		$event_id      = absint( $event_id );

		if ( '' === $security_code ) {
			return null;
		}

		$table  = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$where  = 'a.tec_security_code = %s';
		$params = array( $security_code );

		if ( $event_id ) {
			$where   .= ' AND a.event_id = %d';
			$params[] = $event_id;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE {$where}
				LIMIT 1",
				$params
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Gets one row by order, booking, and ticket index.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id Booking ID.
	 * @param int    $ticket_index One-based ticket index.
	 * @return array|null
	 */
	public function get_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index ) {
		$row_id = $this->get_id_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index );

		return $row_id ? $this->get_by_id( $row_id ) : null;
	}

	/**
	 * Finds one GatiCrew attendee row by event and attendee identity.
	 *
	 * This is a guarded migration fallback for rows created before the bridge
	 * persisted direct TEC attendee IDs.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $name Attendee name.
	 * @param string $email Attendee email.
	 * @return array|null
	 */
	public function get_by_event_identity( $event_id, $name, $email ) {
		global $wpdb;

		$event_id = absint( $event_id );
		$name     = sanitize_text_field( (string) $name );
		$email    = sanitize_email( $email );

		if ( ! $event_id || ( '' === $name && '' === $email ) ) {
			return null;
		}

		$table  = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$where  = array( 'a.event_id = %d' );
		$params = array( $event_id );

		if ( '' !== $email ) {
			$where[]  = 'a.attendee_email = %s';
			$params[] = $email;
		}

		if ( '' !== $name ) {
			$like     = '%' . $wpdb->esc_like( $name ) . '%';
			$where[]  = '(a.attendee_name = %s OR a.attendee_names LIKE %s)';
			$params[] = $name;
			$params[] = $like;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY a.id DESC
				LIMIT 1',
				$params
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Gets one attendee booking by QR token, booking ID, or attendee row ID.
	 *
	 * @param string $token QR token or booking ID.
	 * @return array|null
	 */
	public function get_by_qr_token( $token ) {
		global $wpdb;

		$token = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $token );

		if ( '' === $token ) {
			return null;
		}

		if ( ctype_digit( $token ) ) {
			return $this->get_by_id( absint( $token ) );
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE a.booking_id = %s OR a.qr_token = %s
				ORDER BY a.id ASC
				LIMIT 1",
				$token,
				$token
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Gets every row attached to a shared QR token, booking ID, or attendee row ID.
	 *
	 * @param string $token QR token or booking ID.
	 * @return array
	 */
	public function get_group_by_qr_token( $token ) {
		global $wpdb;

		$token = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $token );

		if ( '' === $token ) {
			return array();
		}

		if ( ctype_digit( $token ) ) {
			$attendee = $this->get_by_id( absint( $token ) );

			if ( empty( $attendee ) ) {
				return array();
			}

			return $this->get_group_by_order_booking(
				isset( $attendee['order_id'] ) ? absint( $attendee['order_id'] ) : 0,
				isset( $attendee['booking_id'] ) ? $attendee['booking_id'] : ''
			);
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE a.booking_id = %s OR a.qr_token = %s
				ORDER BY a.id ASC",
				$token,
				$token
			),
			ARRAY_A
		);

		return $this->normalize_rows( $rows );
	}

	/**
	 * Stores the official Event Tickets attendee link for one GatiCrew row.
	 *
	 * These values are copied from TEC attendee post/meta so the booking API can
	 * load the authoritative QR ticket without guessing across attendee posts.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id GatiCrew booking ID.
	 * @param int    $ticket_index One-based ticket index.
	 * @param array  $data TEC attendee data.
	 * @return int Updated GatiCrew attendee row ID.
	 */
	public function update_tec_ticket_data( $order_id, $booking_id, $ticket_index, array $data ) {
		global $wpdb;

		$row_id       = $this->get_id_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index );
		$tec_id       = isset( $data['tec_attendee_post_id'] ) ? absint( $data['tec_attendee_post_id'] ) : 0;
		$security     = isset( $data['tec_security_code'] ) ? sanitize_text_field( $data['tec_security_code'] ) : '';
		$qr_url       = isset( $data['tec_qr_url'] ) ? esc_url_raw( $data['tec_qr_url'] ) : '';
		$qr_image_url = isset( $data['tec_qr_image_url'] ) ? esc_url_raw( $data['tec_qr_image_url'] ) : '';

		if ( ! $row_id || ! $tec_id ) {
			return 0;
		}

		$updated = $wpdb->update(
			GatiCrew_Events_Bridge_Schema::get_attendees_table_name(),
			array(
				'tec_attendee_post_id' => $tec_id,
				'tec_security_code'    => $security,
				'tec_qr_url'           => $qr_url,
				'tec_qr_image_url'     => $qr_image_url,
			),
			array( 'id' => $row_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? 0 : $row_id;
	}

	/**
	 * Gets every row attached to an order booking group.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id Booking ID.
	 * @return array
	 */
	public function get_group_by_order_booking( $order_id, $booking_id ) {
		global $wpdb;

		$order_id   = absint( $order_id );
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $booking_id );

		if ( ! $order_id || '' === $booking_id ) {
			return array();
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				WHERE a.order_id = %d AND a.booking_id = %s
				ORDER BY a.id ASC",
				$order_id,
				$booking_id
			),
			ARRAY_A
		);

		return $this->normalize_rows( $rows );
	}

	/**
	 * Returns attendee booking rows for the admin list table.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function get_admin_items( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'event_id' => 0,
				'status'   => '',
				'date'     => '',
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$table    = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $this->get_admin_where_sql( $args );
		$orderby  = $this->get_admin_orderby_sql( $args['orderby'], $args['order'] );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, p.post_title AS event_name
				FROM {$table} a
				LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
				{$where['sql']}
				ORDER BY {$orderby}
				LIMIT %d OFFSET %d",
				array_merge( $where['params'], array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return $this->normalize_rows( $rows );
	}

	/**
	 * Counts attendee booking rows for the admin list table.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count_admin_items( array $args = array() ) {
		global $wpdb;

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$where = $this->get_admin_where_sql( $args );
		$sql   = "SELECT COUNT(*)
			FROM {$table} a
			LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
			{$where['sql']}";

		if ( empty( $where['params'] ) ) {
			return absint( $wpdb->get_var( $sql ) );
		}

		return absint( $wpdb->get_var( $wpdb->prepare( $sql, $where['params'] ) ) );
	}

	/**
	 * Returns attendee totals by status for admin views.
	 *
	 * @param array $args Current filter args.
	 * @return array
	 */
	public function count_by_status( array $args = array() ) {
		global $wpdb;

		$table       = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$filter_args = $args;
		unset( $filter_args['status'] );

		$where  = $this->get_admin_where_sql( $filter_args );
		$counts = array(
			'all'                   => 0,
			self::STATUS_CONFIRMED  => 0,
			self::STATUS_CANCELLED  => 0,
			self::STATUS_CHECKED_IN => 0,
		);

		$sql = "SELECT a.status, COUNT(*) AS total
			FROM {$table} a
			LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
			{$where['sql']}
			GROUP BY a.status";

		$rows = empty( $where['params'] )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $where['params'] ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : '';
			$total  = isset( $row['total'] ) ? absint( $row['total'] ) : 0;

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = $total;
			}

			$counts['all'] += $total;
		}

		return $counts;
	}

	/**
	 * Returns event options that have attendees.
	 *
	 * @return array
	 */
	public function get_events_for_filter() {
		global $wpdb;

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		return $wpdb->get_results(
			"SELECT DISTINCT a.event_id, p.post_title AS event_name
			FROM {$table} a
			LEFT JOIN {$wpdb->posts} p ON a.event_id = p.ID
			WHERE a.event_id > 0
			ORDER BY p.post_title ASC",
			ARRAY_A
		);
	}

	/**
	 * Deletes attendee booking rows by IDs.
	 *
	 * @param array $ids Attendee IDs.
	 * @return int Deleted row count.
	 */
	public function delete_by_ids( array $ids ) {
		global $wpdb;

		$ids = $this->sanitize_ids( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return absint(
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ({$placeholders})",
					$ids
				)
			)
		);
	}

	/**
	 * Updates attendee booking status by IDs.
	 *
	 * @param array  $ids Attendee IDs.
	 * @param string $status New status.
	 * @return int Updated row count.
	 */
	public function update_status_by_ids( array $ids, $status ) {
		global $wpdb;

		$ids    = $this->sanitize_ids( $ids );
		$status = $this->sanitize_status( $status );

		if ( empty( $ids ) || '' === $status ) {
			return 0;
		}

		$table         = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();
		$placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$is_checked_in = self::STATUS_CHECKED_IN === $status ? 1 : 0;
		$qr_status     = $this->get_qr_status_for_booking_status( $status );
		$checked_sql   = $is_checked_in ? 'checked_in_at = %s,' : 'checked_in_at = NULL,';
		$params        = $is_checked_in
			? array( $status, $is_checked_in, current_time( 'mysql' ), $status, $qr_status )
			: array( $status, $is_checked_in, $status, $qr_status );

		return absint(
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = %s,
						checked_in = %d,
						{$checked_sql}
						booking_status = %s,
						qr_status = %s
					WHERE id IN ({$placeholders})",
					array_merge( $params, $ids )
				)
			)
		);
	}

	/**
	 * Marks a booking checked in once.
	 *
	 * @param string $token QR token, booking ID, or attendee row ID.
	 * @return array
	 */
	public function mark_checked_in_by_token( $token ) {
		global $wpdb;

		$token    = GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $token );
		$attendee = $this->get_by_qr_token( $token );
		$group    = $this->get_group_by_qr_token( $token );

		if ( empty( $attendee ) ) {
			return array(
				'success' => false,
				'code'    => 'invalid_token',
			);
		}

		$status = isset( $attendee['status'] ) ? sanitize_key( $attendee['status'] ) : '';

		if ( self::STATUS_CANCELLED === $status ) {
			return array(
				'success'  => false,
				'code'     => 'cancelled',
				'attendee' => $attendee,
				'group'    => $group,
			);
		}

		if ( self::STATUS_CHECKED_IN === $status || ! empty( $attendee['checked_in'] ) ) {
			return array(
				'success'  => false,
				'code'     => 'already_checked_in',
				'attendee' => $attendee,
				'group'    => $group,
			);
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		if ( ctype_digit( $token ) ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = %s,
						checked_in = 1,
						checked_in_at = %s,
						booking_status = %s,
						qr_status = %s
					WHERE order_id = %d
						AND booking_id = %s
						AND status <> %s
						AND checked_in = 0",
					self::STATUS_CHECKED_IN,
					current_time( 'mysql' ),
					self::STATUS_CHECKED_IN,
					GatiCrew_Events_Bridge_QR_Tokens::STATUS_USED,
					isset( $attendee['order_id'] ) ? absint( $attendee['order_id'] ) : 0,
					isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '',
					self::STATUS_CANCELLED
				)
			);
		} else {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table}
					SET status = %s,
						checked_in = 1,
						checked_in_at = %s,
						booking_status = %s,
						qr_status = %s
					WHERE (booking_id = %s OR qr_token = %s)
						AND status <> %s
						AND checked_in = 0",
					self::STATUS_CHECKED_IN,
					current_time( 'mysql' ),
					self::STATUS_CHECKED_IN,
					GatiCrew_Events_Bridge_QR_Tokens::STATUS_USED,
					$token,
					$token,
					self::STATUS_CANCELLED
				)
			);
		}

		if ( $updated ) {
			return array(
				'success'  => true,
				'code'     => 'checked_in',
				'attendee' => $this->get_by_qr_token( $token ),
				'group'    => $this->get_group_by_qr_token( $token ),
			);
		}

		return array(
			'success'  => false,
			'code'     => 'already_checked_in',
			'attendee' => $this->get_by_qr_token( $token ),
			'group'    => $this->get_group_by_qr_token( $token ),
		);
	}

	/**
	 * Gets attendee row ID by order/event pair.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $event_id Event post ID.
	 * @return int
	 */
	private function get_id_by_order_event( $order_id, $event_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		$event_id = absint( $event_id );

		if ( ! $order_id || ! $event_id ) {
			return 0;
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE order_id = %d AND event_id = %d LIMIT 1",
					$order_id,
					$event_id
				)
			)
		);
	}

	/**
	 * Gets attendee row ID by order/booking pair.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id Booking ID.
	 * @return int
	 */
	private function get_id_by_order_booking( $order_id, $booking_id ) {
		global $wpdb;

		$order_id   = absint( $order_id );
		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $booking_id );

		if ( ! $order_id || '' === $booking_id ) {
			return 0;
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE order_id = %d AND booking_id = %s LIMIT 1",
					$order_id,
					$booking_id
				)
			)
		);
	}

	/**
	 * Gets attendee row ID by order/booking/ticket index.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $booking_id Booking ID.
	 * @param int    $ticket_index One-based ticket index.
	 * @return int
	 */
	private function get_id_by_order_booking_ticket_index( $order_id, $booking_id, $ticket_index ) {
		global $wpdb;

		$order_id     = absint( $order_id );
		$booking_id   = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $booking_id );
		$ticket_index = max( 1, absint( $ticket_index ) );

		if ( ! $order_id || '' === $booking_id ) {
			return 0;
		}

		$table = GatiCrew_Events_Bridge_Schema::get_attendees_table_name();

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE order_id = %d AND booking_id = %s AND ticket_index = %d LIMIT 1",
					$order_id,
					$booking_id,
					$ticket_index
				)
			)
		);
	}

	/**
	 * Normalizes attendee data before insertion.
	 *
	 * @param array $data Raw attendee data.
	 * @return array
	 */
	private function prepare_attendee_data( array $data ) {
		$quantity       = isset( $data['quantity'] ) ? absint( $data['quantity'] ) : ( isset( $data['ticket_quantity'] ) ? absint( $data['ticket_quantity'] ) : 1 );
		$quantity       = max( 1, $quantity );
		$status         = isset( $data['status'] ) ? $this->sanitize_status( $data['status'] ) : ( isset( $data['booking_status'] ) ? $this->sanitize_status( $data['booking_status'] ) : self::STATUS_CONFIRMED );
		$status         = $status ? $status : self::STATUS_CONFIRMED;
		$attendee_names = $this->sanitize_attendee_names( isset( $data['attendee_names'] ) ? $data['attendee_names'] : array() );

		if ( empty( $attendee_names ) && ! empty( $data['attendee_name'] ) ) {
			$attendee_names[] = sanitize_text_field( $data['attendee_name'] );
		}

		if ( empty( $attendee_names ) ) {
			$attendee_names[] = __( 'Guest', 'gaticrew-events-bridge' );
		}

		$checked_in = self::STATUS_CHECKED_IN === $status || ! empty( $data['checked_in'] ) ? 1 : 0;
		$booking_id = isset( $data['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $data['booking_id'] ) : '';
		$qr_status  = $this->get_qr_status_for_booking_status( $status );
		$ticket_index = isset( $data['ticket_index'] ) ? max( 1, absint( $data['ticket_index'] ) ) : 1;

		return array(
			'booking_id'      => $booking_id,
			'order_id'        => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0,
			'event_id'        => isset( $data['event_id'] ) ? absint( $data['event_id'] ) : 0,
			'product_id'      => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
			'attendee_names'  => wp_json_encode( array_values( $attendee_names ) ),
			'attendee_email'  => isset( $data['attendee_email'] ) ? sanitize_email( $data['attendee_email'] ) : '',
			'attendee_phone'  => isset( $data['attendee_phone'] ) ? wc_sanitize_phone_number( $data['attendee_phone'] ) : '',
			'quantity'        => $quantity,
			'qr_code'         => ! empty( $data['qr_code'] ) ? esc_url_raw( $data['qr_code'] ) : '',
			'ticket_pdf'      => ! empty( $data['ticket_pdf'] ) ? esc_url_raw( $data['ticket_pdf'] ) : '',
			'status'          => $status,
			'checked_in'      => $checked_in,
			'checked_in_at'   => $checked_in ? current_time( 'mysql' ) : null,
			'created_at'      => current_time( 'mysql' ),
			'ticket_index'    => $ticket_index,
			'attendee_name'   => sanitize_text_field( reset( $attendee_names ) ),
			'ticket_quantity' => $quantity,
			'booking_status'  => $status,
			'qr_token'        => $booking_id,
			'qr_status'       => $qr_status,
			'tec_attendee_post_id' => isset( $data['tec_attendee_post_id'] ) ? absint( $data['tec_attendee_post_id'] ) : 0,
			'tec_security_code'    => isset( $data['tec_security_code'] ) ? sanitize_text_field( $data['tec_security_code'] ) : '',
			'tec_qr_url'           => ! empty( $data['tec_qr_url'] ) ? esc_url_raw( $data['tec_qr_url'] ) : '',
			'tec_qr_image_url'     => ! empty( $data['tec_qr_image_url'] ) ? esc_url_raw( $data['tec_qr_image_url'] ) : '',
		);
	}

	/**
	 * Builds sanitized filter SQL for the admin list table.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	private function get_admin_where_sql( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'event_id' => 0,
				'status'   => '',
				'date'     => '',
			)
		);

		$where  = array();
		$params = array();
		$search = sanitize_text_field( (string) $args['search'] );

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(
				a.booking_id LIKE %s
				OR a.attendee_names LIKE %s
				OR a.attendee_name LIKE %s
				OR a.attendee_email LIKE %s
				OR a.attendee_phone LIKE %s
			)';
			$params  = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$event_id = isset( $args['event_id'] ) ? absint( $args['event_id'] ) : 0;

		if ( $event_id ) {
			$where[]  = 'a.event_id = %d';
			$params[] = $event_id;
		}

		$status = isset( $args['status'] ) ? $this->sanitize_status( $args['status'] ) : '';

		if ( '' !== $status ) {
			$where[]  = 'a.status = %s';
			$params[] = $status;
		}

		$date_range = $this->get_date_range( isset( $args['date'] ) ? $args['date'] : '' );

		if ( ! empty( $date_range ) ) {
			$where[]  = 'a.created_at >= %s';
			$where[]  = 'a.created_at <= %s';
			$params[] = $date_range['start'];
			$params[] = $date_range['end'];
		}

		return array(
			'sql'    => empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	/**
	 * Maps attendee booking status to QR lifecycle status.
	 *
	 * @param string $status Booking status.
	 * @return string
	 */
	private function get_qr_status_for_booking_status( $status ) {
		switch ( $status ) {
			case self::STATUS_CHECKED_IN:
				return GatiCrew_Events_Bridge_QR_Tokens::STATUS_USED;
			case self::STATUS_CANCELLED:
				return GatiCrew_Events_Bridge_QR_Tokens::STATUS_REVOKED;
			case self::STATUS_CONFIRMED:
			default:
				return GatiCrew_Events_Bridge_QR_Tokens::STATUS_ACTIVE;
		}
	}

	/**
	 * Builds safe ORDER BY SQL.
	 *
	 * @param string $orderby Requested orderby.
	 * @param string $order Requested direction.
	 * @return string
	 */
	private function get_admin_orderby_sql( $orderby, $order ) {
		$allowed = array(
			'booking_id'    => 'a.booking_id',
			'attendee_name' => 'a.attendee_names',
			'event_name'    => 'p.post_title',
			'created_at'    => 'a.created_at',
		);

		$orderby = sanitize_key( $orderby );
		$order   = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		$field   = isset( $allowed[ $orderby ] ) ? $allowed[ $orderby ] : 'a.created_at';

		return $field . ' ' . $order . ', a.id DESC';
	}

	/**
	 * Normalizes rows to the current shape and legacy aliases.
	 *
	 * @param array $rows Raw DB rows.
	 * @return array
	 */
	private function normalize_rows( $rows ) {
		$normalized = array();

		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$normalized[] = $this->normalize_row( $row );
			}
		}

		return $normalized;
	}

	/**
	 * Adds compatibility aliases expected by older templates/controllers.
	 *
	 * @param array $row Raw DB row.
	 * @return array
	 */
	private function normalize_row( array $row ) {
		$names = $this->sanitize_attendee_names( isset( $row['attendee_names'] ) ? $row['attendee_names'] : array() );

		if ( empty( $names ) && ! empty( $row['attendee_name'] ) ) {
			$names[] = sanitize_text_field( $row['attendee_name'] );
		}

		$quantity = isset( $row['quantity'] ) ? max( 1, absint( $row['quantity'] ) ) : 0;

		if ( ! $quantity && isset( $row['ticket_quantity'] ) ) {
			$quantity = max( 1, absint( $row['ticket_quantity'] ) );
		}

		$quantity = max( 1, $quantity, count( $names ) );
		$status   = isset( $row['status'] ) ? $this->sanitize_status( $row['status'] ) : '';

		if ( '' === $status && ! empty( $row['booking_status'] ) ) {
			$status = $this->sanitize_status( $row['booking_status'] );
		}

		$status     = $status ? $status : self::STATUS_CONFIRMED;
		$checked_in = ! empty( $row['checked_in'] ) || self::STATUS_CHECKED_IN === $status;
		$booking_id = isset( $row['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $row['booking_id'] ) : '';
		$qr_token   = ! empty( $row['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $row['qr_token'] ) : '';

		if ( '' === $qr_token ) {
			$qr_token = $booking_id;
		}

		$row['attendee_names']  = $names;
		$row['attendee_name']   = ! empty( $names[0] ) ? $names[0] : '';
		$row['quantity']        = $quantity;
		$row['ticket_quantity'] = $quantity;
		$row['attendee_count']  = $quantity;
		$row['status']          = $status;
		$row['booking_status']  = $status;
		$row['checked_in']      = $checked_in ? 1 : 0;
		$row['qr_token']        = $qr_token;
		$row['qr_status']       = $checked_in ? GatiCrew_Events_Bridge_QR_Tokens::STATUS_USED : $this->get_qr_status_for_booking_status( $status );
		$row['tec_attendee_post_id'] = isset( $row['tec_attendee_post_id'] ) ? absint( $row['tec_attendee_post_id'] ) : 0;
		$row['tec_security_code']    = isset( $row['tec_security_code'] ) ? sanitize_text_field( $row['tec_security_code'] ) : '';
		$row['tec_qr_url']           = ! empty( $row['tec_qr_url'] ) ? esc_url_raw( $row['tec_qr_url'] ) : '';
		$row['tec_qr_image_url']     = ! empty( $row['tec_qr_image_url'] ) ? esc_url_raw( $row['tec_qr_image_url'] ) : '';

		return $row;
	}

	/**
	 * Sanitizes attendee names from arrays or JSON strings.
	 *
	 * @param mixed $names Raw attendee names.
	 * @return array
	 */
	private function sanitize_attendee_names( $names ) {
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
	 * Sanitizes attendee status values.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function sanitize_status( $status ) {
		$status = sanitize_key( (string) $status );

		return array_key_exists( $status, self::get_statuses() ) ? $status : '';
	}

	/**
	 * Sanitizes row IDs.
	 *
	 * @param array $ids Raw IDs.
	 * @return array
	 */
	private function sanitize_ids( array $ids ) {
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Converts a date filter key to a created_at range.
	 *
	 * @param string $date_filter Date filter key.
	 * @return array
	 */
	private function get_date_range( $date_filter ) {
		$date_filter = sanitize_key( (string) $date_filter );
		$now         = current_time( 'timestamp' );

		switch ( $date_filter ) {
			case 'today':
				$start = strtotime( 'today', $now );
				$end   = strtotime( 'tomorrow', $start ) - 1;
				break;
			case 'last_7_days':
				$start = strtotime( '-6 days', strtotime( 'today', $now ) );
				$end   = strtotime( 'tomorrow', strtotime( 'today', $now ) ) - 1;
				break;
			case 'this_month':
				$start = strtotime( wp_date( 'Y-m-01 00:00:00', $now ) );
				$end   = strtotime( '+1 month', $start ) - 1;
				break;
			default:
				return array();
		}

		return array(
			'start' => wp_date( 'Y-m-d H:i:s', $start ),
			'end'   => wp_date( 'Y-m-d H:i:s', $end ),
		);
	}
}
