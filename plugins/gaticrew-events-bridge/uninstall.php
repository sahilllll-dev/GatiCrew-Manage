<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package GatiCrew_Events_Bridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/roles/class-gaticrew-events-bridge-role-manager.php';

GatiCrew_Events_Bridge_Role_Manager::remove_role();
