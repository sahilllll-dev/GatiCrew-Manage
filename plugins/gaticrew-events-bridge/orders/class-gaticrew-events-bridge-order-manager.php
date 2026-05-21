<?php
/**
 * WooCommerce order, attendee, and confirmation integration.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Order_Manager {
	/**
	 * Order item meta key for per-ticket booking identity.
	 */
	const ITEM_META_BOOKING_ID = '_gaticrew_booking_id';

	/**
	 * Order item meta key for group attendee names.
	 */
	const ITEM_META_ATTENDEE_NAMES = '_gaticrew_attendee_names';

	/**
	 * Attendee repository.
	 *
	 * @var GatiCrew_Events_Bridge_Attendees_Repository
	 */
	private $attendees_repository;

	/**
	 * Tracks whether the WooCommerce thank-you template output is being buffered.
	 *
	 * @var bool
	 */
	private $thankyou_buffer_started = false;

	/**
	 * Tracks whether custom block checkout confirmation has rendered.
	 *
	 * @var bool
	 */
	private $block_thankyou_rendered = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->attendees_repository = new GatiCrew_Events_Bridge_Attendees_Repository();
	}

	/**
	 * Registers WooCommerce checkout, thank-you, and admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_checkout_attendee_fields' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_attendee_fields' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_event_booking_on_checkout' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_attendee_names_to_order_item' ), 20, 4 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_attendees_when_order_is_successful' ), 20, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_attendees_when_order_is_successful' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_filter( 'woocommerce_locate_template', array( $this, 'locate_custom_thankyou_template' ), 20, 3 );
		add_filter( 'render_block', array( $this, 'replace_order_confirmation_blocks' ), 20, 2 );
		add_action( 'woocommerce_before_thankyou', array( $this, 'start_custom_thankyou_buffer' ), 0 );
		add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou_event_confirmation' ), 0 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_event_details' ), 20 );
	}

	/**
	 * Renders one required attendee-name field for every linked ticket quantity.
	 *
	 * @param WC_Checkout $checkout WooCommerce checkout object.
	 * @return void
	 */
	public function render_checkout_attendee_fields( $checkout ) {
		unset( $checkout );

		$cart_bookings = $this->get_linked_event_bookings_from_cart();

		if ( empty( $cart_bookings ) ) {
			return;
		}

		$posted_names = $this->get_posted_attendee_names();
		?>
		<div class="gaticrew-checkout-attendees">
			<h3><?php echo esc_html__( 'Attendee Details', 'gaticrew-events-bridge' ); ?></h3>
			<?php foreach ( $cart_bookings as $booking ) : ?>
				<?php
				$cart_item_key = isset( $booking['cart_item_key'] ) ? sanitize_key( $booking['cart_item_key'] ) : '';
				$quantity      = isset( $booking['quantity'] ) ? max( 1, absint( $booking['quantity'] ) ) : 1;
				$event_name    = ! empty( $booking['event_name'] ) ? sanitize_text_field( $booking['event_name'] ) : '';
				$saved_names   = isset( $posted_names[ $cart_item_key ] ) && is_array( $posted_names[ $cart_item_key ] ) ? $posted_names[ $cart_item_key ] : array();
				?>
				<div class="gaticrew-checkout-attendees__group">
					<p class="gaticrew-checkout-attendees__event">
						<strong><?php echo esc_html( $event_name ); ?></strong>
						<span><?php echo esc_html( sprintf( _n( '%d ticket', '%d tickets', $quantity, 'gaticrew-events-bridge' ), $quantity ) ); ?></span>
					</p>
					<?php for ( $index = 1; $index <= $quantity; $index++ ) : ?>
						<?php
						$field_id = 'gaticrew_attendee_names_' . $cart_item_key . '_' . $index;
						$value    = isset( $saved_names[ $index ] ) ? sanitize_text_field( $saved_names[ $index ] ) : '';
						woocommerce_form_field(
							'gaticrew_attendee_names[' . $cart_item_key . '][' . $index . ']',
							array(
								'type'        => 'text',
								'id'          => $field_id,
								'class'       => array( 'form-row-wide' ),
								'label'       => sprintf( __( 'Attendee Name %d', 'gaticrew-events-bridge' ), $index ),
								'required'    => true,
								'placeholder' => __( 'Full attendee name', 'gaticrew-events-bridge' ),
							),
							$value
						);
						?>
					<?php endfor; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Requires all attendee fields for linked event-ticket quantities.
	 *
	 * @param array     $data Posted checkout data.
	 * @param WP_Error  $errors Checkout validation errors.
	 * @return void
	 */
	public function validate_checkout_attendee_fields( $data, $errors ) {
		unset( $data );

		if ( ! $errors instanceof WP_Error ) {
			return;
		}

		$cart_bookings = $this->get_linked_event_bookings_from_cart();

		if ( empty( $cart_bookings ) ) {
			return;
		}

		$posted_names = $this->get_posted_attendee_names();

		foreach ( $cart_bookings as $booking ) {
			$cart_item_key = isset( $booking['cart_item_key'] ) ? sanitize_key( $booking['cart_item_key'] ) : '';
			$quantity      = isset( $booking['quantity'] ) ? max( 1, absint( $booking['quantity'] ) ) : 1;
			$event_name    = ! empty( $booking['event_name'] ) ? sanitize_text_field( $booking['event_name'] ) : __( 'GatiCrew event', 'gaticrew-events-bridge' );
			$names         = isset( $posted_names[ $cart_item_key ] ) && is_array( $posted_names[ $cart_item_key ] ) ? $posted_names[ $cart_item_key ] : array();

			for ( $index = 1; $index <= $quantity; $index++ ) {
				$name = isset( $names[ $index ] ) ? sanitize_text_field( $names[ $index ] ) : '';

				if ( '' === $name ) {
					$errors->add(
						'gaticrew_attendee_name_' . $cart_item_key . '_' . $index,
						sprintf(
							/* translators: 1: attendee number, 2: event name. */
							__( 'Attendee Name %1$d is required for %2$s.', 'gaticrew-events-bridge' ),
							$index,
							$event_name
						)
					);
				}
			}
		}
	}

	/**
	 * Persists per-item attendee names so order processing can create group rows.
	 *
	 * @param WC_Order_Item_Product $item Order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart item values.
	 * @param WC_Order              $order WooCommerce order.
	 * @return void
	 */
	public function save_attendee_names_to_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
			return;
		}

		$linked_event = $this->get_linked_event_from_cart_item( $values );

		if ( empty( $linked_event['event_id'] ) ) {
			return;
		}

		$quantity = max( 1, absint( $item->get_quantity() ) );
		$names    = $this->get_posted_attendee_names_for_cart_item( $cart_item_key, $quantity );

		if ( empty( $names ) ) {
			return;
		}

		$item->update_meta_data( self::ITEM_META_ATTENDEE_NAMES, $names );

		$existing = $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_ATTENDEE_NAMES, true );
		$existing = is_array( $existing ) ? $existing : array();

		foreach ( $names as $name ) {
			$existing[] = sanitize_text_field( $name );
		}

		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_ATTENDEE_NAMES, array_values( array_filter( $existing ) ) );
	}

	/**
	 * Replaces WooCommerce's thank-you template for linked event-ticket orders.
	 *
	 * This is the primary takeover path: WooCommerce never loads its default
	 * "Order received", order overview, payment text, order table, or billing
	 * address sections for GatiCrew event bookings.
	 *
	 * @param string $located Located template path.
	 * @param string $template_name WooCommerce template name.
	 * @param string $template_path WooCommerce template path.
	 * @return string
	 */
	public function locate_custom_thankyou_template( $located, $template_name, $template_path ) {
		unset( $template_path );

		if ( 'checkout/thankyou.php' !== $template_name ) {
			return $located;
		}

		$order = $this->get_order_from_received_endpoint();

		if ( ! $order instanceof WC_Order || ! $this->order_has_gaticrew_event_products( $order ) ) {
			return $located;
		}

		return GATICREW_EVENTS_BRIDGE_PATH . 'templates/woocommerce-thankyou-event.php';
	}

	/**
	 * Replaces WooCommerce Blocks order-confirmation output for event orders.
	 *
	 * Checkout block pages render separate blocks for status, summary, totals,
	 * payment details, and addresses. For GatiCrew event bookings, each default
	 * block returns empty output and the custom confirmation renders once.
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block Parsed block data.
	 * @return string
	 */
	public function replace_order_confirmation_blocks( $block_content, $block ) {
		if ( empty( $block['blockName'] ) || 0 !== strpos( $block['blockName'], 'woocommerce/order-confirmation-' ) ) {
			return $block_content;
		}

		$order = $this->get_order_from_received_endpoint();

		if ( ! $order instanceof WC_Order || ! $this->order_has_gaticrew_event_products( $order ) ) {
			return $block_content;
		}

		if ( $this->block_thankyou_rendered ) {
			if ( false !== strpos( $block_content, 'gaticrew-event-confirmation' ) ) {
				return $block_content;
			}

			return '';
		}

		$this->block_thankyou_rendered = true;

		return $this->get_custom_thankyou_markup( $order );
	}

	/**
	 * Loads confirmation styling only on the order-received page.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		$is_order_received = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$is_checkout       = function_exists( 'is_checkout' ) && is_checkout();

		if ( ! $is_order_received && ! $is_checkout ) {
			return;
		}

		wp_enqueue_style(
			'gaticrew-events-bridge-frontend',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/css/frontend.css',
			array(),
			GATICREW_EVENTS_BRIDGE_VERSION
		);
	}

	/**
	 * Stores order meta before the classic checkout order is first saved.
	 *
	 * @param WC_Order $order WooCommerce order object before persistence.
	 * @param array    $data Posted checkout data.
	 * @return void
	 */
	public function capture_event_booking_on_checkout( $order, $data = array() ) {
		unset( $data );

		$this->capture_event_booking( $order, false );
	}

	/**
	 * Creates attendees only after WooCommerce marks the order successful.
	 *
	 * This avoids duplicate rows from checkout refreshes, cart updates, Store API
	 * draft orders, and pending payment orders.
	 *
	 * @param int      $order_id WooCommerce order ID.
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function create_attendees_when_order_is_successful( $order_id, $order = null ) {
		$order_id = absint( $order_id );

		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order || ! $order->has_status( array( 'processing', 'completed' ) ) ) {
			return;
		}

		$event_bookings = $this->get_linked_event_bookings_from_order( $order );

		if ( empty( $event_bookings ) ) {
			return;
		}

		$meta_updated = $this->ensure_order_event_meta( $order, $event_bookings[0] );
		$this->create_attendee_records_for_order( $order, $event_bookings );

		if ( $meta_updated ) {
			$order->save();
		}
	}

	/**
	 * Starts buffering WooCommerce's default thank-you output for event orders.
	 *
	 * WooCommerce prints the "Order received" notice and overview outside hookable
	 * callbacks. Buffering lets event-ticket orders replace that full layout while
	 * leaving ordinary WooCommerce orders untouched.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function start_custom_thankyou_buffer( $order_id ) {
		$order = $this->get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! $this->order_has_gaticrew_event_products( $order ) ) {
			return;
		}

		remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );

		$this->thankyou_buffer_started = true;
		ob_start();
	}

	/**
	 * Renders the custom event confirmation on the WooCommerce thank-you page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function render_thankyou_event_confirmation( $order_id ) {
		$order = $this->get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->order_has_gaticrew_event_products( $order ) ) {
			return;
		}

		if ( $this->block_thankyou_rendered ) {
			return;
		}

		if ( $this->thankyou_buffer_started && ob_get_level() > 0 ) {
			ob_end_clean();
			$this->thankyou_buffer_started = false;
		}

		$this->capture_event_booking( $order, true );

		$confirmation_items = $this->get_confirmation_items_for_order( $order );

		if ( empty( $confirmation_items ) ) {
			return;
		}

		echo $this->get_custom_thankyou_markup( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders the custom thank-you experience from the replacement template.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function render_custom_thankyou_template( WC_Order $order ) {
		if ( ! $this->order_has_gaticrew_event_products( $order ) ) {
			return;
		}

		echo $this->get_custom_thankyou_markup( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Builds the complete custom GatiCrew thank-you markup.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return string
	 */
	private function get_custom_thankyou_markup( WC_Order $order ) {
		$this->capture_event_booking( $order, true );

		$confirmation_items = $this->get_confirmation_items_for_order( $order );

		if ( empty( $confirmation_items ) ) {
			return '';
		}

		ob_start();
		include GATICREW_EVENTS_BRIDGE_PATH . 'templates/thankyou-event-confirmation.php';

		return (string) ob_get_clean();
	}

	/**
	 * Displays event booking details in the WooCommerce admin order screen.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return void
	 */
	public function render_admin_order_event_details( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$items = $this->get_confirmation_items_for_order( $order );

		if ( empty( $items ) ) {
			return;
		}

		?>
		<div class="gaticrew-event-order-details">
			<h3><?php echo esc_html__( 'GatiCrew Event Details', 'gaticrew-events-bridge' ); ?></h3>
			<?php foreach ( $items as $item ) : ?>
				<div class="gaticrew-event-order-details__item">
					<p>
						<strong><?php echo esc_html__( 'Booking ID:', 'gaticrew-events-bridge' ); ?></strong>
						<?php echo esc_html( $item['booking_id'] ); ?>
					</p>
					<p>
						<strong><?php echo esc_html__( 'Event Name:', 'gaticrew-events-bridge' ); ?></strong>
						<?php echo esc_html( $item['event_name'] ); ?>
					</p>
					<p>
						<strong><?php echo esc_html__( 'Event Date:', 'gaticrew-events-bridge' ); ?></strong>
						<?php echo esc_html( $item['event_date'] ); ?>
					</p>
					<p>
						<strong><?php echo esc_html__( 'Venue:', 'gaticrew-events-bridge' ); ?></strong>
						<?php echo esc_html( $item['event_venue'] ); ?>
					</p>
					<p>
						<strong><?php echo esc_html__( 'Attendees:', 'gaticrew-events-bridge' ); ?></strong>
						<?php echo esc_html( implode( ', ', ! empty( $item['attendee_names'] ) ? $item['attendee_names'] : array( $item['attendee_name'] ) ) ); ?>
					</p>
					<?php if ( ! empty( $item['qr_image_url'] ) ) : ?>
						<p>
							<strong><?php echo esc_html__( 'QR Ticket:', 'gaticrew-events-bridge' ); ?></strong><br>
							<a href="<?php echo esc_url( $item['qr_validation_url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img class="gaticrew-admin-qr" src="<?php echo esc_url( $item['qr_image_url'] ); ?>" alt="<?php echo esc_attr__( 'QR Ticket', 'gaticrew-events-bridge' ); ?>">
							</a>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $item['pdf_download_url'] ) ) : ?>
						<p>
							<a class="button" href="<?php echo esc_url( $item['pdf_download_url'] ); ?>">
								<?php echo esc_html__( 'Download Ticket PDF', 'gaticrew-events-bridge' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Performs guarded booking meta capture only.
	 *
	 * @param mixed $order WooCommerce order object candidate.
	 * @param bool  $persist Whether to save the order after updating meta.
	 * @return void
	 */
	private function capture_event_booking( $order, $persist ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$event_bookings = $this->get_linked_event_bookings_from_order( $order );

		if ( empty( $event_bookings ) ) {
			return;
		}

		$meta_updated = $this->ensure_order_event_meta( $order, $event_bookings[0] );

		if ( $persist && $meta_updated && $order->get_id() ) {
			$order->save();
		}
	}

	/**
	 * Finds linked event products, returning exactly one booking row per order item.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array
	 */
	private function get_linked_event_bookings_from_order( WC_Order $order ) {
		$bookings = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$linked_event = $this->get_linked_event_from_order_item( $item );

			if ( empty( $linked_event['event_id'] ) ) {
				continue;
			}

			$bookings[] = array(
				'item_id'        => absint( $item->get_id() ),
				'event_id'       => absint( $linked_event['event_id'] ),
				'product_id'     => absint( $linked_event['product_id'] ),
				'quantity'       => max( 1, absint( $item->get_quantity() ) ),
				'attendee_names' => $this->get_attendee_names_from_order_item( $item ),
			);
		}

		return $bookings;
	}

	/**
	 * Finds linked event products in the current cart.
	 *
	 * @return array
	 */
	private function get_linked_event_bookings_from_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$bookings = array();

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$linked_event = $this->get_linked_event_from_cart_item( $cart_item );

			if ( empty( $linked_event['event_id'] ) ) {
				continue;
			}

			$event_id = absint( $linked_event['event_id'] );

			$bookings[] = array(
				'cart_item_key' => sanitize_key( $cart_item_key ),
				'event_id'      => $event_id,
				'product_id'    => absint( $linked_event['product_id'] ),
				'quantity'      => isset( $cart_item['quantity'] ) ? max( 1, absint( $cart_item['quantity'] ) ) : 1,
				'event_name'    => GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ),
			);
		}

		return $bookings;
	}

	/**
	 * Resolves a WooCommerce cart item to a linked published event.
	 *
	 * @param array $cart_item Cart item values.
	 * @return array|null
	 */
	private function get_linked_event_from_cart_item( array $cart_item ) {
		$product_ids = array_filter(
			array_unique(
				array_map(
					'absint',
					array(
						isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0,
						isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0,
					)
				)
			)
		);

		foreach ( $product_ids as $product_id ) {
			$event = GatiCrew_Events_Bridge_Events::get_event_by_ticket_product_id( $product_id );

			if ( $event instanceof WP_Post ) {
				return array(
					'event_id'   => (int) $event->ID,
					'product_id' => (int) $product_id,
				);
			}
		}

		return null;
	}

	/**
	 * Resolves a WooCommerce order item to a linked published event.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return array|null
	 */
	private function get_linked_event_from_order_item( WC_Order_Item_Product $item ) {
		$product_ids = array_filter(
			array_unique(
				array_map(
					'absint',
					array(
						$item->get_variation_id(),
						$item->get_product_id(),
					)
				)
			)
		);

		foreach ( $product_ids as $product_id ) {
			$event = GatiCrew_Events_Bridge_Events::get_event_by_ticket_product_id( $product_id );

			if ( $event instanceof WP_Post ) {
				return array(
					'event_id'   => (int) $event->ID,
					'product_id' => (int) $product_id,
				);
			}
		}

		return null;
	}

	/**
	 * Saves the first event booking snapshot to WooCommerce order meta.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $event_booking Event booking data.
	 * @return bool Whether order meta was changed.
	 */
	private function ensure_order_event_meta( WC_Order $order, array $event_booking ) {
		$current_event_id = absint( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, true ) );
		$event_id         = $this->is_valid_event_id( $current_event_id ) ? $current_event_id : absint( $event_booking['event_id'] );

		if ( ! $this->is_valid_event_id( $event_id ) ) {
			return false;
		}

		$booking_id = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, true ) );

		if ( ! $booking_id ) {
			$booking_id = GatiCrew_Events_Bridge_Bookings::generate_booking_id();
		}

		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, $event_id );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_EVENT_NAME, GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ) );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, $booking_id );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_EVENT_DATE, GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_EVENT_VENUE, GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_NAME, $this->get_customer_name( $order ) );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_EMAIL, sanitize_email( $order->get_billing_email() ) );
		$order->update_meta_data( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_PHONE, wc_sanitize_phone_number( $order->get_billing_phone() ) );

		return true;
	}

	/**
	 * Creates attendee booking rows for linked event products.
	 *
	 * Each linked order item becomes one booking row containing all attendee
	 * names as JSON. This prevents duplicate attendee creation and keeps one QR
	 * code/PDF ticket attached to the full group booking.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $event_bookings Aggregated linked event booking rows.
	 * @return void
	 */
	private function create_attendee_records_for_order( WC_Order $order, array $event_bookings ) {
		$order_id = absint( $order->get_id() );

		if ( ! $order_id ) {
			return;
		}

		$customer = $this->get_customer_data( $order );

		foreach ( $event_bookings as $event_booking ) {
			$event_id = absint( $event_booking['event_id'] );

			if ( ! $event_id ) {
				continue;
			}

			$booking_id = $this->get_booking_id_for_event_record( $order, $event_booking );

			$quantity       = isset( $event_booking['quantity'] ) ? max( 1, absint( $event_booking['quantity'] ) ) : 1;
			$attendee_names = $this->normalize_attendee_names_for_quantity(
				isset( $event_booking['attendee_names'] ) ? $event_booking['attendee_names'] : array(),
				$quantity,
				$customer['name']
			);
			if ( $this->attendees_repository->exists_for_order_booking( $order_id, $booking_id ) ) {
				continue;
			}

			$this->attendees_repository->create(
				array(
					'order_id'        => $order_id,
					'event_id'        => $event_id,
					'product_id'      => isset( $event_booking['product_id'] ) ? absint( $event_booking['product_id'] ) : 0,
					'booking_id'      => $booking_id,
					'attendee_names'  => array_values( $attendee_names ),
					'attendee_email'  => $customer['email'],
					'attendee_phone'  => $customer['phone'],
					'quantity'        => $quantity,
					'status'          => 'confirmed',
				)
			);
		}
	}

	/**
	 * Reuses order meta booking ID for the primary event and generates IDs for others.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $event_booking Event booking data.
	 * @return string
	 */
	private function get_booking_id_for_event_record( WC_Order $order, array $event_booking ) {
		$item_id = isset( $event_booking['item_id'] ) ? absint( $event_booking['item_id'] ) : 0;
		$item    = $item_id ? $order->get_item( $item_id ) : false;

		if ( $item instanceof WC_Order_Item_Product ) {
			$item_booking = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $item->get_meta( self::ITEM_META_BOOKING_ID, true ) );

			if ( $item_booking ) {
				return $item_booking;
			}
		}

		$event_id      = isset( $event_booking['event_id'] ) ? absint( $event_booking['event_id'] ) : 0;
		$primary_event = absint( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, true ) );
		$order_booking = GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, true ) );
		$booking_id    = ( $event_id && $event_id === $primary_event && $order_booking ) ? $order_booking : GatiCrew_Events_Bridge_Bookings::generate_booking_id();

		if ( $item instanceof WC_Order_Item_Product ) {
			$item->update_meta_data( self::ITEM_META_BOOKING_ID, $booking_id );
			$item->save();
		}

		return $booking_id;
	}

	/**
	 * Builds confirmation view models from attendee records.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array
	 */
	private function get_confirmation_items_for_order( WC_Order $order ) {
		$attendees = $this->attendees_repository->get_by_order( $order->get_id() );

		if ( empty( $attendees ) ) {
			return $this->get_fallback_confirmation_items_from_order_meta( $order );
		}

		$items = array();
		$groups = array();

		foreach ( $attendees as $attendee ) {
			$booking_id = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';
			$event_id   = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
			$key        = $event_id . '|' . $booking_id;

			if ( '' === $booking_id ) {
				$key .= '|' . ( isset( $attendee['id'] ) ? absint( $attendee['id'] ) : count( $groups ) );
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}

			$groups[ $key ][] = $attendee;
		}

		foreach ( $groups as $group_attendees ) {
			$items[] = $this->format_confirmation_item( $order, $group_attendees[0], $group_attendees );
		}

		return $items;
	}

	/**
	 * Provides backward-compatible confirmation data from existing order meta.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array
	 */
	private function get_fallback_confirmation_items_from_order_meta( WC_Order $order ) {
		$event_id = absint( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, true ) );

		if ( ! $event_id ) {
			return array();
		}

		return array(
			array(
				'event_id'        => $event_id,
				'event_name'      => GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ),
				'event_date'      => GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ),
				'event_venue'     => GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ),
				'booking_id'      => GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_BOOKING_ID, true ) ),
				'attendee_name'   => sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_NAME, true ) ),
				'attendee_names'  => $this->get_order_meta_attendee_names( $order ),
				'attendee_count'  => max( 1, $this->get_total_linked_ticket_quantity( $order, $event_id ) ),
				'attendee_email'  => sanitize_email( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_EMAIL, true ) ),
				'attendee_phone'  => wc_sanitize_phone_number( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_CUSTOMER_PHONE, true ) ),
				'ticket_quantity' => max( 1, $this->get_total_linked_ticket_quantity( $order, $event_id ) ),
				'qr_token'          => '',
				'qr_status'         => '',
				'qr_image_url'      => '',
				'qr_validation_url' => '',
				'pdf_download_url'  => '',
			),
		);
	}

	/**
	 * Formats one attendee row for admin and thank-you display.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $attendee Attendee row.
	 * @param array    $group_attendees All attendee rows in this booking group.
	 * @return array
	 */
	private function format_confirmation_item( WC_Order $order, array $attendee, array $group_attendees = array() ) {
		$event_id      = isset( $attendee['event_id'] ) ? absint( $attendee['event_id'] ) : 0;
		$primary_event = absint( $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_ID, true ) );
		$event_name    = $event_id ? GatiCrew_Events_Bridge_Events::get_event_title_label( $event_id ) : '';
		$event_date    = $event_id ? GatiCrew_Events_Bridge_Events::get_event_date_label( $event_id ) : '';
		$event_venue   = $event_id ? GatiCrew_Events_Bridge_Events::get_venue_label( $event_id ) : '';
		$booking_id    = isset( $attendee['booking_id'] ) ? GatiCrew_Events_Bridge_Bookings::sanitize_booking_id( $attendee['booking_id'] ) : '';
		$qr_token      = isset( $attendee['qr_token'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_token( $attendee['qr_token'] ) : $booking_id;
		$attendee_id   = isset( $attendee['id'] ) ? absint( $attendee['id'] ) : 0;
		$group_attendees = empty( $group_attendees ) ? array( $attendee ) : $group_attendees;
		$attendee_names = $this->get_attendee_names_from_rows( $group_attendees );
		$ticket_quantity = isset( $attendee['ticket_quantity'] ) ? max( 1, absint( $attendee['ticket_quantity'] ) ) : count( $attendee_names );
		$ticket_quantity = max( $ticket_quantity, count( $attendee_names ) );

		if ( $event_id && $event_id === $primary_event ) {
			$event_name  = $event_name ? $event_name : sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_NAME, true ) );
			$event_date  = $event_date ? $event_date : sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_DATE, true ) );
			$event_venue = $event_venue ? $event_venue : sanitize_text_field( (string) $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_EVENT_VENUE, true ) );
		}

		return array(
			'event_id'        => $event_id,
			'event_name'      => $event_name,
			'event_date'      => $event_date,
			'event_venue'     => $event_venue,
			'booking_id'      => $booking_id,
			'attendee_name'   => isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '',
			'attendee_names'  => $attendee_names,
			'attendee_count'  => max( 1, count( $attendee_names ) ),
			'attendee_email'  => isset( $attendee['attendee_email'] ) ? sanitize_email( $attendee['attendee_email'] ) : '',
			'attendee_phone'  => isset( $attendee['attendee_phone'] ) ? wc_sanitize_phone_number( $attendee['attendee_phone'] ) : '',
			'ticket_quantity' => $ticket_quantity,
			'qr_token'          => $qr_token,
			'qr_status'         => isset( $attendee['qr_status'] ) ? GatiCrew_Events_Bridge_QR_Tokens::sanitize_status( $attendee['qr_status'] ) : '',
			'qr_image_url'      => ! empty( $attendee['qr_code'] ) ? esc_url_raw( $attendee['qr_code'] ) : ( $qr_token ? GatiCrew_Events_Bridge_QR_Tokens::get_qr_image_url( $qr_token ) : '' ),
			'qr_validation_url' => $qr_token ? GatiCrew_Events_Bridge_QR_Tokens::get_validation_url( $qr_token ) : '',
			'pdf_download_url'  => $attendee_id ? GatiCrew_Events_Bridge_PDF_Tickets::get_public_download_url( $attendee, $order ) : '',
		);
	}

	/**
	 * Totals linked ticket quantity for a specific event in an order.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param int      $event_id Event post ID.
	 * @return int
	 */
	private function get_total_linked_ticket_quantity( WC_Order $order, $event_id ) {
		$event_id = absint( $event_id );
		$total    = 0;

		foreach ( $this->get_linked_event_bookings_from_order( $order ) as $booking ) {
			if ( $event_id === absint( $booking['event_id'] ) ) {
				$total += isset( $booking['quantity'] ) ? absint( $booking['quantity'] ) : 0;
			}
		}

		return max( 1, $total );
	}

	/**
	 * Returns attendee names from grouped attendee rows.
	 *
	 * @param array $attendees Attendee rows.
	 * @return array
	 */
	private function get_attendee_names_from_rows( array $attendees ) {
		$names = array();

		foreach ( $attendees as $attendee ) {
			if ( ! empty( $attendee['attendee_names'] ) && is_array( $attendee['attendee_names'] ) ) {
				foreach ( $attendee['attendee_names'] as $name ) {
					$name = sanitize_text_field( $name );

					if ( '' !== $name ) {
						$names[] = $name;
					}
				}

				continue;
			}

			$index = isset( $attendee['ticket_index'] ) ? max( 1, absint( $attendee['ticket_index'] ) ) : count( $names ) + 1;
			$name  = isset( $attendee['attendee_name'] ) ? sanitize_text_field( $attendee['attendee_name'] ) : '';

			if ( '' !== $name ) {
				$names[ $index ] = $name;
			}
		}

		ksort( $names, SORT_NUMERIC );

		return array_values( $names );
	}

	/**
	 * Returns attendee names stored directly on the order for fallback output.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array
	 */
	private function get_order_meta_attendee_names( WC_Order $order ) {
		$names = $order->get_meta( GatiCrew_Events_Bridge::ORDER_META_ATTENDEE_NAMES, true );

		if ( ! is_array( $names ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					$names
				)
			)
		);
	}

	/**
	 * Gets a WooCommerce order by ID.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return WC_Order|false
	 */
	private function get_order( $order_id ) {
		$order_id = absint( $order_id );

		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		return wc_get_order( $order_id );
	}

	/**
	 * Resolves the current order from WooCommerce's order-received endpoint.
	 *
	 * @return WC_Order|false
	 */
	private function get_order_from_received_endpoint() {
		global $wp;

		if ( empty( $wp->query_vars['order-received'] ) ) {
			return false;
		}

		$order = $this->get_order( $wp->query_vars['order-received'] );

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		if ( isset( $_GET['key'] ) ) {
			$order_key = wc_clean( wp_unslash( $_GET['key'] ) );

			if ( $order->get_order_key() !== $order_key ) {
				return false;
			}
		}

		return $order;
	}

	/**
	 * Validates that an ID points to a published The Events Calendar event.
	 *
	 * @param int $event_id Event post ID.
	 * @return bool
	 */
	private function is_valid_event_id( $event_id ) {
		$event_id = absint( $event_id );

		return $event_id
			&& GatiCrew_Events_Bridge_Events::get_event_post_type() === get_post_type( $event_id )
			&& 'publish' === get_post_status( $event_id );
	}

	/**
	 * Checks whether an order contains at least one linked GatiCrew event product.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return bool
	 */
	private function order_has_gaticrew_event_products( WC_Order $order ) {
		return ! empty( $this->get_linked_event_bookings_from_order( $order ) );
	}

	/**
	 * Returns posted attendee names grouped by cart item key.
	 *
	 * @return array
	 */
	private function get_posted_attendee_names() {
		if ( empty( $_POST['gaticrew_attendee_names'] ) || ! is_array( $_POST['gaticrew_attendee_names'] ) ) {
			return array();
		}

		$raw_names = wp_unslash( $_POST['gaticrew_attendee_names'] );
		$names     = array();

		foreach ( (array) $raw_names as $cart_item_key => $item_names ) {
			$cart_item_key = sanitize_key( $cart_item_key );

			if ( '' === $cart_item_key || ! is_array( $item_names ) ) {
				continue;
			}

			foreach ( $item_names as $index => $name ) {
				$index = max( 1, absint( $index ) );
				$name  = sanitize_text_field( $name );

				if ( '' !== $name ) {
					$names[ $cart_item_key ][ $index ] = $name;
				}
			}
		}

		return $names;
	}

	/**
	 * Returns sanitized posted attendee names for one cart item.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $quantity Ticket quantity.
	 * @return array
	 */
	private function get_posted_attendee_names_for_cart_item( $cart_item_key, $quantity ) {
		$cart_item_key = sanitize_key( $cart_item_key );
		$quantity      = max( 1, absint( $quantity ) );
		$posted_names  = $this->get_posted_attendee_names();
		$item_names    = isset( $posted_names[ $cart_item_key ] ) && is_array( $posted_names[ $cart_item_key ] ) ? $posted_names[ $cart_item_key ] : array();
		$names         = array();

		for ( $index = 1; $index <= $quantity; $index++ ) {
			$name = isset( $item_names[ $index ] ) ? sanitize_text_field( $item_names[ $index ] ) : '';

			if ( '' !== $name ) {
				$names[ $index ] = $name;
			}
		}

		return $names;
	}

	/**
	 * Reads stored attendee names from an order item.
	 *
	 * @param WC_Order_Item_Product $item Order line item.
	 * @return array
	 */
	private function get_attendee_names_from_order_item( WC_Order_Item_Product $item ) {
		$names = $item->get_meta( self::ITEM_META_ATTENDEE_NAMES, true );

		if ( ! is_array( $names ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $names as $index => $name ) {
			$index = max( 1, absint( $index ) );
			$name  = sanitize_text_field( $name );

			if ( '' !== $name ) {
				$sanitized[ $index ] = $name;
			}
		}

		ksort( $sanitized, SORT_NUMERIC );

		return $sanitized;
	}

	/**
	 * Guarantees one attendee name for each ticket slot.
	 *
	 * @param array  $names Posted or stored names.
	 * @param int    $quantity Ticket quantity.
	 * @param string $fallback_name Customer fallback name for legacy orders.
	 * @return array
	 */
	private function normalize_attendee_names_for_quantity( array $names, $quantity, $fallback_name ) {
		$quantity      = max( 1, absint( $quantity ) );
		$fallback_name = sanitize_text_field( $fallback_name );
		$normalized    = array();

		for ( $index = 1; $index <= $quantity; $index++ ) {
			$name = isset( $names[ $index ] ) ? sanitize_text_field( $names[ $index ] ) : '';

			if ( '' === $name ) {
				$name = $fallback_name;
			}

			$normalized[ $index ] = $name;
		}

		return $normalized;
	}

	/**
	 * Builds sanitized customer data from billing fields.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array
	 */
	private function get_customer_data( WC_Order $order ) {
		return array(
			'name'  => $this->get_customer_name( $order ),
			'email' => sanitize_email( $order->get_billing_email() ),
			'phone' => wc_sanitize_phone_number( $order->get_billing_phone() ),
		);
	}

	/**
	 * Builds a sanitized customer display name from billing data.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return string
	 */
	private function get_customer_name( WC_Order $order ) {
		$name = trim(
			sprintf(
				'%1$s %2$s',
				$order->get_billing_first_name(),
				$order->get_billing_last_name()
			)
		);

		if ( '' === $name ) {
			$name = $order->get_formatted_billing_full_name();
		}

		return sanitize_text_field( $name );
	}
}
