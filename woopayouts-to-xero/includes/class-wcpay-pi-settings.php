<?php
// File: wp-content/plugins/woopayouts-to-xero/includes/class-wcpay-pi-settings.php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class WCPay_PI_Settings {
	public const OPTION_KEY = 'wcpay_pi_settings';

	public static function defaults(): array {
		return [
			// WooPayments
			'payout_statuses' => 'paid',

			// Xero App
			'xero_client_id' => '',
			'xero_client_secret' => '',
			// Keep scopes configurable (needed by existing Xero client).
			'xero_scopes' => 'offline_access accounting.transactions accounting.settings openid profile email',

			// Invoice basics (FREE)
			'invoice_contact_name' => 'WooPayments',
			'invoice_reference_prefix' => 'WooPay Payout ',
			'invoice_reference_suffix' => '',
			'summary_account_code' => '',

			// Premium placeholders (UI-only in free build)
			'send_itemized' => 'no',
			'send_shipping' => 'yes',
			'send_fees' => 'yes',
			'invoice_status' => 'DRAFT',
			'account_code_items' => '',
			'account_code_shipping' => '',
			'account_code_fees' => '',
			'category_account_codes' => [],
		];
	}

	public static function get_all(): array {
		$raw = get_option(self::OPTION_KEY, []);
		$raw = is_array($raw) ? $raw : [];
		return array_merge(self::defaults(), $raw);
	}

	public static function get(string $key, string $default = ''): string {
		$all = self::get_all();
		$val = $all[$key] ?? $default;
		return is_scalar($val) ? (string) $val : $default;
	}

	public static function set(string $key, $value): void {
		$all = self::get_all();
		$all[$key] = $value;
		update_option(self::OPTION_KEY, $all, false);
	}

	public static function update(array $values): void {
		update_option(self::OPTION_KEY, $values, false);
	}
}
