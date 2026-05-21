<?php
/**
 * GatiCrew operator role and capabilities.
 *
 * The custom role is intentionally narrow. It receives only the primitive
 * capabilities needed for event operations, ticket products, order handling,
 * attendee management, and gate check-ins.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class GatiCrew_Events_Bridge_Role_Manager {
	const ROLE_KEY = 'gaticrew_manager';
	const ROLE_NAME = 'GatiCrew Manager';

	const CAP_MANAGE_ATTENDEES = 'manage_gaticrew_attendees';
	const CAP_MANAGE_CHECKINS  = 'manage_gaticrew_checkins';
	const CAP_MANAGE_TICKETS   = 'manage_gaticrew_tickets';

	/**
	 * Creates or updates the role with the current least-privilege capability set.
	 *
	 * @return void
	 */
	public static function sync_role() {
		$capabilities = self::get_capabilities();
		$role         = get_role( self::ROLE_KEY );

		if ( ! $role ) {
			add_role( self::ROLE_KEY, self::ROLE_NAME, $capabilities );
		} else {
			foreach ( array_keys( (array) $role->capabilities ) as $capability ) {
				if ( ! isset( $capabilities[ $capability ] ) ) {
					$role->remove_cap( $capability );
				}
			}

			foreach ( $capabilities as $capability => $grant ) {
				if ( ! isset( $role->capabilities[ $capability ] ) || (bool) $role->capabilities[ $capability ] !== (bool) $grant ) {
					$role->add_cap( $capability, (bool) $grant );
				}
			}
		}

		self::grant_plugin_caps_to_administrators();
	}

	/**
	 * Removes the custom role and plugin-owned capabilities.
	 *
	 * @return void
	 */
	public static function remove_role() {
		remove_role( self::ROLE_KEY );

		$administrator = get_role( 'administrator' );

		if ( ! $administrator ) {
			return;
		}

		foreach ( self::get_custom_capabilities() as $capability ) {
			$administrator->remove_cap( $capability );
		}
	}

	/**
	 * Returns the full capability map assigned to GatiCrew Managers.
	 *
	 * @return array
	 */
	public static function get_capabilities() {
		$capabilities = array(
			'read'                 => true,
			'view_admin_dashboard' => true,

			self::CAP_MANAGE_ATTENDEES => true,
			self::CAP_MANAGE_CHECKINS  => true,
			self::CAP_MANAGE_TICKETS   => true,
		);

		foreach ( self::get_events_capabilities() as $capability ) {
			$capabilities[ $capability ] = true;
		}

		foreach ( self::get_product_capabilities() as $capability ) {
			$capabilities[ $capability ] = true;
		}

		foreach ( self::get_ticket_capabilities() as $capability ) {
			$capabilities[ $capability ] = true;
		}

		foreach ( self::get_order_capabilities() as $capability ) {
			$capabilities[ $capability ] = true;
		}

		return $capabilities;
	}

	/**
	 * Returns plugin-owned custom capabilities.
	 *
	 * @return array
	 */
	public static function get_custom_capabilities() {
		return array(
			self::CAP_MANAGE_ATTENDEES,
			self::CAP_MANAGE_CHECKINS,
			self::CAP_MANAGE_TICKETS,
		);
	}

	/**
	 * Determines whether the current user is operating as a GatiCrew Manager.
	 *
	 * Administrators are excluded so normal site admins keep the full WordPress
	 * experience even if they are also assigned the GatiCrew Manager role.
	 *
	 * @return bool
	 */
	public static function current_user_is_gaticrew_manager() {
		$user = wp_get_current_user();

		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}

		return in_array( self::ROLE_KEY, (array) $user->roles, true )
			&& ! in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Adds the plugin-owned caps to administrators for explicit capability checks.
	 *
	 * @return void
	 */
	private static function grant_plugin_caps_to_administrators() {
		$administrator = get_role( 'administrator' );

		if ( ! $administrator ) {
			return;
		}

		foreach ( array_keys( self::get_capabilities() ) as $capability ) {
			if ( empty( $administrator->capabilities[ $capability ] ) ) {
				$administrator->add_cap( $capability, true );
			}
		}
	}

	/**
	 * Returns Events Calendar event, venue, and organizer edit capabilities.
	 *
	 * @return array
	 */
	private static function get_events_capabilities() {
		return array_merge(
			self::get_limited_post_type_capabilities( 'tribe_event', 'tribe_events' ),
			self::get_limited_post_type_capabilities( 'tribe_venue', 'tribe_venues' ),
			self::get_limited_post_type_capabilities( 'tribe_organizer', 'tribe_organizers' )
		);
	}

	/**
	 * Returns WooCommerce product edit capabilities without broad Woo settings access.
	 *
	 * @return array
	 */
	private static function get_product_capabilities() {
		return array_merge(
			self::get_limited_post_type_capabilities( 'product', 'products' ),
			array(
				'manage_product_terms',
				'edit_product_terms',
				'assign_product_terms',
			)
		);
	}

	/**
	 * Returns common Event Tickets edit capabilities without global settings access.
	 *
	 * @return array
	 */
	private static function get_ticket_capabilities() {
		return array_merge(
			self::get_limited_post_type_capabilities( 'tribe_ticket', 'tribe_tickets' ),
			array(
				'manage_tribe_tickets',
				'edit_tribe_tickets',
				'read_tribe_tickets',
			)
		);
	}

	/**
	 * Returns order capabilities needed to view orders and update statuses.
	 *
	 * @return array
	 */
	private static function get_order_capabilities() {
		return array(
			'edit_shop_order',
			'read_shop_order',
			'edit_shop_orders',
			'edit_others_shop_orders',
			'read_private_shop_orders',
			'edit_private_shop_orders',
			'edit_published_shop_orders',
		);
	}

	/**
	 * Builds a non-destructive create/edit/publish capability set for post types.
	 *
	 * Delete caps are intentionally omitted because the manager role is for
	 * operations, not full content or commerce administration.
	 *
	 * @param string $singular Singular capability base.
	 * @param string $plural Plural capability base.
	 * @return array
	 */
	private static function get_limited_post_type_capabilities( $singular, $plural ) {
		return array(
			'edit_' . $singular,
			'read_' . $singular,
			'edit_' . $plural,
			'edit_others_' . $plural,
			'publish_' . $plural,
			'read_private_' . $plural,
			'edit_private_' . $plural,
			'edit_published_' . $plural,
		);
	}

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {}
}
