<?php
// File: wp-content/plugins/woopayouts-to-xero/includes/class-wcpay-pi-plugin.php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class WCPay_PI_Plugin {
	private static $instance = null;

	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		if (!$this->deps_ok()) {
			add_action('admin_notices', [$this, 'admin_notice_missing_deps']);
			return;
		}

		require_once WCPAY_PI_PATH . 'includes/class-wcpay-pi-settings.php';
		require_once WCPAY_PI_PATH . 'includes/class-wcpay-pi-admin.php';
		require_once WCPAY_PI_PATH . 'includes/class-wcpay-pi-wcpay-client.php';
		require_once WCPAY_PI_PATH . 'includes/class-wcpay-pi-xero-client.php';
		require_once WCPAY_PI_PATH . 'includes/class-wcpay-pi-deliver.php';

		WCPay_PI_Admin::init();
	}

	private function deps_ok(): bool {
		$wc_ok = class_exists('WooCommerce') || defined('WC_VERSION');

		$wcpay_ok =
			class_exists('WC_Payments') ||
			class_exists('\WooCommerce\Payments\Main') ||
			defined('WC_PAYMENTS_VERSION_NUMBER') ||
			defined('WCPAY_VERSION_NUMBER');

		// WooPayments may lazy-load its classes; fall back to checking active plugin file in wp-admin.
		if (!$wcpay_ok && defined('ABSPATH')) {
			$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
			if (is_readable($plugin_file)) {
				require_once $plugin_file;
				if (function_exists('is_plugin_active')) {
					$wcpay_ok = is_plugin_active('woocommerce-payments/woocommerce-payments.php');
				}
			}
		}

		return $wc_ok && $wcpay_ok;
	}

	public function admin_notice_missing_deps(): void {
		?>
		<div class="notice notice-error">
			<p><strong><?php echo esc_html__('WooPayouts to Xero requires WooCommerce and WooPayments.', 'woopayouts-to-xero'); ?></strong></p>
		</div>
		<?php
	}
}
