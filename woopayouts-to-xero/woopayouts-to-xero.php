<?php
/**
 * Plugin Name: WooPayouts to Xero (Free)
 * Description: Send WooPayments payouts to Xero as a draft invoice (single-line payout total). Upgrade to unlock itemised exports and premium features.
 * Version: 1.0.0
 * Author: Hashy.com.au
 * Author URI: https://hashy.com.au/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woopayouts-to-xero
 * Domain Path: /languages
 * Requires Plugins: woocommerce, woocommerce-payments
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('WCPAY_PI_VERSION', '1.0.0');
define('WCPAY_PI_PLUGIN_FILE', __FILE__);
define('WCPAY_PI_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WCPAY_PI_PATH', plugin_dir_path(__FILE__));
define('WCPAY_PI_URL', plugin_dir_url(__FILE__));

require_once WCPAY_PI_PATH . 'includes/class-wcpay-pi-plugin.php';

add_action('plugins_loaded', static function (): void {
	WCPay_PI_Plugin::instance()->init();
});
