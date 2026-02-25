<?php
// File: wp-content/plugins/wcpay-payout-invoice/includes/class-wcpay-pi-xero-client.php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class WCPay_PI_Xero_Client {
	private const TOKEN_OPTION = 'wcpay_pi_xero_tokens';
	private const TENANT_OPTION = 'wcpay_pi_xero_tenant';

	public function is_connected(): bool {
		$tenant = $this->get_tenant_id();
		$toks = $this->get_tokens();
		return $tenant !== '' && !empty($toks['refresh_token']);
	}

	public function get_redirect_uri(): string {
		return admin_url('admin-post.php?action=wcpay_pi_xero_callback');
	}

	public function start_connect(): void {
		$client_id = trim(WCPay_PI_Settings::get('xero_client_id'));
		$client_secret = trim(WCPay_PI_Settings::get('xero_client_secret'));

		if ($client_id === '' || $client_secret === '') {
			$url = $this->settings_url([
				'wcpay_pi_msg_type' => 'error',
				'wcpay_pi_msg' => rawurlencode(__('Missing Xero Client ID/Secret. Please save your Xero app credentials first.', 'woopayouts-to-xero')),
			]);
			wp_safe_redirect($url);
			exit;
		}

		$state = wp_generate_password(24, false, false);
		update_option('wcpay_pi_xero_oauth_state', $state, false);

		$scopes = trim(WCPay_PI_Settings::get('xero_scopes'));
		if ($scopes === '') {
			$scopes = 'offline_access accounting.transactions accounting.settings openid profile email';
		}

		$params = [
			'response_type' => 'code',
			'client_id' => $client_id,
			'redirect_uri' => $this->get_redirect_uri(),
			'scope' => $scopes,
			'state' => $state,
		];

		$authorize = 'https://login.xero.com/identity/connect/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		wp_redirect($authorize);
		exit;
	}


	public function handle_callback(): void {
		$code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
		$state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
		$saved_state = (string) get_option('wcpay_pi_xero_oauth_state', '');

		if ($code === '' || $state === '' || $state !== $saved_state) {
			wp_die('Invalid OAuth callback (missing or invalid state/code).');
		}

		delete_option('wcpay_pi_xero_oauth_state');

		$tokens = $this->exchange_code_for_tokens($code);
		$this->save_tokens($tokens);

		$tenant_id = $this->resolve_tenant_id($tokens['access_token'] ?? '');
		if ($tenant_id !== '') {
			update_option(self::TENANT_OPTION, $tenant_id, false);
		}

		wp_safe_redirect($this->settings_url(['xero_connected' => '1']));
		exit;
	}

	public function disconnect(): void {
		delete_option(self::TOKEN_OPTION);
		delete_option(self::TENANT_OPTION);
	}

	public function create_invoice(array $invoice): array {
		$access_token = $this->get_access_token();
		$tenant_id = $this->get_tenant_id();

		if ($access_token === '' || $tenant_id === '') {
			return ['ok' => false, 'error' => 'Xero not connected.'];
		}

		$url = 'https://api.xero.com/api.xro/2.0/Invoices';
		$body = wp_json_encode(['Invoices' => [$invoice]], JSON_UNESCAPED_SLASHES);

		$res = wp_remote_post($url, [
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'xero-tenant-id' => $tenant_id,
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
			'body' => $body,
		]);

		if (is_wp_error($res)) {
			return ['ok' => false, 'error' => $res->get_error_message()];
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$resp_body = (string) wp_remote_retrieve_body($res);

		return [
			'ok' => $code >= 200 && $code < 300,
			'code' => $code,
			'body' => mb_substr($resp_body, 0, 5000),
		];
	}

	
	public function get_lock_dates(): array {
		$tenant_id = $this->get_tenant_id();
		if ($tenant_id === '') {
			return [];
		}

		$cache_key = 'wcpay_pi_xero_lock_' . md5($tenant_id);
		$cached = get_transient($cache_key);
		if (is_array($cached) && isset($cached['max_lock'])) {
			return $cached;
		}

		$access_token = $this->get_access_token();
		if ($access_token === '') {
			return [];
		}

		$url = 'https://api.xero.com/api.xro/2.0/Organisations';
		$res = wp_remote_get($url, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'xero-tenant-id' => $tenant_id,
				'Accept' => 'application/json',
			],
		]);

		if (is_wp_error($res)) {
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code < 200 || $code >= 300) {
			return [];
		}

		$data = json_decode($body, true);
		$data = is_array($data) ? $data : [];
		$orgs = $data['Organisations'] ?? [];
		if (!is_array($orgs) || empty($orgs) || !is_array($orgs[0])) {
			return [];
		}

		$org = $orgs[0];

		$period_lock = self::date_only((string) ($org['PeriodLockDate'] ?? ''));
		$eoy_lock    = self::date_only((string) ($org['EndOfYearLockDate'] ?? ''));

		$max_lock = '';
		$ts_a = $period_lock !== '' ? strtotime($period_lock) : 0;
		$ts_b = $eoy_lock !== '' ? strtotime($eoy_lock) : 0;
		if ($ts_a || $ts_b) {
			$max_lock = date('Y-m-d', max($ts_a, $ts_b));
		}

		$out = [
			'period_lock' => $period_lock,
			'eoy_lock'    => $eoy_lock,
			'max_lock'    => $max_lock,
		];

		set_transient($cache_key, $out, 12 * HOUR_IN_SECONDS);

		return $out;
	}

	private static function date_only(string $dt): string {
		$dt = trim($dt);
		if ($dt === '') {
			return '';
		}
		// Often returned as "YYYY-MM-DDTHH:MM:SS" (or similar).
		if (strlen($dt) >= 10) {
			return substr($dt, 0, 10);
		}
		return $dt;
	}

