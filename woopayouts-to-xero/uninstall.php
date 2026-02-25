<?php
/**
 * Uninstall cleanup for WooPayouts to Xero (Free).
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// Remove any scheduled hooks created by older builds.
foreach ([
	'wcpay_pi_poll_deposits',
	'wcpay_pi_license_refresh',
] as $hook) {
	$ts = wp_next_scheduled($hook);
	if ($ts) {
		wp_unschedule_event($ts, $hook);
	}
}

// Delete options used by this plugin.
delete_option('wcpay_pi_settings');
delete_option('wcpay_pi_xero_tokens');
delete_option('wcpay_pi_xero_tenant');
delete_option('wcpay_pi_delivery_states');
delete_option('wcpay_pi_invoice_meta');
delete_option('wcpay_pi_sent_deposits');
delete_option('wcpay_pi_xero_oauth_state');

// Delete any WooCommerce REST API key we created (if present).
$creds = get_option('wcpay_pi_wc_api_credentials', []);
$creds = is_array($creds) ? $creds : [];
$key_id = isset($creds['key_id']) ? (int) $creds['key_id'] : 0;

if ($key_id > 0) {
	$table = $wpdb->prefix . 'woocommerce_api_keys';
	$wpdb->delete($table, ['key_id' => $key_id], ['%d']);
}
delete_option('wcpay_pi_wc_api_credentials');
delete_option('wcpay_pi_wc_api_key_notice');
