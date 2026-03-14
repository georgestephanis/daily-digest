<?php
/**
 * Daily Digest custom Keyring services loader.
 *
 * @package DailyDigest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Keyring_Service' ) ) {
	return;
}

require_once __DIR__ . '/keyring-services/class-daily-digest-keyring-service-base.php';
require_once __DIR__ . '/keyring-services/class-daily-digest-keyring-service-clickup-oauth2.php';
require_once __DIR__ . '/keyring-services/class-daily-digest-keyring-service-clickup-pat.php';
require_once __DIR__ . '/keyring-services/class-daily-digest-keyring-service-harvest-oauth2.php';
require_once __DIR__ . '/keyring-services/class-daily-digest-keyring-service-harvest-pat.php';
require_once __DIR__ . '/keyring-services/class-daily-digest-keyring-service-slack.php';

add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Clickup_OAuth2', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Clickup_PAT', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Harvest_OAuth2', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Harvest_PAT', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Slack', 'init' ) );
