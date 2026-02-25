<?php
// File: wp-content/plugins/woopayouts-to-xero/includes/class-wcpay-pi-admin.php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

final class WCPay_PI_Admin {
	private const MENU_SLUG_STATUS   = 'wcpay-pi-status';
	private const MENU_SLUG_SETTINGS = 'wcpay-pi-settings';
	private const MENU_SLUG_PREMIUM  = 'wcpay-pi-go-premium';

	private const OPTION_DELIVERY_STATES = 'wcpay_pi_delivery_states';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'admin_menu']);

		add_action('admin_post_wcpay_pi_save_settings', [__CLASS__, 'handle_save_settings']);
		add_action('admin_post_wcpay_pi_send_deposit', [__CLASS__, 'handle_send_deposit']);

		// Xero OAuth handlers.
		add_action('admin_post_wcpay_pi_xero_connect', [__CLASS__, 'handle_xero_connect']);
		add_action('admin_post_wcpay_pi_xero_callback', [__CLASS__, 'handle_xero_callback']);
		add_action('admin_post_wcpay_pi_xero_disconnect', [__CLASS__, 'handle_xero_disconnect']);
	}

	public static function admin_menu(): void {
		add_menu_page(
			__('WooPayouts to Xero', 'woopayouts-to-xero'),
			__('WooPayouts to Xero', 'woopayouts-to-xero'),
			'manage_woocommerce',
			self::MENU_SLUG_STATUS,
			[__CLASS__, 'render_status_page'],
			'dashicons-migrate',
			56
		);

		add_submenu_page(
			self::MENU_SLUG_STATUS,
			__('Payout Status', 'woopayouts-to-xero'),
			__('Payout Status', 'woopayouts-to-xero'),
			'manage_woocommerce',
			self::MENU_SLUG_STATUS,
			[__CLASS__, 'render_status_page']
		);

		add_submenu_page(
			self::MENU_SLUG_STATUS,
			__('Settings', 'woopayouts-to-xero'),
			__('Settings', 'woopayouts-to-xero'),
			'manage_woocommerce',
			self::MENU_SLUG_SETTINGS,
			[__CLASS__, 'render_settings_page']
		);

		add_submenu_page(
			self::MENU_SLUG_STATUS,
			__('Go Premium', 'woopayouts-to-xero'),
			__('Go Premium', 'woopayouts-to-xero'),
			'manage_woocommerce',
			self::MENU_SLUG_PREMIUM,
			[__CLASS__, 'render_premium_page']
		);
	}

	public static function render_status_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}

		$settings = WCPay_PI_Settings::get_all();
		$states   = self::get_delivery_states();
		$invoices = get_option('wcpay_pi_invoice_meta', []);
		$invoices = is_array($invoices) ? $invoices : [];

		$client = new WCPay_PI_WCPay_Client();
		$api_error = '';
		$deposits = [];

		try {
			$deposits = $client->list_deposits(1, 50);
			$deposits = is_array($deposits) ? $deposits : [];
		} catch (Throwable $e) {
			$api_error = $e->getMessage();
			$deposits = [];
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Payout Status', 'woopayouts-to-xero'); ?></h1>

			<?php if ($api_error !== ''): ?>
				<div class="notice notice-error"><p><?php echo esc_html($api_error); ?></p></div>
			<?php endif; ?>

			<p><?php echo esc_html__('Free version: sends a single-line draft invoice with the WooPayments payout total.', 'woopayouts-to-xero'); ?></p>

			<table class="widefat striped" style="max-width: 1200px;">
				<thead>
					<tr>
						<th><?php echo esc_html__('Payout ID', 'woopayouts-to-xero'); ?></th>
						<th><?php echo esc_html__('Date', 'woopayouts-to-xero'); ?></th>
						<th><?php echo esc_html__('Status', 'woopayouts-to-xero'); ?></th>
						<th><?php echo esc_html__('Amount', 'woopayouts-to-xero'); ?></th>
						<th><?php echo esc_html__('Xero', 'woopayouts-to-xero'); ?></th>
						<th><?php echo esc_html__('Action', 'woopayouts-to-xero'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if (empty($deposits)): ?>
					<tr><td colspan="6"><?php echo esc_html__('No payouts found.', 'woopayouts-to-xero'); ?></td></tr>
				<?php else: ?>
					<?php foreach ($deposits as $dep): ?>
						<?php
						$dep_id   = (string) ($dep['id'] ?? '');
						$date_raw = (string) ($dep['date'] ?? $dep['created'] ?? $dep['arrival_date'] ?? '');
						$status   = (string) ($dep['status'] ?? '');
						$amount   = (string) ($dep['amount'] ?? '');
						$currency = (string) ($dep['currency'] ?? '');

						$date_display = self::format_deposit_date($date_raw);
						$amount_display = self::format_deposit_amount($amount, $currency);

						$st = $states[$dep_id] ?? ['state' => '—'];
						$state = (string) ($st['state'] ?? '—');

						$inv_meta = $invoices[$dep_id] ?? [];
						$inv_id = (string) ($inv_meta['invoice_id'] ?? '');
						$inv_num = (string) ($inv_meta['invoice_number'] ?? '');
						$inv_url = '';
						if ($inv_id !== '') {
							$inv_url = 'https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=' . rawurlencode($inv_id);
						}

						$badge_style = '';
						if ($state === 'sent') { $badge_style = 'color:#0a7;font-weight:600;'; }
						if ($state === 'pending') { $badge_style = 'color:#b86e00;font-weight:600;'; }
						if ($state === 'error') { $badge_style = 'color:#a00;font-weight:600;'; }

						$send_url = wp_nonce_url(
							admin_url('admin-post.php?action=wcpay_pi_send_deposit&deposit_id=' . rawurlencode($dep_id)),
							'wcpay_pi_send_deposit'
						);
						?>
						<tr>
							<td><code><?php echo esc_html($dep_id); ?></code></td>
							<td><?php echo esc_html($date_display); ?></td>
							<td><?php echo esc_html($status !== '' ? $status : '—'); ?></td>
							<td><?php echo wp_kses_post($amount_display); ?></td>
							<td>
								<span style="<?php echo esc_attr($badge_style); ?>"><?php echo esc_html($state); ?></span>
								<?php if ($state === 'sent' && $inv_url !== ''): ?>
									<br />
									<a href="<?php echo esc_url($inv_url); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html($inv_num !== '' ? ('Invoice ' . $inv_num) : __('View in Xero', 'woopayouts-to-xero')); ?>
									</a>
								<?php endif; ?>
							</td>
							<td>
								<a class="button button-primary" href="<?php echo esc_url($send_url); ?>">
									<?php echo esc_html__('Send to Xero', 'woopayouts-to-xero'); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}

		$settings = WCPay_PI_Settings::get_all();
		$xero = new WCPay_PI_Xero_Client();

		$save_url = wp_nonce_url(admin_url('admin-post.php?action=wcpay_pi_save_settings'), 'wcpay_pi_save_settings');

		$connect_url = wp_nonce_url(admin_url('admin-post.php?action=wcpay_pi_xero_connect'), 'wcpay_pi_xero_connect');
		$disconnect_url = wp_nonce_url(admin_url('admin-post.php?action=wcpay_pi_xero_disconnect'), 'wcpay_pi_xero_disconnect');

		$redirect_uri = $xero->get_redirect_uri();
		$dev_portal = 'https://developer.xero.com/app/manage';

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Settings', 'woopayouts-to-xero'); ?></h1>

			<p>
				<?php echo esc_html__('To connect, create a Xero app and paste Client ID and Client Secret below.', 'woopayouts-to-xero'); ?>
				<a href="<?php echo esc_url($dev_portal); ?>" target="_blank" rel="nofollow noopener noreferrer"><?php echo esc_html__('Open Xero Developer “My Apps”', 'woopayouts-to-xero'); ?></a>
			</p>

			<p><strong><?php echo esc_html__('Redirect URI', 'woopayouts-to-xero'); ?>:</strong> <code><?php echo esc_html($redirect_uri); ?></code></p>

			<form method="post" action="<?php echo esc_url($save_url); ?>">
				<?php wp_nonce_field('wcpay_pi_save_settings'); ?>

				<table class="form-table" role="presentation" style="max-width: 900px;">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__('Xero Client ID', 'woopayouts-to-xero'); ?></th>
							<td><input type="text" class="regular-text" style="min-width:420px" name="wcpay_pi_settings[xero_client_id]" value="<?php echo esc_attr((string) ($settings['xero_client_id'] ?? '')); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__('Xero Client Secret', 'woopayouts-to-xero'); ?></th>
							<td><input type="password" class="regular-text" style="min-width:420px" name="wcpay_pi_settings[xero_client_secret]" value="<?php echo esc_attr((string) ($settings['xero_client_secret'] ?? '')); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__('Contact name', 'woopayouts-to-xero'); ?></th>
							<td><input type="text" class="regular-text" name="wcpay_pi_settings[invoice_contact_name]" value="<?php echo esc_attr((string) ($settings['invoice_contact_name'] ?? 'WooPayments')); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__('Reference prefix', 'woopayouts-to-xero'); ?></th>
							<td><input type="text" class="regular-text" name="wcpay_pi_settings[invoice_reference_prefix]" value="<?php echo esc_attr((string) ($settings['invoice_reference_prefix'] ?? 'WooPay Payout ')); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__('Reference suffix', 'woopayouts-to-xero'); ?></th>
							<td><input type="text" class="regular-text" name="wcpay_pi_settings[invoice_reference_suffix]" value="<?php echo esc_attr((string) ($settings['invoice_reference_suffix'] ?? '')); ?>" /></td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__('Account code (required)', 'woopayouts-to-xero'); ?></th>
							<td>
								<input type="text" class="regular-text" name="wcpay_pi_settings[summary_account_code]" value="<?php echo esc_attr((string) ($settings['summary_account_code'] ?? '')); ?>" />
								<p class="description"><?php echo esc_html__('Free version creates a single line invoice. Xero often requires an account code.', 'woopayouts-to-xero'); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php echo esc_html__('Payout statuses', 'woopayouts-to-xero'); ?></th>
							<td>
								<input type="text" class="regular-text" name="wcpay_pi_settings[payout_statuses]" value="<?php echo esc_attr((string) ($settings['payout_statuses'] ?? 'paid')); ?>" />
								<p class="description"><?php echo esc_html__('(Optional) Used by premium auto-send. Comma-separated. Example: paid', 'woopayouts-to-xero'); ?></p>
							</td>
						</tr>

					</tbody>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php echo esc_html__('Save settings', 'woopayouts-to-xero'); ?></button>

					<?php if ($xero->is_connected()): ?>
						<a class="button button-secondary" href="<?php echo esc_url($disconnect_url); ?>"><?php echo esc_html__('Disconnect Xero', 'woopayouts-to-xero'); ?></a>
					<?php else: ?>
						<a class="button button-secondary" href="<?php echo esc_url($connect_url); ?>"><?php echo esc_html__('Connect to Xero', 'woopayouts-to-xero'); ?></a>
					<?php endif; ?>
				</p>

				<hr />

				<h2><?php echo esc_html__('Premium features (locked in Free)', 'woopayouts-to-xero'); ?></h2>
				<p><?php echo esc_html__('Upgrade to unlock itemised exports, category account mapping, refunds, fees/shipping controls, and “Awaiting payment” invoices.', 'woopayouts-to-xero'); ?></p>

				<table class="form-table" role="presentation" style="max-width: 900px;">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__('Send itemised invoice', 'woopayouts-to-xero'); ?></th>
							<td><label><input type="checkbox" disabled /> <?php echo esc_html__('Export per-product line items', 'woopayouts-to-xero'); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Send invoice as', 'woopayouts-to-xero'); ?></th>
							<td>
								<select disabled>
									<option><?php echo esc_html__('Draft', 'woopayouts-to-xero'); ?></option>
									<option><?php echo esc_html__('Awaiting payment', 'woopayouts-to-xero'); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Account code mapping', 'woopayouts-to-xero'); ?></th>
							<td><input type="text" class="regular-text" disabled value="" placeholder="<?php echo esc_attr__('Category → Account codes', 'woopayouts-to-xero'); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Refund handling', 'woopayouts-to-xero'); ?></th>
							<td><label><input type="checkbox" disabled /> <?php echo esc_html__('Handle refunds and debits', 'woopayouts-to-xero'); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Fees & shipping controls', 'woopayouts-to-xero'); ?></th>
							<td><label><input type="checkbox" disabled /> <?php echo esc_html__('Send fees/shipping as separate lines', 'woopayouts-to-xero'); ?></label></td>
						</tr>
					</tbody>
				</table>

				<p>
					<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG_PREMIUM)); ?>">
						<?php echo esc_html__('Go Premium', 'woopayouts-to-xero'); ?>
					</a>
				</p>

			</form>
		</div>
		<?php
	}

	public static function render_premium_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die('Forbidden');
		}

		$url = 'https://hashy.com.au/shop/woopayouts-to-xero-plugin/';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Go Premium', 'woopayouts-to-xero'); ?></h1>

			<p><?php echo esc_html__('Unlock premium exports and automation:', 'woopayouts-to-xero'); ?></p>
			<ul style="list-style:disc;padding-left:18px;">
				<li><?php echo esc_html__('Full line item exports (per item in each payout)', 'woopayouts-to-xero'); ?></li>
				<li><?php echo esc_html__('Refund handling for payouts & debits', 'woopayouts-to-xero'); ?></li>
				<li><?php echo esc_html__('Account code linking to categories', 'woopayouts-to-xero'); ?></li>
				<li><?php echo esc_html__('Fees & shipping line controls', 'woopayouts-to-xero'); ?></li>
				<li><?php echo esc_html__('Send invoice as “Awaiting payment”', 'woopayouts-to-xero'); ?></li>
				<li><?php echo esc_html__('Automatic updates while support is active', 'woopayouts-to-xero'); ?></li>
			</ul>

			<p>
				<a class="button button-primary" href="<?php echo esc_url($url); ?>" target="_blank" rel="nofollow noopener noreferrer">
					<?php echo esc_html__('View Premium Plugin', 'woopayouts-to-xero'); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public static function handle_save_settings(): void {
		if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden'); }
		check_admin_referer('wcpay_pi_save_settings');

		$posted = isset($_POST['wcpay_pi_settings']) && is_array($_POST['wcpay_pi_settings'])
			? wp_unslash($_POST['wcpay_pi_settings'])
			: [];

		$defaults = WCPay_PI_Settings::defaults();
		$out = WCPay_PI_Settings::get_all();

		$out['xero_client_id'] = sanitize_text_field((string) ($posted['xero_client_id'] ?? $defaults['xero_client_id']));
		$out['xero_client_secret'] = sanitize_text_field((string) ($posted['xero_client_secret'] ?? $defaults['xero_client_secret']));

		$out['invoice_contact_name'] = sanitize_text_field((string) ($posted['invoice_contact_name'] ?? $defaults['invoice_contact_name']));
		$out['invoice_reference_prefix'] = sanitize_text_field((string) ($posted['invoice_reference_prefix'] ?? $defaults['invoice_reference_prefix']));
		$out['invoice_reference_suffix'] = sanitize_text_field((string) ($posted['invoice_reference_suffix'] ?? $defaults['invoice_reference_suffix']));

		$out['summary_account_code'] = sanitize_text_field((string) ($posted['summary_account_code'] ?? $defaults['summary_account_code']));
		$out['payout_statuses'] = sanitize_text_field((string) ($posted['payout_statuses'] ?? $defaults['payout_statuses']));

		WCPay_PI_Settings::update($out);

		wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_SETTINGS . '&wcpay_pi_msg=' . rawurlencode('Settings saved.')));
		exit;
	}

	public static function handle_send_deposit(): void {
		if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden'); }
		check_admin_referer('wcpay_pi_send_deposit');

		$deposit_id = isset($_GET['deposit_id']) ? sanitize_text_field(wp_unslash($_GET['deposit_id'])) : '';
		if ($deposit_id === '') {
			wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_STATUS));
			exit;
		}

		$client = new WCPay_PI_WCPay_Client();
		$deliver = new WCPay_PI_Deliver($client);

		self::set_delivery_state($deposit_id, 'pending');

		$res = $deliver->deliver($deposit_id);

		if (!empty($res['ok'])) {
			self::set_delivery_state($deposit_id, 'sent');
			wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_STATUS . '&wcpay_pi_msg=' . rawurlencode('Sent to Xero (draft).')));
			exit;
		}

		self::set_delivery_state($deposit_id, 'error');
		wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_STATUS . '&wcpay_pi_msg_type=error&wcpay_pi_msg=' . rawurlencode('Send failed. Check Settings and Xero connection.')));
		exit;
	}

	public static function handle_xero_connect(): void {
		if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden'); }
		check_admin_referer('wcpay_pi_xero_connect');

		$xero = new WCPay_PI_Xero_Client();
		$xero->start_connect();
	}

	public static function handle_xero_callback(): void {
		if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden'); }
		$xero = new WCPay_PI_Xero_Client();
		$xero->handle_callback();
	}

	public static function handle_xero_disconnect(): void {
		if (!current_user_can('manage_woocommerce')) { wp_die('Forbidden'); }
		check_admin_referer('wcpay_pi_xero_disconnect');

		$xero = new WCPay_PI_Xero_Client();
		$xero->disconnect();

		wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG_SETTINGS . '&wcpay_pi_msg=' . rawurlencode('Disconnected from Xero.')));
		exit;
	}

	private static function get_delivery_states(): array {
		$states = get_option(self::OPTION_DELIVERY_STATES, []);
		return is_array($states) ? $states : [];
	}

	private static function set_delivery_state(string $deposit_id, string $state): void {
		$states = self::get_delivery_states();
		$states[$deposit_id] = [
			'state' => $state,
			'updated_at' => time(),
		];
		update_option(self::OPTION_DELIVERY_STATES, $states, false);
	}

	private static function format_deposit_date(string $raw): string {
		if ($raw === '') {
			return '—';
		}
		$ts = strtotime($raw);
		if (!$ts) {
			return $raw;
		}
		return date_i18n('d/m/Y', $ts);
	}

	private static function format_deposit_amount($raw_amount, string $raw_currency): string {
		$amount_cents = (float) $raw_amount;
		$amount       = $amount_cents / 100;

		$currency = strtoupper($raw_currency !== '' ? $raw_currency : get_woocommerce_currency());

		if (function_exists('wc_price')) {
			return wp_kses_post(wc_price($amount, ['currency' => $currency]));
		}

		return sprintf('%s %.2f', $currency, $amount);
	}
}
