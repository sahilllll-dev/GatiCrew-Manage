<?php
/**
 * Admin event editor integration.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Admin {
	const NONCE_ACTION = 'gaticrew_events_bridge_save_ticket_product';
	const NONCE_NAME   = 'gaticrew_events_bridge_nonce';
	const FIELD_NAME   = 'gaticrew_ticket_product_id';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . GatiCrew_Events_Bridge_Events::get_event_post_type(), array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the product selector to The Events Calendar event editor.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		add_meta_box(
			'gaticrew-events-bridge-ticket-product',
			__( 'Linked Ticket Product', 'gaticrew-events-bridge' ),
			array( $this, 'render_meta_box' ),
			GatiCrew_Events_Bridge_Events::get_event_post_type(),
			'side',
			'default'
		);
	}

	/**
	 * Renders the WooCommerce product search dropdown.
	 *
	 * @param WP_Post $post Current event post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$product_id    = absint( get_post_meta( $post->ID, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID, true ) );
		$product_label = GatiCrew_Events_Bridge_Products::get_admin_product_label( $product_id );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="gaticrew-events-bridge-field">
			<label class="screen-reader-text" for="gaticrew-events-bridge-product">
				<?php echo esc_html__( 'Linked Ticket Product', 'gaticrew-events-bridge' ); ?>
			</label>

			<select
				id="gaticrew-events-bridge-product"
				name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
				class="gaticrew-events-bridge-product-search"
				data-placeholder="<?php echo esc_attr__( 'Search for a WooCommerce product', 'gaticrew-events-bridge' ); ?>"
				data-action="woocommerce_json_search_products"
				data-security="<?php echo esc_attr( wp_create_nonce( 'search-products' ) ); ?>"
			>
				<option value=""></option>
				<?php if ( $product_id && $product_label ) : ?>
					<option value="<?php echo esc_attr( $product_id ); ?>" selected="selected">
						<?php echo esc_html( $product_label ); ?>
					</option>
				<?php endif; ?>
			</select>

			<p class="description">
				<?php echo esc_html__( 'Select the WooCommerce product used as the ticket product for this event.', 'gaticrew-events-bridge' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the selected product ID after validating nonce, permissions, and product state.
	 *
	 * @param int     $post_id Event post ID.
	 * @param WP_Post $post Event post object.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( GatiCrew_Events_Bridge_Events::get_event_post_type() !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product_id = isset( $_POST[ self::FIELD_NAME ] ) ? absint( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : 0;

		if ( ! $product_id ) {
			delete_post_meta( $post_id, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID );
			return;
		}

		if ( ! GatiCrew_Events_Bridge_Products::is_valid_product_id( $product_id ) ) {
			delete_post_meta( $post_id, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID );
			return;
		}

		update_post_meta( $post_id, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID, $product_id );
	}

	/**
	 * Loads the small admin script only on the event editor screen.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$is_event_editor = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
			&& GatiCrew_Events_Bridge_Events::get_event_post_type() === $screen->post_type;

		$is_order_editor = in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true )
			&& ( 'shop_order' === $screen->post_type || 'woocommerce_page_wc-orders' === $screen->id );

		if ( ! $is_event_editor && ! $is_order_editor ) {
			return;
		}

		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}

		wp_enqueue_style(
			'gaticrew-events-bridge-admin',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/css/admin.css',
			array(),
			GATICREW_EVENTS_BRIDGE_VERSION
		);

		if ( ! $is_event_editor ) {
			return;
		}

		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
		} elseif ( wp_script_is( 'selectWoo', 'registered' ) ) {
			wp_enqueue_script( 'selectWoo' );
		}

		wp_enqueue_script(
			'gaticrew-events-bridge-admin',
			GATICREW_EVENTS_BRIDGE_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GATICREW_EVENTS_BRIDGE_VERSION,
			true
		);
	}
}
