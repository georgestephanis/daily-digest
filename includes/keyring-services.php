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

require_once __DIR__ . '/keyring-services/base.php';
require_once __DIR__ . '/keyring-services/clickup.php';
require_once __DIR__ . '/keyring-services/slack.php';

add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Clickup', 'init' ) );
add_action( 'keyring_load_services', array( 'Daily_Digest_Keyring_Service_Slack', 'init' ) );
