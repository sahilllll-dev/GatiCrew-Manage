<?php
/**
 * Public REST API controller.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_REST_Controller extends WP_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'gaticrew/v1';

	/**
	 * REST route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'events';

	/**
	 * Registers REST hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers /wp-json/gaticrew/v1/events.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Returns published events and linked product data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );

		$query = new WP_Query(
			array(
				'post_type'              => GatiCrew_Events_Bridge_Events::get_event_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'meta_value',
				'meta_key'               => '_EventStartDate',
				'order'                  => 'ASC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$items = array();

		foreach ( $query->posts as $event ) {
			$items[] = $this->prepare_event_for_response( $event, $request );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * Builds one event response item.
	 *
	 * @param WP_Post         $event Event post.
	 * @param WP_REST_Request $request REST request.
	 * @return array
	 */
	private function prepare_event_for_response( $event, $request ) {
		$product_id   = absint( get_post_meta( $event->ID, GatiCrew_Events_Bridge::META_KEY_TICKET_PRODUCT_ID, true ) );
		$product_data = GatiCrew_Events_Bridge_Products::get_public_product_data( $product_id );

		$data = array(
			'event_id'       => (int) $event->ID,
			'title'          => html_entity_decode( get_the_title( $event ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'slug'           => $event->post_name,
			'description'    => $this->get_event_description( $event ),
			'featured_image' => get_the_post_thumbnail_url( $event->ID, 'full' ) ?: null,
			'event_date'     => GatiCrew_Events_Bridge_Events::get_event_date( $event->ID ),
			'venue'          => GatiCrew_Events_Bridge_Events::get_venue( $event->ID ),
		);

		$data = array_merge( $data, $product_data );

		return $this->filter_response_by_context( $data, $request['context'] );
	}

	/**
	 * Returns rendered event content with normal WordPress sanitization.
	 *
	 * @param WP_Post $event Event post.
	 * @return string
	 */
	private function get_event_description( $event ) {
		$content = apply_filters( 'the_content', $event->post_content );

		return wp_kses_post( $content );
	}

	/**
	 * Collection query parameters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'context'  => $this->get_context_param( array( 'default' => 'view' ) ),
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'gaticrew-events-bridge' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of events to return.', 'gaticrew-events-bridge' ),
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Public schema for generated REST documentation.
	 *
	 * @return array
	 */
	public function get_public_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'gaticrew_event',
			'type'       => 'object',
			'properties' => array(
				'event_id'                       => array(
					'description' => __( 'Event ID.', 'gaticrew-events-bridge' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
				'title'                          => array(
					'description' => __( 'Event title.', 'gaticrew-events-bridge' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'slug'                           => array(
					'description' => __( 'Event slug.', 'gaticrew-events-bridge' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'description'                    => array(
					'description' => __( 'Rendered event description.', 'gaticrew-events-bridge' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'featured_image'                 => array(
					'description' => __( 'Featured image URL.', 'gaticrew-events-bridge' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
					'context'     => array( 'view' ),
				),
				'event_date'                     => array(
					'description' => __( 'Event date details.', 'gaticrew-events-bridge' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
				),
				'venue'                          => array(
					'description' => __( 'Event venue details.', 'gaticrew-events-bridge' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view' ),
				),
				'linked_woocommerce_product_id'  => array(
					'description' => __( 'Linked WooCommerce product ID.', 'gaticrew-events-bridge' ),
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view' ),
				),
				'product_name'                   => array(
					'description' => __( 'Linked product name.', 'gaticrew-events-bridge' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view' ),
				),
				'product_price'                  => array(
					'description' => __( 'Linked product price.', 'gaticrew-events-bridge' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view' ),
				),
				'product_permalink'              => array(
					'description' => __( 'Linked product permalink.', 'gaticrew-events-bridge' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
					'context'     => array( 'view' ),
				),
				'product_stock_quantity'         => array(
					'description' => __( 'Linked product stock quantity.', 'gaticrew-events-bridge' ),
					'type'        => array( 'integer', 'null' ),
					'context'     => array( 'view' ),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
