<?php
/**
 * DOMPDF ticket template.
 *
 * @package GatiCrew_Events_Bridge
 *
 * @var array $ticket Sanitized ticket data.
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<style>
		@page {
			margin: 34px;
		}

		body {
			margin: 0;
			color: #1f2933;
			font-family: "DejaVu Sans", sans-serif;
			font-size: 13px;
			line-height: 1.45;
			background: #ffffff;
		}

		.ticket {
			border: 1px solid #d8e0dc;
			background: #ffffff;
		}

		.header {
			padding: 28px 30px;
			background: #111827;
			color: #ffffff;
		}

		.brand {
			margin: 0 0 8px;
			font-size: 13px;
			font-weight: 700;
			letter-spacing: 2px;
			text-transform: uppercase;
		}

		h1 {
			margin: 0;
			font-size: 28px;
			line-height: 1.2;
		}

		.content {
			padding: 28px 30px;
		}

		.summary {
			width: 100%;
			border-collapse: collapse;
			margin-bottom: 22px;
		}

		.summary td {
			vertical-align: top;
		}

		.qr-cell {
			width: 170px;
			padding-left: 24px;
			text-align: center;
		}

		.qr {
			width: 150px;
			height: 150px;
			border: 1px solid #e5e7eb;
			background: #ffffff;
		}

		.qr-token {
			margin-top: 8px;
			color: #5f6f67;
			font-size: 10px;
			word-break: break-all;
		}

		.details {
			width: 100%;
			border-collapse: collapse;
		}

		.details th,
		.details td {
			padding: 10px 12px;
			border: 1px solid #e5e7eb;
			text-align: left;
			vertical-align: top;
		}

		.details th {
			width: 34%;
			color: #5f6f67;
			background: #f8faf9;
			font-weight: 700;
		}

		.instructions {
			margin-top: 22px;
			padding: 18px;
			border: 1px solid #e5e7eb;
			background: #f8faf9;
		}

		.instructions h2 {
			margin: 0 0 10px;
			font-size: 16px;
		}

		.instructions ul {
			margin: 0;
			padding-left: 18px;
		}

		.footer {
			padding: 16px 30px;
			border-top: 1px solid #e5e7eb;
			color: #5f6f67;
			font-size: 11px;
		}
	</style>
</head>
<body>
	<div class="ticket">
		<div class="header">
			<p class="brand"><?php echo esc_html( $ticket['brand_name'] ); ?></p>
			<h1><?php echo esc_html__( 'Event Ticket', 'gaticrew-events-bridge' ); ?></h1>
		</div>

		<div class="content">
			<table class="summary">
				<tr>
					<td>
						<table class="details">
							<tr>
								<th><?php echo esc_html__( 'Event Name', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $ticket['event_name'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Event Date', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $ticket['event_date'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Venue', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $ticket['event_venue'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Booking ID', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $ticket['booking_id'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Attendees', 'gaticrew-events-bridge' ); ?></th>
								<td>
									<ol>
										<?php foreach ( $ticket['attendee_names'] as $attendee_name ) : ?>
											<li><?php echo esc_html( $attendee_name ); ?></li>
										<?php endforeach; ?>
									</ol>
								</td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Customer Email', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( $ticket['attendee_email'] ); ?></td>
							</tr>
							<tr>
								<th><?php echo esc_html__( 'Ticket Quantity', 'gaticrew-events-bridge' ); ?></th>
								<td><?php echo esc_html( absint( $ticket['ticket_quantity'] ) ); ?></td>
							</tr>
						</table>
					</td>
					<td class="qr-cell">
						<?php if ( ! empty( $ticket['qr_data_uri'] ) ) : ?>
							<img class="qr" src="<?php echo esc_attr( $ticket['qr_data_uri'] ); ?>" alt="<?php echo esc_attr__( 'Ticket QR Code', 'gaticrew-events-bridge' ); ?>">
							<div class="qr-token"><?php echo esc_html( $ticket['qr_token'] ); ?></div>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<div class="instructions">
				<h2><?php echo esc_html__( 'Rider Instructions', 'gaticrew-events-bridge' ); ?></h2>
				<ul>
					<?php foreach ( $ticket['instructions'] as $instruction ) : ?>
						<li><?php echo esc_html( $instruction ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<div class="footer">
			<?php echo esc_html__( 'Scan the QR code at check-in to validate this ticket.', 'gaticrew-events-bridge' ); ?>
		</div>
	</div>
</body>
</html>