private function exchange_code_for_tokens(string $code): array {
		$client_id = trim(WCPay_PI_Settings::get('xero_client_id'));
		$client_secret = trim(WCPay_PI_Settings::get('xero_client_secret'));

		if ($client_id === '' || $client_secret === '') {
			wp_die('Missing Xero Client ID/Secret.');
		}

		$res = wp_remote_post('https://identity.xero.com/connect/token', [
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept' => 'application/json',
			],
			'body' => http_build_query([
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => $this->get_redirect_uri(),
			], '', '&', PHP_QUERY_RFC3986),
		]);

		if (is_wp_error($res)) {
			wp_die('Token exchange failed: ' . esc_html($res->get_error_message()));
		}

		$code_http = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code_http < 200 || $code_http >= 300) {
			wp_die('Token exchange failed (HTTP ' . $code_http . '): ' . esc_html(mb_substr($body, 0, 500)));
		}

		$data = json_decode($body, true);
		$data = is_array($data) ? $data : [];

		return [
			'access_token' => (string) ($data['access_token'] ?? ''),
			'refresh_token' => (string) ($data['refresh_token'] ?? ''),
			'expires_in' => (int) ($data['expires_in'] ?? 0),
			'token_type' => (string) ($data['token_type'] ?? ''),
			'scope' => (string) ($data['scope'] ?? ''),
			'created_at' => time(),
		];
	}

	private function resolve_tenant_id(string $access_token): string {
		if ($access_token === '') {
			return '';
		}

		$res = wp_remote_get('https://api.xero.com/connections', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $access_token,
				'Accept' => 'application/json',
			],
		]);

		if (is_wp_error($res)) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code < 200 || $code >= 300) {
			return '';
		}

		$data = json_decode($body, true);
		$data = is_array($data) ? $data : [];
		if (empty($data) || !is_array($data[0])) {
			return '';
		}

		return (string) ($data[0]['tenantId'] ?? '');
	}

	private function get_tenant_id(): string {
		return (string) get_option(self::TENANT_OPTION, '');
	}

	private function get_tokens(): array {
		$raw = get_option(self::TOKEN_OPTION, []);
		return is_array($raw) ? $raw : [];
	}

	private function save_tokens(array $tokens): void {
		update_option(self::TOKEN_OPTION, $tokens, false);
	}

	private function get_access_token(): string {
		$toks = $this->get_tokens();
		$access = (string) ($toks['access_token'] ?? '');
		$refresh = (string) ($toks['refresh_token'] ?? '');
		$created_at = (int) ($toks['created_at'] ?? 0);
		$expires_in = (int) ($toks['expires_in'] ?? 0);

		$expires_at = $created_at > 0 && $expires_in > 0 ? $created_at + $expires_in : 0;
		$needs_refresh = $refresh !== '' && ($expires_at > 0 && $expires_at < (time() + 120));

		if ($access !== '' && !$needs_refresh) {
			return $access;
		}

		if ($refresh === '') {
			return '';
		}

		$new = $this->refresh_tokens($refresh);
		if (!empty($new['access_token'])) {
			$this->save_tokens($new);
			return (string) $new['access_token'];
		}

		return '';
	}

	private function refresh_tokens(string $refresh_token): array {
		$client_id = trim(WCPay_PI_Settings::get('xero_client_id'));
		$client_secret = trim(WCPay_PI_Settings::get('xero_client_secret'));
		if ($client_id === '' || $client_secret === '') {
			return [];
		}

		$res = wp_remote_post('https://identity.xero.com/connect/token', [
			'timeout' => 25,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept' => 'application/json',
			],
			'body' => http_build_query([
				'grant_type' => 'refresh_token',
				'refresh_token' => $refresh_token,
			], '', '&', PHP_QUERY_RFC3986),
		]);

		if (is_wp_error($res)) {
			return [];
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code < 200 || $code >= 300) {
			return [];
		}

		$data = json_decode($body, true);
		$data = is_array($data) ? $data : [];

		return [
			'access_token' => (string) ($data['access_token'] ?? ''),
			'refresh_token' => (string) ($data['refresh_token'] ?? $refresh_token),
			'expires_in' => (int) ($data['expires_in'] ?? 0),
			'token_type' => (string) ($data['token_type'] ?? ''),
			'scope' => (string) ($data['scope'] ?? ''),
			'created_at' => time(),
		];
	}

	private function settings_url(array $args = []): string {
		$url = admin_url('admin.php?page=wcpay-pi-settings');
		if (!empty($args)) {
			$url = add_query_arg($args, $url);
		}
		return $url;
	}
}
