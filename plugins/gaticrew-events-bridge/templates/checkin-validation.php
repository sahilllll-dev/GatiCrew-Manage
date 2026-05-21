<?php
/**
 * Standalone QR ticket validation template.
 *
 * @package GatiCrew_Events_Bridge
 *
 * @var array|null $attendee Attendee row.
 * @var array      $attendee_group Attendee rows sharing the same QR booking token.
 * @var string     $notice Notice key.
 * @var string     $token Sanitized QR token.
 */

defined( 'ABSPATH' ) || exit;

$is_valid         = is_array( $attendee ) && ! empty( $attendee['id'] );
$booking_status   = $is_valid && ! empty( $attendee['booking_status'] ) ? sanitize_key( $attendee['booking_status'] ) : '';
$qr_status        = $is_valid && ! empty( $attendee['qr_status'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_status( $attendee['qr_status'] ) : '';
$is_checked_in    = GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CHECKED_IN === $booking_status || GatiCrew_Events_Bridge_QR_Tokens::STATUS_USED === $qr_status;
$is_cancelled     = GatiCrew_Events_Bridge_Attendees_Repository::STATUS_CANCELLED === $booking_status;
$event_id         = $is_valid && ! empty( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
$event_name       = $is_valid && ! empty( $attendee['event_name'] ) ? sanitize_text_field( $attendee['event_name'] ) : GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id );
$event_date       = $event_id ? GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) : '';
$statuses         = GatiCrew_Events_Bridge_Attendees_Repository::get_statuses();
$status_label     = isset( $statuses[ $booking_status ] ) ? $statuses[ $booking_status ] : $booking_status;
$attendee_group   = ! empty( $attendee_group ) && is_array( $attendee_group ) ? $attendee_group : ( $is_valid ? array( $attendee ) : array() );
$attendee_names   = array();

foreach ( $attendee_group as $group_attendee ) {
	if ( ! empty( $group_attendee['attendee_names'] ) && is_array( $group_attendee['attendee_names'] ) ) {
		foreach ( $group_attendee['attendee_names'] as $group_name ) {
			$group_name = sanitize_text_field( $group_name );

			if ( '' !== $group_name ) {
				$attendee_names[] = $group_name;
			}
		}

		continue;
	}

	$name = isset( $group_attendee['attendee_name'] ) ? sanitize_text_field( $group_attendee['attendee_name'] ) : '';

	if ( '' !== $name ) {
		$attendee_names[] = $name;
	}
}

if ( empty( $attendee_names ) && $is_valid && ! empty( $attendee['attendee_name'] ) ) {
	$attendee_names[] = sanitize_text_field( $attendee['attendee_name'] );
}

$attendee_count   = $is_valid && ! empty( $attendee['quantity'] ) ? max( absint( $attendee['quantity'] ), count( $attendee_names ) ) : max( 1, count( $attendee_names ) );
$page_title       = $is_valid ? __( 'GatiCrew Ticket Validation', 'gaticrew-events-bridge' ) : __( 'Invalid GatiCrew Ticket', 'gaticrew-events-bridge' );
$notice_messages  = array(
	'checked_in'         => __( 'Checked In', 'gaticrew-events-bridge' ),
	'already_checked_in' => __( 'Already Checked In', 'gaticrew-events-bridge' ),
	'cancelled'          => __( 'This ticket is cancelled.', 'gaticrew-events-bridge' ),
	'invalid_nonce'      => __( 'Security check failed. Refresh and try again.', 'gaticrew-events-bridge' ),
	'invalid_token'      => __( 'Invalid QR ticket.', 'gaticrew-events-bridge' ),
	'rate_limited'       => __( 'Too many invalid attempts. Try again later.', 'gaticrew-events-bridge' ),
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $page_title ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'gaticrew-checkin-page' ); ?>>
	<main class="gaticrew-checkin">
		<section class="gaticrew-checkin__card">
			<p class="gaticrew-checkin__eyebrow"><?php echo esc_html__( 'GatiCrew Check-In', 'gaticrew-events-bridge' ); ?></p>
			<h1><?php echo esc_html( $page_title ); ?></h1>

			<?php if ( isset( $notice_messages[ $notice ] ) ) : ?>
				<div class="gaticrew-checkin__notice gaticrew-checkin__notice--<?php echo esc_attr( sanitize_html_class( $notice ) ); ?>">
					<?php echo esc_html( $notice_messages[ $notice ] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $is_valid ) : ?>
				<dl class="gaticrew-checkin__details">
					<div>
						<dt><?php echo esc_html__( 'Attendees', 'gaticrew-events-bridge' ); ?></dt>
						<dd>
							<ol class="gaticrew-checkin__attendees">
								<?php foreach ( $attendee_names as $attendee_name ) : ?>
									<li><?php echo esc_html( $attendee_name ); ?></li>
								<?php endforeach; ?>
							</ol>
						</dd>
					</div>
					<div>
						<dt><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></dt>
						<dd><?php echo esc_html( $attendee['booking_id'] ); ?></dd>
					</div>
					<div>
						<dt><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></dt>
						<dd><?php echo esc_html( $attendee_count ); ?></dd>
					</div>
					<div>
						<dt><?php echo esc_html__( 'Event Name', 'gaticrew-events-bridge' ); ?></dt>
						<dd><?php echo esc_html( $event_name ); ?></dd>
					</div>
					<div>
						<dt><?php echo esc_html__( 'Event Date', 'gaticrew-events-bridge' ); ?></dt>
						<dd><?php echo esc_html( $event_date ); ?></dd>
					</div>
					<div>
						<dt><?php echo esc_html__( 'Attendee Status', 'gaticrew-events-bridge' ); ?></dt>
						<dd>
							<mark class="gaticrew-attendee-status gaticrew-attendee-status--<?php echo esc_attr( $booking_status ); ?>">
								<span><?php echo esc_html( $status_label ); ?></span>
							</mark>
						</dd>
					</div>
				</dl>

				<?php if ( $is_checked_in ) : ?>
					<div class="gaticrew-checkin__result gaticrew-checkin__result--used">
						<?php echo esc_html__( 'Already Checked In', 'gaticrew-events-bridge' ); ?>
					</div>
				<?php elseif ( $is_cancelled ) : ?>
					<div class="gaticrew-checkin__result gaticrew-checkin__result--cancelled">
						<?php echo esc_html__( 'This ticket cannot be checked in because it is cancelled.', 'gaticrew-events-bridge' ); ?>
					</div>
				<?php else : ?>
					<form method="post" class="gaticrew-checkin__actions">
						<input type="hidden" name="gaticrew_checkin_action" value="mark_checked_in">
						<?php wp_nonce_field( 'gaticrew_checkin_' . $token ); ?>
						<button type="submit" class="button gaticrew-checkin__button">
							<?php echo esc_html__( 'Mark Checked In', 'gaticrew-events-bridge' ); ?>
						</button>
					</form>
				<?php endif; ?>
			<?php else : ?>
				<div class="gaticrew-checkin__result gaticrew-checkin__result--invalid">
					<?php echo esc_html__( 'This QR ticket could not be validated.', 'gaticrew-events-bridge' ); ?>
				</div>
			<?php endif; ?>
		</section>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
