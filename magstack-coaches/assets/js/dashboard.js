/* Magstack Coaches – coach dashboard (vanilla JS over REST). */
(function () {
	'use strict';
	var cfg = window.magstackDash || {};
	var T = cfg.i18n || {};
	var root = document.getElementById('mc-dash-app');
	if (!root) { return; }
	var state = { tab: 'bookings', filter: 'pending', me: null, bookings: [] };

	function h(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { n.className = v; }
			else if (k === 'text') { n.textContent = v; }
			else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); }
			else { n.setAttribute(k, v === true ? '' : v); }
		});
		(kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}
	function money(v) { return (cfg.currency || '₱') + Number(v).toLocaleString(); }

	function api(path, method, body) {
		return fetch(cfg.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error(j && j.message ? j.message : T.error); }
				return j;
			});
		});
	}
	function toast(msg, bad) {
		var t = h('div', { class: 'mc-toast' + (bad ? ' is-err' : ''), role: 'status', text: msg });
		document.body.appendChild(t);
		setTimeout(function () { t.remove(); }, 3000);
	}
	function fail(e) { toast(e && e.message ? e.message : T.error, true); }

	// ---- Tabs ------------------------------------------------------------------------------
	function render() {
		root.textContent = '';
		var tabs = [['bookings', T.bookings], ['availability', T.availability], ['packages', T.packages], ['profile', T.profile]];
		var nav = h('div', { class: 'mc-tabs', role: 'tablist' });
		tabs.forEach(function (t) {
			nav.appendChild(h('button', {
				type: 'button', role: 'tab', class: 'mc-tab' + (state.tab === t[0] ? ' is-active' : ''),
				'aria-selected': state.tab === t[0] ? 'true' : 'false',
				onclick: function () { state.tab = t[0]; if (t[0] === 'bookings') { loadBookings(); } else { render(); } }
			}, [t[1]]));
		});
		root.appendChild(nav);
		var panel = h('div', { class: 'mc-panel', role: 'tabpanel' });
		root.appendChild(panel);
		({ bookings: bookingsView, availability: availabilityView, packages: packagesView, profile: profileView })[state.tab](panel);
	}

	// ---- Bookings --------------------------------------------------------------------------
	function loadBookings() {
		api('coach/bookings').then(function (rows) { state.bookings = rows; render(); }).catch(fail);
	}
	function act(b, action, confirmIt) {
		if (confirmIt && !window.confirm(T.confirmAct)) { return; }
		api('coach/bookings/' + b.id, 'POST', { action: action }).then(function (nb) {
			state.bookings = state.bookings.map(function (x) { return x.id === nb.id ? nb : x; });
			render();
		}).catch(fail);
	}
	function bookingsView(panel) {
		var bar = h('div', { class: 'mc-chips' });
		[['pending', T.pending], ['confirmed', T.confirmed], ['all', T.all]].forEach(function (f) {
			bar.appendChild(h('button', { type: 'button', class: 'mc-chip mc-chip--btn' + (state.filter === f[0] ? ' is-on' : ''), onclick: function () { state.filter = f[0]; render(); } }, [f[1]]));
		});
		panel.appendChild(bar);
		var rows = state.bookings.filter(function (b) { return state.filter === 'all' || b.status === state.filter; });
		if (!rows.length) { panel.appendChild(h('p', { class: 'mc-muted', text: T.noBookings })); return; }
		rows.forEach(function (b) {
			var c = h('article', { class: 'mc-bk mc-bk--' + b.status });
			c.appendChild(h('div', { class: 'mc-bk__top' }, [
				h('strong', { text: b.date_label + ' · ' + b.time_label }),
				h('span', { class: 'mc-status mc-status--' + b.status, text: b.status_label })
			]));
			c.appendChild(h('div', { text: b.player + ' · ' + b.mobile }));
			c.appendChild(h('div', { class: 'mc-muted', text: b.email + (b.level ? ' · ' + b.level : '') }));
			c.appendChild(h('div', { text: b.court }));
			c.appendChild(h('div', { class: 'mc-muted', text: 'Coach fee ' + money(b.coach_fee) + ' · Court fee ' + (b.court_fee > 0 ? money(b.court_fee) : (b.court_fee_note || '—')) + ' · ' + b.payment_status }));
			if (b.package) { c.appendChild(h('div', { class: 'mc-muted', text: 'Package ' + b.package.code + ': ' + b.package.remaining + ' ' + T.sessionsLeft })); }
			if (b.notes) { c.appendChild(h('div', { class: 'mc-muted', text: '“' + b.notes + '”' })); }
			var actions = h('div', { class: 'mc-bk__actions' });
			if (b.status === 'pending') {
				actions.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm', onclick: function () { act(b, 'confirm'); } }, [T.accept]));
				actions.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () { act(b, 'decline', true); } }, [T.decline]));
			}
			if (b.status === 'confirmed') {
				actions.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm', onclick: function () { act(b, 'complete'); } }, [T.complete]));
				actions.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () { act(b, 'cancel', true); } }, [T.cancel]));
			}
			if (b.status !== 'declined' && b.status !== 'cancelled') {
				actions.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () { act(b, b.payment_status === 'paid' ? 'unpaid' : 'paid'); } }, [b.payment_status === 'paid' ? T.markUnpaid : T.markPaid]));
			}
			c.appendChild(actions);
			panel.appendChild(c);
		});
	}

	// ---- Availability ----------------------------------------------------------------------
	function hasHours(av) {
		var w = av && av.weekly;
		return !!w && !Array.isArray(w) && Object.keys(w).some(function (d) { return (w[d] || []).length; });
	}
	function timeInput(val, label, cb) {
		return h('input', { type: 'time', value: val, 'aria-label': label, step: 900, onchange: function (e) { cb(e.target.value); } });
	}
	function rangeRow(list, i, dayLabel) {
		var r = list[i];
		return h('div', { class: 'mc-range' }, [
			timeInput(r[0], dayLabel + ' start', function (v) { r[0] = v; }),
			h('span', { text: '–' }),
			timeInput(r[1], dayLabel + ' end', function (v) { r[1] = v; }),
			h('button', { type: 'button', class: 'mc-x', 'aria-label': T.remove, onclick: function () { list.splice(i, 1); render(); } }, ['×'])
		]);
	}
	function availabilityView(panel) {
		var av = state.me.availability;
		// PHP encodes empty maps as [] – normalise to plain objects so keys survive JSON.stringify.
		if (!av.weekly || Array.isArray(av.weekly)) { av.weekly = {}; }
		if (!av.overrides || Array.isArray(av.overrides)) { av.overrides = {}; }
		panel.appendChild(h('h3', { text: T.weekly }));
		if (!hasHours(av)) { panel.appendChild(h('p', { class: 'mc-notice', role: 'status', text: T.setSchedule })); }
		function fill(days, s, e) { Object.keys(cfg.days).forEach(function (d) { av.weekly[d] = days.indexOf(d) !== -1 ? [[s, e]] : []; }); render(); }
		var presets = h('div', { class: 'mc-chips' });
		[[T.presetWeekdays, function () { fill(['mon', 'tue', 'wed', 'thu', 'fri'], '09:00', '17:00'); }],
		 [T.presetEvery, function () { fill(Object.keys(cfg.days), '09:00', '17:00'); }],
		 [T.presetWeekend, function () { fill(['sat', 'sun'], '08:00', '12:00'); }],
		 [T.presetCopy, function () { var m = JSON.parse(JSON.stringify(av.weekly.mon || [])); Object.keys(cfg.days).forEach(function (d) { av.weekly[d] = JSON.parse(JSON.stringify(m)); }); render(); }],
		 [T.presetClear, function () { fill([], '', ''); }]].forEach(function (x) {
			presets.appendChild(h('button', { type: 'button', class: 'mc-chip mc-chip--btn', onclick: x[1] }, [x[0]]));
		});
		panel.appendChild(presets);
		Object.keys(cfg.days).forEach(function (d) {
			var list = av.weekly[d] = av.weekly[d] || [];
			var row = h('div', { class: 'mc-day' }, [h('strong', { class: 'mc-day__name', text: cfg.days[d] })]);
			var box = h('div', { class: 'mc-day__ranges' });
			if (!list.length) { box.appendChild(h('span', { class: 'mc-muted', text: T.closed })); }
			list.forEach(function (_, i) { box.appendChild(rangeRow(list, i, cfg.days[d])); });
			box.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () { list.push(['09:00', '12:00']); render(); } }, ['+ ' + T.addRange]));
			row.appendChild(box);
			panel.appendChild(row);
		});

		panel.appendChild(h('h3', { text: T.overrides }));
		var today = new Date().toISOString().slice(0, 10);
		var dateIn = h('input', { type: 'date', min: today, 'aria-label': T.overrides });
		panel.appendChild(h('div', { class: 'mc-range' }, [dateIn, h('button', { type: 'button', class: 'mc-btn mc-btn--sm', onclick: function () {
			if (!dateIn.value) { return; }
			av.overrides[dateIn.value] = { closed: true };
			render();
		} }, [T.addOverride])]));
		Object.keys(av.overrides).sort().forEach(function (date) {
			var o = av.overrides[date];
			var row = h('div', { class: 'mc-day' }, [h('strong', { class: 'mc-day__name', text: date })]);
			var box = h('div', { class: 'mc-day__ranges' });
			box.appendChild(h('label', { class: 'mc-check' }, [
				h('input', { type: 'checkbox', checked: o.closed ? true : null, onchange: function (e) {
					if (e.target.checked) { av.overrides[date] = { closed: true }; } else { av.overrides[date] = { ranges: [['09:00', '12:00']] }; }
					render();
				} }), ' ' + T.closedDay
			]));
			if (!o.closed) {
				o.ranges = o.ranges || [];
				o.ranges.forEach(function (_, i) { box.appendChild(rangeRow(o.ranges, i, date)); });
				box.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () { o.ranges.push(['09:00', '12:00']); render(); } }, ['+ ' + T.addRange]));
			}
			box.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () { delete av.overrides[date]; render(); } }, [T.remove]));
			row.appendChild(box);
			panel.appendChild(row);
		});
		panel.appendChild(h('p', {}, [h('button', { type: 'button', class: 'mc-btn', onclick: function () {
			api('coach/availability', 'POST', av).then(function (res) { state.me.availability = res.availability; toast(T.saved); render(); }).catch(fail);
		} }, [T.save])]));
	}

	// ---- Packages --------------------------------------------------------------------------
	function packagesView(panel) {
		var pk = state.me.packages;
		function form(p) {
			var f = h('form', { class: 'mc-form mc-pkgform', onsubmit: function (e) {
				e.preventDefault();
				var d = { id: p.id || 0, name: f.name.value, sessions: +f.sessions.value, price: +f.price.value, validity_days: +f.validity.value || 0 };
				api('coach/packages', 'POST', d).then(function (res) { state.me.packages = res.packages; toast(T.saved); render(); }).catch(fail);
			} });
			[['name', 'Name', 'text', p.name || ''], ['sessions', 'Sessions', 'number', p.sessions || 5], ['price', 'Total price', 'number', p.price || ''], ['validity', 'Valid for (days, 0 = no expiry)', 'number', p.validity_days || 0]].forEach(function (x) {
				f.appendChild(h('div', { class: 'mc-field' }, [h('label', { for: 'pk-' + (p.id || 0) + x[0], text: x[1] }), h('input', { id: 'pk-' + (p.id || 0) + x[0], name: x[0], type: x[2], value: x[3], min: x[2] === 'number' ? 0 : null })]));
			});
			var acts = h('div', { class: 'mc-bk__actions' }, [h('button', { type: 'submit', class: 'mc-btn mc-btn--sm' }, [T.save])]);
			if (p.id) { acts.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () {
				if (!window.confirm(T.confirmAct)) { return; }
				api('coach/packages/' + p.id, 'DELETE').then(function (res) { state.me.packages = res.packages; render(); }).catch(fail);
			} }, [T.remove])); }
			f.appendChild(acts);
			return f;
		}
		pk.forEach(function (p) { var w = h('div', { class: 'mc-bk' }); w.appendChild(form(p)); panel.appendChild(w); });
		panel.appendChild(h('h3', { text: '+ ' + T.packages }));
		var nw = h('div', { class: 'mc-bk' }); nw.appendChild(form({})); panel.appendChild(nw);
		if (state.me.purchases.length) {
			panel.appendChild(h('h3', { text: 'Purchased packages' }));
			state.me.purchases.forEach(function (p) {
				panel.appendChild(h('div', { class: 'mc-bk' }, [h('strong', { text: p.code + ' · ' + p.player }), h('div', { class: 'mc-muted', text: p.email + ' · ' + p.remaining + '/' + p.total + ' ' + T.sessionsLeft + (p.paid ? ' · paid' : ' · unpaid') })]));
			});
		}
	}

	// ---- Profile & booking link / QR -------------------------------------------------------
	function drawQr(canvas, text) {
		if (typeof qrcode === 'undefined') { return; }
		var qr = qrcode(0, 'M'); qr.addData(text); qr.make();
		var n = qr.getModuleCount(), cell = 8, margin = 4, size = (n + margin * 2) * cell;
		canvas.width = size; canvas.height = size;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
		ctx.fillStyle = '#000';
		for (var r = 0; r < n; r++) { for (var c = 0; c < n; c++) { if (qr.isDark(r, c)) { ctx.fillRect((c + margin) * cell, (r + margin) * cell, cell, cell); } } }
	}
	function profileView(panel) {
		var me = state.me;
		var link = h('input', { type: 'text', readonly: true, value: me.booking_link, 'aria-label': 'Booking link', onfocus: function (e) { e.target.select(); } });
		panel.appendChild(h('div', { class: 'mc-field' }, [h('label', { text: 'Your shareable booking link' }), link]));
		var copy = h('button', { type: 'button', class: 'mc-btn mc-btn--sm', onclick: function () {
			(navigator.clipboard ? navigator.clipboard.writeText(me.booking_link) : Promise.reject()).then(function () { toast(T.copied); }, function () { link.select(); document.execCommand('copy'); toast(T.copied); });
		} }, [T.copy]);
		var canvas = h('canvas', { class: 'mc-qr', role: 'img', 'aria-label': 'QR code for your booking link' });
		drawQr(canvas, me.booking_link);
		var dl = h('button', { type: 'button', class: 'mc-btn mc-btn--sm', onclick: function () {
			canvas.toBlob(function (blob) {
				var a = h('a', { href: URL.createObjectURL(blob), download: 'booking-qr-' + me.id + '.png' });
				document.body.appendChild(a); a.click(); a.remove();
			}, 'image/png');
		} }, [T.downloadQr]);
		panel.appendChild(h('p', {}, [copy, ' ', h('a', { class: 'mc-btn mc-btn--sm mc-btn--ghost', href: me.profile_url }, ['View public profile'])]));
		panel.appendChild(h('div', {}, [canvas]));
		panel.appendChild(h('p', {}, [dl]));

		var p = me.profile;
		var f = h('form', { class: 'mc-form', onsubmit: function (e) {
			e.preventDefault();
			api('coach/profile', 'POST', { bio: f.bio.value, rate: +f.rate.value, durations: f.durations.value, city: f.city.value, venues: f.venues.value })
				.then(function (m) { state.me = m; toast(T.saved); render(); }).catch(fail);
		} });
		f.appendChild(h('h3', { text: T.profile }));
		function field(name, label, el) { return h('div', { class: 'mc-field' }, [h('label', { for: 'pf-' + name, text: label }), el]); }
		f.appendChild(field('bio', 'Bio', h('textarea', { id: 'pf-bio', name: 'bio', rows: 4, maxlength: 1200, text: p.bio })));
		f.appendChild(field('rate', 'Rate per hour', h('input', { id: 'pf-rate', name: 'rate', type: 'number', min: 1, value: p.rate })));
		f.appendChild(field('durations', 'Session durations (minutes, comma separated)', h('input', { id: 'pf-durations', name: 'durations', type: 'text', value: p.durations.join(', ') })));
		f.appendChild(field('city', 'Service area (city)', h('input', { id: 'pf-city', name: 'city', type: 'text', value: p.city })));
		f.appendChild(field('venues', 'Other venues (one per line: Name | Address)', h('textarea', { id: 'pf-venues', name: 'venues', rows: 3, text: p.venues })));
		f.appendChild(h('button', { type: 'submit', class: 'mc-btn' }, [T.save]));
		panel.appendChild(f);
	}

	setTimeout(function () {
		if (root.textContent.indexOf('Loading') !== -1 && !state.me) { root.textContent = ''; root.appendChild(h('p', { class: 'mc-notice mc-notice--err', text: T.error + ' (' + (cfg.root || '') + ')' })); }
	}, 8000);
	Promise.all([api('coach/me'), api('coach/bookings')]).then(function (res) {
		state.me = res[0]; state.bookings = res[1];
		if (!state.bookings.some(function (b) { return b.status === 'pending'; })) { state.filter = 'confirmed'; }
		if (!hasHours(state.me.availability)) { state.tab = 'availability'; } // first-run: ask for the weekly schedule
		render();
	}).catch(function (e) { root.textContent = ''; root.appendChild(h('p', { class: 'mc-notice mc-notice--err', text: e.message || T.error })); });
})();
