<?php
/**
 * WooCommerce thank-you event confirmation template.
 *
 * @package GatiCrew_Events_Bridge
 *
 * @var array $confirmation_items Confirmation rows prepared by the order manager.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $confirmation_items ) || ! is_array( $confirmation_items ) ) {
	return;
}

$rider_instructions = array(
	__( 'Arrive 30 minutes early', 'gaticrew-events-bridge' ),
	__( 'Carry valid ID', 'gaticrew-events-bridge' ),
	__( 'Helmet mandatory', 'gaticrew-events-bridge' ),
	__( 'Follow crew instructions', 'gaticrew-events-bridge' ),
);

$back_url = apply_filters( 'gaticrew_events_bridge_thankyou_back_url', home_url( '/' ) );
?>

<section class="gaticrew-event-confirmation">
	<div class="gaticrew-event-confirmation__hero">
		<p class="gaticrew-event-confirmation__eyebrow"><?php echo esc_html__( 'GatiCrew Booking', 'gaticrew-events-bridge' ); ?></p>
		<h2><?php echo esc_html__( 'Booking Confirmed', 'gaticrew-events-bridge' ); ?></h2>
	</div>

	<?php foreach ( $confirmation_items as $item ) : ?>
		<div class="gaticrew-event-confirmation__ticket">
			<?php if ( ! empty( $item['qr_image_url'] ) ) : ?>
				<div class="gaticrew-event-confirmation__qr">
					<img src="<?php echo esc_url( $item['qr_image_url'] ); ?>" alt="<?php echo esc_attr__( 'QR Ticket', 'gaticrew-events-bridge' ); ?>">
					<strong><?php echo esc_html__( 'QR Ticket', 'gaticrew-events-bridge' ); ?></strong>
					<span><?php echo esc_html( $item['qr_token'] ); ?></span>
				</div>
			<?php endif; ?>
			<dl class="gaticrew-event-confirmation__details">
				<div class="gaticrew-event-confirmation__detail gaticrew-event-confirmation__detail--wide">
					<dt><?php echo esc_html__( 'Event Name', 'gaticrew-events-bridge' ); ?></dt>
					<dd><?php echo esc_html( $item['event_name'] ); ?></dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Event Date', 'gaticrew-events-bridge' ); ?></dt>
					<dd><?php echo esc_html( $item['event_date'] ); ?></dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Venue', 'gaticrew-events-bridge' ); ?></dt>
					<dd><?php echo esc_html( $item['event_venue'] ); ?></dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></dt>
					<dd><?php echo esc_html( $item['booking_id'] ); ?></dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Attendees', 'gaticrew-events-bridge' ); ?></dt>
					<dd>
						<ol class="gaticrew-event-confirmation__attendees">
							<?php foreach ( ! empty( $item['attendee_names'] ) ? $item['attendee_names'] : array( $item['attendee_name'] ) as $attendee_name ) : ?>
								<li><?php echo esc_html( $attendee_name ); ?></li>
							<?php endforeach; ?>
						</ol>
					</dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Group Booking', 'gaticrew-events-bridge' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							sprintf(
								_n( '%d attendee on this booking', '%d attendees on this booking', absint( $item['attendee_count'] ), 'gaticrew-events-bridge' ),
								absint( $item['attendee_count'] )
							)
						);
						?>
					</dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></dt>
					<dd><?php echo esc_html( absint( $item['ticket_quantity'] ) ); ?></dd>
				</div>
				<div class="gaticrew-event-confirmation__detail">
					<dt><?php echo esc_html__( 'Customer Email', 'gaticrew-events-bridge' ); ?></dt>
					<dd><?php echo esc_html( $item['attendee_email'] ); ?></dd>
				</div>
			</dl>
			<?php if ( ! empty( $item['pdf_download_url'] ) ) : ?>
				<p class="gaticrew-event-confirmation__ticket-actions">
					<a class="button gaticrew-event-confirmation__button" href="<?php echo esc_url( $item['pdf_download_url'] ); ?>">
						<?php echo esc_html__( 'Download Ticket PDF', 'gaticrew-events-bridge' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<div class="gaticrew-event-confirmation__panel gaticrew-event-confirmation__instructions">
		<h3><?php echo esc_html__( 'Rider Instructions', 'gaticrew-events-bridge' ); ?></h3>
		<ul>
			<?php foreach ( $rider_instructions as $instruction ) : ?>
				<li><?php echo esc_html( $instruction ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<div class="gaticrew-event-confirmation__panel gaticrew-event-confirmation__qr-note">
		<strong><?php echo esc_html__( 'QR Ticket Ready', 'gaticrew-events-bridge' ); ?></strong>
		<p><?php echo esc_html__( 'Show the QR ticket at the ride check-in desk for validation.', 'gaticrew-events-bridge' ); ?></p>
	</div>

	<p class="gaticrew-event-confirmation__actions">
		<a class="button gaticrew-event-confirmation__button" href="<?php echo esc_url( $back_url ); ?>">
			<?php echo esc_html__( 'Back to GatiCrew', 'gaticrew-events-bridge' ); ?>
		</a>
	</p>
</section>
