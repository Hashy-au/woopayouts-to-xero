// File: wp-content/plugins/wcpay-payout-invoice/assets/admin.js
(function () {
	'use strict';

	if (!window.WCPayPIPdf) return;

	const REST = window.WCPayPIPdf.restUrl;
	const NONCE = window.WCPayPIPdf.nonce;

	const MONTHS =
		'(January|February|March|April|May|June|July|August|September|October|November|December)';

	function getQueryParamFromUrl(url, name) {
		try {
			const u = new URL(url, window.location.origin);
			return u.searchParams.get(name) || '';
		} catch (e) {
			return '';
		}
	}

	function decodePathFromCurrentUrl() {
		const raw = getQueryParamFromUrl(window.location.href, 'path');
		try {
			return decodeURIComponent(raw || '');
		} catch (e) {
			return raw || '';
		}
	}

	function isDateLinkText(text) {
		const t = (text || '').trim();
		const re = new RegExp(`^${MONTHS}\\s+\\d{1,2},\\s+\\d{4}$`, 'i');
		return re.test(t);
	}

	function extractPayoutIdFromHref(href) {
		// Your URL format:
		// admin.php?page=wc-admin&path=%2Fpayments%2Fpayouts%2Fdetails&id=po_...
		const qid = getQueryParamFromUrl(href, 'id');
		if (qid) return qid;

		// Fallback: legacy path-based format /payments/payouts/details/<id>
		try {
			const u = new URL(href, window.location.origin);
			const p = u.searchParams.get('path') || '';
			const decoded = decodeURIComponent(p);
			const m = decoded.match(/\/payments\/(?:payouts|deposits)\/(?:details\/)?([^/?#]+)/i);
			return m ? m[1] : '';
		} catch (e) {
			return '';
		}
	}

	async function fetchFile(endpoint, opts) {
		const res = await fetch(endpoint, {
			...opts,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
				...(opts && opts.headers ? opts.headers : {}),
			},
			credentials: 'same-origin',
		});
		const json = await res.json();
		if (!res.ok) throw new Error(json && json.error ? json.error : 'Request failed');
		return json;
	}

	function downloadBase64(filename, base64, mime) {
		const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
		const blob = new Blob([bytes], { type: mime || 'application/octet-stream' });
		const url = URL.createObjectURL(blob);

		const a = document.createElement('a');
		a.href = url;
		a.download = filename || 'download';
		document.body.appendChild(a);
		a.click();
		a.remove();

		setTimeout(() => URL.revokeObjectURL(url), 5000);
	}

	async function deliverPayout(payoutId) {
		const data = await fetchFile(`${REST}/deposit/${encodeURIComponent(payoutId)}/deliver?force=1`, { method: 'POST' });
		return data;
	}

	async function downloadPayout(payoutId) {
		const data = await fetchFile(`${REST}/deposit/${encodeURIComponent(payoutId)}`, { method: 'GET' });
		downloadBase64(data.filename, data.base64, data.mime);
	}

	async function downloadBulk(payoutIds) {
		const data = await fetchFile(`${REST}/bulk`, {
			method: 'POST',
			body: JSON.stringify({ deposit_ids: payoutIds }),
		});
		downloadBase64(data.filename, data.base64, data.mime);
	}

	function ensureToolbar() {
		if (document.querySelector('.wcpay-pi-toolbar')) return;

		const box = document.createElement('div');
		box.className = 'wcpay-pi-toolbar';
		box.innerHTML = `
			<div class="wcpay-pi-toolbar-row">
				<strong>Payout PDFs</strong>
				<button type="button" id="wcpay-pi-download-selected" disabled>Download selected (ZIP)</button>
				<span id="wcpay-pi-selected-count" class="muted">0 selected</span>
			</div>
		`;
		document.body.appendChild(box);

		const btn = box.querySelector('#wcpay-pi-download-selected');
		const label = box.querySelector('#wcpay-pi-selected-count');

		function getSelectedIds() {
			return Array.from(document.querySelectorAll('input.wcpay-pi-checkbox:checked'))
				.map((el) => el.getAttribute('data-payout-id') || '')
				.filter(Boolean);
		}

		function refresh() {
			const ids = getSelectedIds();
			btn.disabled = ids.length === 0;
			label.textContent = `${ids.length} selected`;
		}

		document.addEventListener('change', (e) => {
			const t = e.target;
			if (t && t.classList && t.classList.contains('wcpay-pi-checkbox')) refresh();
		});

		btn.addEventListener('click', async () => {
			const ids = getSelectedIds();
			if (!ids.length) return;

			btn.disabled = true;
			const old = btn.textContent;
			btn.textContent = 'Generating...';
			try {
				await downloadBulk(ids);
			} catch (err) {
				alert(err.message || String(err));
			} finally {
				btn.textContent = old;
				refresh();
			}
		});
	}

	function findRowContainer(el) {
		return (
			el.closest('tr') ||
			el.closest('[role="row"]') ||
			el.closest('.woocommerce-table__row') ||
			el.closest('li') ||
			el.closest('div')
		);
	}

	function injectListButtons() {
		ensureToolbar();

		// Only target the "Date" link text. Many other cells use the same href.
		const links = Array.from(
			document.querySelectorAll('a[href*="page=wc-admin"][href*="payments%2Fpayouts%2Fdetails"], a[href*="page=wc-admin"][href*="payments%2Fdeposits%2Fdetails"]')
		).filter((a) => isDateLinkText(a.textContent || ''));

		for (const link of links) {
			const href = link.getAttribute('href') || '';
			const payoutId = extractPayoutIdFromHref(href);
			if (!payoutId) continue;

			const row = findRowContainer(link);
			if (!row) continue;

			// Only one injection per row.
			if (row.getAttribute('data-wcpay-pi-row') === '1') continue;
			row.setAttribute('data-wcpay-pi-row', '1');

			// Remove any old/duplicate injected elements from previous versions.
			row.querySelectorAll('.wcpay-pi-inline-wrap').forEach((n) => n.remove());

			const cell =
				link.closest('td') ||
				link.closest('[role="gridcell"]') ||
				link.parentElement;

			if (!cell) continue;

			const wrap = document.createElement('span');
			wrap.className = 'wcpay-pi-inline-wrap';

			const cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.className = 'wcpay-pi-checkbox';
			cb.setAttribute('data-payout-id', payoutId);

			const btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'wcpay-pi-inline-btn';
			btn.textContent = 'Download PDF';
			btn.addEventListener('click', async () => {
				btn.disabled = true;
				const old = btn.textContent;
				btn.textContent = 'Generating...';
				try {
					await downloadPayout(payoutId);
				} catch (err) {
					alert(err.message || String(err));
				} finally {
					btn.textContent = old;
					btn.disabled = false;
				}
			});

			wrap.appendChild(cb);
			wrap.appendChild(btn);

			const sendBtn = document.createElement('button');
			sendBtn.type = 'button';
			sendBtn.className = 'wcpay-pi-inline-btn';
			sendBtn.textContent = 'Send to webhook';
			sendBtn.addEventListener('click', async () => {
				sendBtn.disabled = true;
				const old = sendBtn.textContent;
				sendBtn.textContent = 'Sending...';
				try {
					await deliverPayout(payoutId);
					alert('Delivered (email + webhook).');
				} catch (err) {
					alert(err.message || String(err));
				} finally {
					sendBtn.textContent = old;
					sendBtn.disabled = false;
				}
			});

			wrap.appendChild(sendBtn);

			// Insert before the date link, so it stays in Date column only.
			link.insertAdjacentElement('beforebegin', wrap);
		}
	}

	function injectDetailsButton() {
		const path = decodePathFromCurrentUrl();
		if (!path.startsWith('/payments/payouts/details') && !path.startsWith('/payments/deposits/details')) return;

		const payoutId = getQueryParamFromUrl(window.location.href, 'id');
		if (!payoutId) return;

		const maybeExport = Array.from(document.querySelectorAll('button, a')).find(
			(el) => (el.textContent || '').trim() === 'Export'
		);
		const container = maybeExport ? maybeExport.parentElement : null;
		if (!container) return;

		if (container.querySelector('[data-wcpay-pi-details-btn="1"]')) return;

		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'wcpay-pi-inline-btn';
		btn.setAttribute('data-wcpay-pi-details-btn', '1');
		btn.textContent = 'Download payout PDF';
		btn.addEventListener('click', async () => {
			btn.disabled = true;
			const old = btn.textContent;
			btn.textContent = 'Generating...';
			try {
				await downloadPayout(payoutId);
			} catch (err) {
				alert(err.message || String(err));
			} finally {
				btn.textContent = old;
				btn.disabled = false;
			}
		});

		container.appendChild(btn);

		const sendBtn = document.createElement('button');
		sendBtn.type = 'button';
		sendBtn.className = 'wcpay-pi-inline-btn';
		sendBtn.textContent = 'Send payout to webhook';
		sendBtn.addEventListener('click', async () => {
			sendBtn.disabled = true;
			const old = sendBtn.textContent;
			sendBtn.textContent = 'Sending...';
			try {
				await deliverPayout(payoutId);
				alert('Delivered (email + webhook).');
			} catch (err) {
				alert(err.message || String(err));
			} finally {
				sendBtn.textContent = old;
				sendBtn.disabled = false;
			}
		});
		container.appendChild(sendBtn);
	}

	function boot() {
		const path = decodePathFromCurrentUrl();
		if (!path.startsWith('/payments/payouts') && !path.startsWith('/payments/deposits')) return;

		const run = () => {
			injectListButtons();
			injectDetailsButton();
		};

		const obs = new MutationObserver(() => run());
		obs.observe(document.body, { childList: true, subtree: true });

		run();
	}

	boot();
})();
