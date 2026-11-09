/* WooCommerce Order Sync & Recovery - admin dashboard.
 * Talks to the plugin's REST API through wp.apiFetch (cookie + X-WP-Nonce handled by core).
 * All dynamic text is written with textContent, never innerHTML, so ERP-provided error
 * strings cannot inject markup.
 */
(function () {
	'use strict';

	var cfg = window.WCOSR || {};
	var api = window.wp && window.wp.apiFetch;
	if (!api) { return; }
	var t = cfg.i18n || {};
	var base = '/' + cfg.namespace;

	function $(id) { return document.getElementById(id); }

	function el(tag, props, children) {
		var node = document.createElement(tag);
		Object.keys(props || {}).forEach(function (k) {
			if (k === 'text') { node.textContent = props[k]; }
			else if (k === 'class') { node.className = props[k]; }
			else { node.setAttribute(k, props[k]); }
		});
		(children || []).forEach(function (c) { node.appendChild(c); });
		return node;
	}

	function fmt(ts) {
		return ts ? new Date(ts * 1000).toLocaleString() : '';
	}

	function rel(ts, now) {
		if (!ts) { return t.never || 'never'; }
		var d = Math.round((ts - now));
		var a = Math.abs(d);
		var s = a < 90 ? a + 's' : a < 5400 ? Math.round(a / 60) + 'm' : a < 172800 ? Math.round(a / 3600) + 'h' : Math.round(a / 86400) + 'd';
		return d >= 0 ? 'in ' + s : s + ' ago';
	}

	function say(node, text, ok) {
		if (!node) { return; }
		node.textContent = text || '';
		node.className = ok === false ? 'wcosr-bad' : 'wcosr-good';
	}

	function errText(e) { return (e && e.message) || t.error || 'Request failed'; }

	// ---- settings tab: test connection -------------------------------------
	var testBtn = $('wcosr-test');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var out = $('wcosr-test-result');
			say(out, t.testing, true);
			api({ path: base + '/test-connection', method: 'POST' })
				.then(function (r) { say(out, r.message, r.ok); })
				.catch(function (e) { say(out, errText(e), false); });
		});
	}

	// ---- dashboard tab -----------------------------------------------------
	var app = $('wcosr-app');
	if (!app) { return; }

	var state = { page: 1, perPage: 20, total: 0, pages: 1, status: null };
	var timer = null;

	function card(label, value, cls) {
		return el('div', { 'class': 'wcosr-card ' + (cls || '') }, [
			el('div', { 'class': 'wcosr-card-n', text: String(value) }),
			el('div', { 'class': 'wcosr-card-l', text: label })
		]);
	}

	function renderStatus(s) {
		state.status = s;
		var c = s.counts || {};
		var cards = $('wcosr-cards');
		cards.textContent = '';
		cards.appendChild(card('Pending', c.pending || 0));
		cards.appendChild(card('Processing', c.processing || 0));
		cards.appendChild(card('Retrying', c.retrying || 0, (c.retrying || 0) > 0 ? 'warn' : ''));
		cards.appendChild(card('Failed', c.failed || 0, (c.failed || 0) > 0 ? 'bad' : ''));
		cards.appendChild(card('Succeeded', c.succeeded || 0, 'good'));
		cards.appendChild(card('Last success', rel(s.last_success_at, s.now)));

		var banner = $('wcosr-banner');
		banner.textContent = '';
		var msgs = [];
		if (!s.configured) { msgs.push('ERP URL / shared secret not configured: orders are queued but cannot be delivered. Open the Settings tab.'); }
		else if (!s.enabled) { msgs.push('Sync is disabled: status changes are not being queued (existing jobs still run).'); }
		if (s.oldest_waiting_since && s.now - s.oldest_waiting_since > 900) {
			msgs.push('The oldest waiting job is ' + rel(s.oldest_waiting_since, s.now).replace(' ago', '') + ' overdue. Check WooCommerce > Status > Scheduled Actions (group "wcosr") and that WP-Cron/Action Scheduler is running.');
		}
		msgs.forEach(function (m) { banner.appendChild(el('div', { 'class': 'notice notice-warning inline' }, [el('p', { text: m })])); });

		var btn = $('wcosr-retry-all');
		btn.textContent = (t.retryAll || 'Retry all failed') + ' (' + (c.failed || 0) + ')';
		btn.disabled = !(c.failed > 0);
	}

	function statusCell(job) {
		var label = job.status + (job.failure_type ? ' (' + job.failure_type + ')' : '');
		return el('span', { 'class': 'wcosr-pill wcosr-' + job.status, text: label });
	}

	function renderJobs(jobs) {
		var body = app.querySelector('tbody');
		body.textContent = '';
		if (!jobs.length) {
			body.appendChild(el('tr', {}, [el('td', { colspan: '8', text: t.empty })]));
			return;
		}
		var now = state.status ? state.status.now : Math.floor(Date.now() / 1000);

		jobs.forEach(function (j) {
			var when = j.status === 'succeeded' || j.status === 'failed' ? fmt(j.completed_at) : (j.next_attempt_at ? rel(j.next_attempt_at, now) : '');
			var err = j.last_error ? (j.last_http_status ? '[' + j.last_http_status + '] ' : '') + j.last_error : '';
			var action = el('td');
			if (j.status === 'failed') {
				var b = el('button', { type: 'button', 'class': 'button button-small', text: t.retry || 'Retry' });
				b.addEventListener('click', function () {
					b.disabled = true;
					api({ path: base + '/jobs/' + j.id + '/retry', method: 'POST' })
						.then(function () { say($('wcosr-msg'), 'Job #' + j.id + ' re-queued', true); load(); })
						.catch(function (e) { say($('wcosr-msg'), errText(e), false); b.disabled = false; });
				});
				action.appendChild(b);
			}
			var orderLink = j.order_url
				? el('a', { href: j.order_url, text: '#' + j.order_id })
				: el('span', { text: '#' + j.order_id });

			body.appendChild(el('tr', {}, [
				el('td', { text: String(j.id) }),
				el('td', {}, [orderLink]),
				el('td', {}, [statusCell(j)]),
				el('td', { text: j.attempts + ' / ' + j.max_attempts }),
				el('td', { text: j.erp_id || '' }),
				el('td', { text: when }),
				el('td', { 'class': 'wcosr-err', text: err, title: err }),
				action
			]));
		});
	}

	function load() {
		var q = '?page=' + state.page + '&per_page=' + state.perPage;
		var st = $('wcosr-filter').value;
		var oid = parseInt($('wcosr-order').value, 10);
		if (st) { q += '&status=' + encodeURIComponent(st); }
		if (oid > 0) { q += '&order_id=' + oid; }

		return Promise.all([
			api({ path: base + '/status' }),
			api({ path: base + '/jobs' + q, parse: false })
		]).then(function (res) {
			renderStatus(res[0]);
			state.total = parseInt(res[1].headers.get('X-WP-Total') || '0', 10);
			state.pages = parseInt(res[1].headers.get('X-WP-TotalPages') || '1', 10);
			return res[1].json();
		}).then(function (jobs) {
			renderJobs(jobs);
			$('wcosr-page').textContent = state.page + ' / ' + state.pages + ' (' + state.total + ')';
			$('wcosr-prev').disabled = state.page <= 1;
			$('wcosr-next').disabled = state.page >= state.pages;
		}).catch(function (e) { say($('wcosr-msg'), errText(e), false); });
	}

	$('wcosr-prev').textContent = t.prev || 'Previous';
	$('wcosr-next').textContent = t.next || 'Next';
	$('wcosr-prev').addEventListener('click', function () { if (state.page > 1) { state.page--; load(); } });
	$('wcosr-next').addEventListener('click', function () { if (state.page < state.pages) { state.page++; load(); } });
	$('wcosr-refresh').addEventListener('click', function () { state.page = 1; load(); });
	$('wcosr-filter').addEventListener('change', function () { state.page = 1; load(); });
	$('wcosr-order').addEventListener('change', function () { state.page = 1; load(); });

	$('wcosr-retry-all').addEventListener('click', function () {
		if (!window.confirm(t.confirmAll || 'Retry all failed jobs?')) { return; }
		api({ path: base + '/jobs/retry-failed', method: 'POST' })
			.then(function (r) { say($('wcosr-msg'), r.retried + ' job(s) re-queued', true); load(); })
			.catch(function (e) { say($('wcosr-msg'), errText(e), false); });
	});

	function schedule() {
		clearInterval(timer);
		timer = setInterval(function () { if (!document.hidden) { load(); } }, 10000);
	}

	load();
	schedule();
})();
