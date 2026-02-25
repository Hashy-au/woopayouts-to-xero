<?php
// File: wp-content/plugins/wcpay-payout-invoice/includes/class-wcpay-pi-wcpay-client.php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class WCPay_PI_WCPay_Client {
	private const OPTION_CREDENTIALS = 'wcpay_pi_wc_api_credentials';
	private const OPTION_KEY_NOTICE  = 'wcpay_pi_wc_api_key_notice';

	/**
	 * Primary strategy: HTTP loopback to wp-json endpoints (matches how WooPayments expects its REST routes to run).
	 * Fallback: internal rest_do_request if loopback is blocked.
	 */
	private function dispatch(string $method, string $route, array $query = []): mixed {
		try {
			return $this->dispatch_http($method, $route, $query);
		} catch (Throwable $e) {
			// If loopback is blocked, fallback once to internal dispatch (best-effort).
			if ($this->looks_like_loopback_failure($e->getMessage())) {
				return $this->dispatch_internal($method, $route, $query);
			}
			throw $e;
		}
	}

	private function dispatch_http(string $method, string $route, array $query = []): mixed {
		$route = '/' . ltrim($route, '/');
		$url = rest_url($route);
		if (!empty($query)) {
			$url = add_query_arg($query, $url);
		}

		$creds = $this->ensure_wc_api_credentials();
		$auth = base64_encode($creds['consumer_key'] . ':' . $creds['consumer_secret']);

		$args = [
			'timeout'   => 30,
			'method'    => strtoupper($method),
			'sslverify' => (bool) apply_filters('wcpay_pi_loopback_sslverify', true),
			'headers'   => [
				'Authorization' => 'Basic ' . $auth,
				'Accept'        => 'application/json',
				'User-Agent'    => 'wcpay-payout-xero/' . (defined('WCPAY_PI_VERSION') ? WCPAY_PI_VERSION : 'dev'),
			],
		];

		$res = wp_remote_request($url, $args);
		if (is_wp_error($res)) {
			throw new RuntimeException('WooPayments loopback REST request failed: ' . $res->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		$body = (string) wp_remote_retrieve_body($res);

		if ($code === 404 && str_contains($body, 'rest_no_route')) {
			throw new RuntimeException('WooPayments REST route missing: ' . $route . '. Ensure WooPayments is active and its REST endpoints are not disabled.');
		}
		if ($code < 200 || $code >= 300) {
			throw new RuntimeException('WooPayments REST error (HTTP ' . $code . '): ' . trim(mb_substr($body, 0, 500)));
		}

		$data = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('WooPayments REST returned invalid JSON.');
		}

		return $data;
	}

	private function dispatch_internal(string $method, string $route, array $query = []): mixed {
		$route = ltrim($route, '/');
		$path  = '/' . $route;

		$req = new WP_REST_Request($method, $path);
		foreach ($query as $k => $v) {
			$req->set_param($k, $v);
		}

		$res = rest_do_request($req);
		if ($res->is_error()) {
			$err = $res->as_error();
			$code = method_exists($err, 'get_error_code') ? (string) $err->get_error_code() : 'error';
			throw new RuntimeException($code . ': ' . $err->get_error_message());
		}

		return $res->get_data();
	}

	private function looks_like_loopback_failure(string $message): bool {
		$needles = [
			'loopback',
			'cURL error',
			'timed out',
			'Could not resolve host',
			'Connection refused',
			'Operation timed out',
		];
		foreach ($needles as $n) {
			if (stripos($message, $n) !== false) {
				return true;
			}
		}
		return false;
	}

	public function deposits_routes_available(): bool {
		try {
			$this->dispatch_http('GET', 'wc/v3/payments/deposits', ['page' => 1, 'pagesize' => 1]);
			return true;
		} catch (Throwable) {
			return false;
		}
	}

	private function try_get_deposit_by_id(string $deposit_id): ?array {
		try {
			$data = $this->dispatch('GET', "wc/v3/payments/deposits/{$deposit_id}");
			return is_array($data) ? $data : null;
		} catch (Throwable) {
			return null;
		}
	}

	public function get_deposit_by_id(string $deposit_id): array {
		$data = $this->dispatch('GET', "wc/v3/payments/deposits/{$deposit_id}");
		return is_array($data) ? $data : [];
	}

	/**
	 * Resolve what wc-admin uses in URL (sometimes bank reference) to a real deposit id.
	 * - If input already works as /deposits/{id}, return it.
	 * - Else list deposits and match by bank reference fields.
	 */
	public function resolve_deposit_id(string $input_id): string {
		$input_id = trim($input_id);

		if ($input_id === '') {
			return $input_id;
		}

		// Common Stripe payout ids start with po_
		if (str_starts_with($input_id, 'po_')) {
			return $input_id;
		}

		$direct = $this->try_get_deposit_by_id($input_id);
		if (is_array($direct) && !empty($direct['id'])) {
			return (string) $direct['id'];
		}

		$pagesize = 100;
		for ($page = 1; $page <= 20; $page++) {
			$data = $this->dispatch('GET', 'wc/v3/payments/deposits', [
				'sort'      => 'date',
				'direction' => 'DESC',
				'pagesize'  => $pagesize,
				'page'      => $page,
			]);

			$list = [];
			if (is_array($data['data'] ?? null)) {
				$list = $data['data'];
			} elseif (is_array($data)) {
				$list = $data;
			}

			if (!is_array($list) || empty($list)) {
				break;
			}

			foreach ($list as $dep) {
				if (!is_array($dep)) {
					continue;
				}

				$dep_id = isset($dep['id']) ? (string) $dep['id'] : '';
				if ($dep_id === '') {
					continue;
				}

				$bank_ref_candidates = array_filter([
					(string) ($dep['bank_reference'] ?? ''),
					(string) ($dep['bankReferenceId'] ?? ''),
					(string) ($dep['bank_reference_id'] ?? ''),
					(string) ($dep['bankReference'] ?? ''),
				]);

				foreach ($bank_ref_candidates as $cand) {
					if ($cand !== '' && hash_equals($cand, $input_id)) {
						return $dep_id;
					}
				}
			}

			if (count($list) < $pagesize) {
				break;
			}
		}

		return $input_id;
	}

	public function list_deposits(int $page = 1, int $pagesize = 100): array {
		$data = $this->dispatch('GET', 'wc/v3/payments/deposits', [
			'sort'      => 'date',
			'direction' => 'DESC',
			'pagesize'  => $pagesize,
			'page'      => $page,
		]);
		if (is_array($data['data'] ?? null)) {
			return is_array($data['data']) ? $data['data'] : [];
		}
		return is_array($data) ? $data : [];
	}

	/**
	 * /wp-json/wc/v3/payments/reports/transactions supports deposit_id filter.
	 */
	public function list_transactions_for_deposit(string $deposit_id, int $per_page = 100): array {
		$page = 1;
		$out  = [];

		while (true) {
			$data = $this->dispatch('GET', 'wc/v3/payments/reports/transactions', [
				'deposit_id' => $deposit_id,
				'per_page'   => $per_page,
				'page'       => $page,
				'sort'       => 'date',
				'direction'  => 'ASC',
			]);

			if (!is_array($data)) {
				break;
			}

			$batch = $data;
			$count = count($batch);

			foreach ($batch as $row) {
				if (is_array($row)) {
					$out[] = $row;
				}
			}

			if ($count < $per_page) {
				break;
			}

			$page++;
			if ($page > 200) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Creates/stores a dedicated read-only WooCommerce REST API key if missing.
	 * This is used for loopback requests to wc/v3 endpoints.
	 */
	public function ensure_wc_api_credentials(): array {
		$stored = get_option(self::OPTION_CREDENTIALS, []);
		$stored = is_array($stored) ? $stored : [];

		if (!empty($stored['consumer_key_enc']) && !empty($stored['consumer_secret_enc']) && !empty($stored['key_id'])) {
			$ck = $this->decrypt_local((string) $stored['consumer_key_enc']);
			$cs = $this->decrypt_local((string) $stored['consumer_secret_enc']);
			if ($ck !== '' && $cs !== '') {
				return [
					'key_id' => (int) $stored['key_id'],
					'consumer_key' => $ck,
					'consumer_secret' => $cs,
				];
			}
		}

		$creds = $this->create_wc_api_key_readonly();
		update_option(self::OPTION_CREDENTIALS, [
			'key_id' => $creds['key_id'],
			'consumer_key_enc' => $this->encrypt_local($creds['consumer_key']),
			'consumer_secret_enc' => $this->encrypt_local($creds['consumer_secret']),
		], false);

		// Persistent notice until user clears.
		update_option(self::OPTION_KEY_NOTICE, [
			'created_at' => time(),
			'key_id' => $creds['key_id'],
			'user_id' => $creds['user_id'],
		], false);

		return $creds;
	}

	private function create_wc_api_key_readonly(): array {
		global $wpdb;

		if (!function_exists('wc_api_hash')) {
			// OK: we fallback to sha256 below.
		}

		$user_id = $this->pick_api_user_id();
		$ck = 'ck_' . bin2hex(random_bytes(20));
		$cs = 'cs_' . bin2hex(random_bytes(20));

		$ck_hashed = function_exists('wc_api_hash') ? wc_api_hash($ck) : hash('sha256', $ck);
		$truncated = substr($ck, -7);

		$table = $wpdb->prefix . 'woocommerce_api_keys';

		$ok = $wpdb->insert(
			$table,
			[
				'user_id'         => $user_id,
				'description'     => 'WooPayments → Xero (loopback)',
				'permissions'     => 'read',
				'consumer_key'    => $ck_hashed,
				'consumer_secret' => $cs,
				'nonces'          => null,
				'truncated_key'   => $truncated,
				'last_access'     => null,
			],
			['%d','%s','%s','%s','%s','%s','%s','%s']
		);

		if ($ok === false) {
			throw new RuntimeException('Failed to create WooCommerce REST API key (DB insert failed).');
		}

		$key_id = (int) $wpdb->insert_id;
		if ($key_id <= 0) {
			throw new RuntimeException('Failed to create WooCommerce REST API key (missing insert id).');
		}

		return [
			'key_id' => $key_id,
			'user_id' => $user_id,
			'consumer_key' => $ck,
			'consumer_secret' => $cs,
		];
	}

	private function pick_api_user_id(): int {
		$uid = get_current_user_id();
		if ($uid > 0) {
			return $uid;
		}

		$users = get_users([
			'role__in' => ['administrator', 'shop_manager'],
			'number'   => 1,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'fields'   => 'ID',
		]);
		if (is_array($users) && !empty($users[0])) {
			return (int) $users[0];
		}

		return 1;
	}

	private function local_key(): string {
		// Derive a stable 32-byte key from WP salts (not exposed in wp-admin).
		return hash('sha256', wp_salt('auth'), true);
	}

	private function encrypt_local(string $plaintext): string {
		$key = $this->local_key();
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
		return $this->bin_to_b64url($nonce . $cipher);
	}

	private function decrypt_local(string $enc_b64url): string {
		$bin = $this->b64url_to_bin($enc_b64url);
		if ($bin === '') { return ''; }
		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if (strlen($bin) < $nonce_len) { return ''; }
		$nonce = substr($bin, 0, $nonce_len);
		$cipher = substr($bin, $nonce_len);
		$plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->local_key());
		return is_string($plain) ? $plain : '';
	}

	private function bin_to_b64url(string $bin): string {
		$b64 = base64_encode($bin);
		return rtrim(strtr($b64, '+/', '-_'), '=');
	}

	private function b64url_to_bin(string $b64url): string {
		$pad = strlen($b64url) % 4;
		if ($pad) { $b64url .= str_repeat('=', 4 - $pad); }
		$b64 = strtr($b64url, '-_', '+/');
		$bin = base64_decode($b64, true);
		return is_string($bin) ? $bin : '';
	}
}
