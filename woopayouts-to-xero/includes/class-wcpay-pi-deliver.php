<?php
// File: wp-content/plugins/woopayouts-to-xero/includes/class-wcpay-pi-deliver.php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class WCPay_PI_Deliver {
	/** @var WCPay_PI_WCPay_Client */
	private $client;

	public function __construct(WCPay_PI_WCPay_Client $client) {
		$this->client = $client;
	}

	public function deliver(string $deposit_id): array {
		$settings = WCPay_PI_Settings::get_all();

		$account_code = trim((string) ($settings['summary_account_code'] ?? ''));
		if ($account_code === '') {
			return [
				'ok' => false,
				'error' => 'Missing account code. Set “Account code (required)” in Settings.',
			];
		}

		$deposit = $this->client->get_deposit_by_id($deposit_id);
		if (empty($deposit) || !is_array($deposit)) {
			return ['ok' => false, 'error' => 'Unable to load payout details from WooPayments.'];
		}

		$amount_cents = (float) ($deposit['amount'] ?? 0);
		$amount = $amount_cents / 100;

		$currency = strtoupper((string) ($deposit['currency'] ?? get_woocommerce_currency()));
		$date_raw = (string) ($deposit['date'] ?? $deposit['created'] ?? $deposit['arrival_date'] ?? '');
		$date = $date_raw !== '' ? gmdate('Y-m-d', (int) strtotime($date_raw)) : gmdate('Y-m-d');

		$contact = trim((string) ($settings['invoice_contact_name'] ?? 'WooPayments'));
		$reference = trim((string) ($settings['invoice_reference_prefix'] ?? 'WooPay Payout '))
			. $deposit_id
			. trim((string) ($settings['invoice_reference_suffix'] ?? ''));

		$invoice = [
			'Type' => 'ACCREC',
			'Status' => 'DRAFT',
			'Contact' => [
				'Name' => $contact !== '' ? $contact : 'WooPayments',
			],
			'CurrencyCode' => $currency,
			'Date' => $date,
			'DueDate' => $date,
			'Reference' => $reference,
			'LineAmountTypes' => 'Inclusive',
			'LineItems' => [
				[
					'Description' => 'WooPayments payout ' . $deposit_id,
					'Quantity' => 1,
					'UnitAmount' => (float) $amount,
					'AccountCode' => $account_code,
				],
			],
		];

		$xero = new WCPay_PI_Xero_Client();
		if (!$xero->is_connected()) {
			return ['ok' => false, 'error' => 'Xero not connected. Go to Settings and connect to Xero.'];
		}

		$res = $xero->create_invoice($invoice);

		if (!empty($res['ok'])) {
			$this->mark_sent($deposit_id);
			$this->store_invoice_meta($deposit_id, (string) ($res['body'] ?? ''));
		}

		return $res;
	}

	private function mark_sent(string $deposit_id): void {
		$sent = get_option('wcpay_pi_sent_deposits', []);
		$sent = is_array($sent) ? $sent : [];
		$sent[$deposit_id] = time();
		update_option('wcpay_pi_sent_deposits', $sent, false);
	}

	private function store_invoice_meta(string $deposit_id, string $raw_body): void {
		$meta = get_option('wcpay_pi_invoice_meta', []);
		$meta = is_array($meta) ? $meta : [];

		$invoice_id = '';
		$invoice_number = '';

		$data = json_decode($raw_body, true);
		if (is_array($data)) {
			$invs = $data['Invoices'] ?? $data['invoices'] ?? null;
			if (is_array($invs) && isset($invs[0]) && is_array($invs[0])) {
				$invoice_id = (string) ($invs[0]['InvoiceID'] ?? '');
				$invoice_number = (string) ($invs[0]['InvoiceNumber'] ?? '');
			}
		}

		$meta[$deposit_id] = [
			'invoice_id' => $invoice_id,
			'invoice_number' => $invoice_number,
			'updated_at' => time(),
		];

		update_option('wcpay_pi_invoice_meta', $meta, false);
	}
}
