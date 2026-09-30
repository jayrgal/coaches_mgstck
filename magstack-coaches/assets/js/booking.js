/* Magstack Coaches – booking wizard (vanilla JS, no page reloads). */
(function () {
	'use strict';
	var cfg = window.magstackBooking || {};
	var T = cfg.i18n || {};
	var root = document.getElementById('mc-booking-app');
	if (!root) { return; }

	var LS = 'magstackPlayer';
	var S = {
		step: 1, coaches: null, coach: null, duration: 0,
		dates: null, date: cfg.date || '', slots: null, time: '',
		mode: 'coach_picks', facilityId: cfg.facility || 0, venueIndex: -1, showMap: false,
		pkg: 0, code: '', codeInfo: null,
		player: { name: '', email: '', mobile: '', level: '', notes: '' },
		busy: false, error: ''
	};

	// ---- helpers ---------------------------------------------------------------------------
	function h(tag, attrs, kids) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { n.className = v; }
			else if (k === 'text') { n.textContent = v; }
			else if (k === 'html') { n.innerHTML = v; }
			else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); }
			else { n.setAttribute(k, v === true ? '' : v); }
		});
		(kids || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}
	function money(v) { return (cfg.currency || '₱') + Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
	function api(path, method, body) {
		return fetch(cfg.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { var e = new Error(j && j.message ? j.message : T.error); e.status = r.status; e.code = j && j.code; throw e; }
				return j;
			});
		});
	}
	function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function parseYmd(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
	function scrollTop() { var b = document.getElementById('mc-booking'); if (b && b.scrollIntoView) { b.scrollIntoView({ block: 'start', behavior: 'smooth' }); } }
	function go(step) { S.step = step; S.error = ''; render(); scrollTop(); }

	function loadSaved() {
		try {
			var s = JSON.parse(localStorage.getItem(LS) || 'null');
			if (s) { ['name', 'email', 'mobile', 'level'].forEach(function (k) { if (s[k]) { S.player[k] = s[k]; } }); }
		} catch (e) { /* storage unavailable */ }
		if (cfg.user) { S.player.name = S.player.name || cfg.user.name; S.player.email = S.player.email || cfg.user.email; }
	}
	function save() {
		try { localStorage.setItem(LS, JSON.stringify({ name: S.player.name, email: S.player.email, mobile: S.player.mobile, level: S.player.level })); } catch (e) { /* ignore */ }
	}

	// ---- Chrome ----------------------------------------------------------------------------
	function frame(body, footer) {
		root.textContent = '';
		var names = T.stepNames || [];
		var nav = h('ol', { class: 'mc-steps', 'aria-label': 'Progress' });
		names.forEach(function (n, i) {
			nav.appendChild(h('li', { class: 'mc-steps__i' + (i + 1 === S.step ? ' is-cur' : '') + (i + 1 < S.step ? ' is-done' : ''), 'aria-current': i + 1 === S.step ? 'step' : null }, [h('span', { class: 'mc-steps__n', text: String(i + 1) }), h('span', { class: 'mc-steps__t', text: n })]));
		});
		root.appendChild(nav);
		if (S.error) { root.appendChild(h('div', { class: 'mc-notice mc-notice--err', role: 'alert', text: S.error })); }
		root.appendChild(body);
		if (footer) { root.appendChild(footer); }
	}
	function footer(nextLabel, nextEnabled, onNext, canBack, inline) {
		var f = h('div', { class: 'mc-foot' + (inline ? ' mc-foot--inline' : '') });
		if (canBack !== false) { f.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--ghost', onclick: function () { back(); } }, [T.back])); }
		if (onNext) { f.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--lg', disabled: nextEnabled ? null : true, onclick: onNext }, [nextLabel])); }
		return f;
	}
	function firstStep() { return cfg.coach ? 2 : 1; }
	function back() { if (S.step > firstStep()) { go(S.step - 1); } else if (S.step === 1) { return; } else { window.location.href = cfg.directory; } }

	function render() {
		if (S.step === 1) { return viewCoaches(); }
		if (S.step === 2) { return viewDateTime(); }
		if (S.step === 3) { return viewCourt(); }
		return viewDetails();
	}

	// ---- Step 1: coach ---------------------------------------------------------------------
	function chooseCoach(id) {
		S.busy = true; S.step = 1; render();
		api('coaches/' + id).then(function (c) {
			S.coach = c; S.duration = c.durations[0]; S.dates = null; S.date = S.date; S.time = ''; S.slots = null; S.busy = false;
			go(2);
		}).catch(function (e) { S.busy = false; S.error = e.message; if (S.step === 1) { render(); } else { go(1); } });
	}
	function viewCoaches() {
		var body = h('div', { class: 'mc-step' }, [h('h2', { text: T.chooseCoach })]);
		if (S.busy) { body.appendChild(h('p', { text: T.loading })); return frame(body); }
		if (!S.coaches) {
			body.appendChild(h('p', { text: T.loading }));
			frame(body);
			api('coaches').then(function (r) { S.coaches = r; render(); }).catch(function (e) { S.error = e.message; S.coaches = []; render(); });
			return;
		}
		var q = h('input', { type: 'search', class: 'mc-search', placeholder: T.searchCoach, 'aria-label': T.searchCoach });
		var list = h('div', { class: 'mc-list' });
		function draw() {
			list.textContent = '';
			var term = q.value.trim().toLowerCase();
			var rows = S.coaches.filter(function (c) { return !term || (c.name + ' ' + c.city).toLowerCase().indexOf(term) !== -1; });
			if (!rows.length) { list.appendChild(h('p', { class: 'mc-muted', text: T.noCoaches })); }
			rows.forEach(function (c) {
				var btn = h('button', { type: 'button', class: 'mc-pick', onclick: function () { chooseCoach(c.id); } }, [
					c.photo ? h('img', { src: c.photo, alt: '', loading: 'lazy' }) : h('span', { class: 'mc-pick__ph', text: c.name.charAt(0) }),
					h('span', { class: 'mc-pick__body' }, [
						h('strong', { text: c.name + (c.verified ? ' ✔' : '') }),
						h('span', { class: 'mc-muted', text: [c.city, money(c.rate) + ' ' + T.perHour].filter(Boolean).join(' · ') })
					])
				]);
				list.appendChild(btn);
			});
		}
		q.addEventListener('input', draw);
		body.appendChild(q); body.appendChild(list);
		frame(body);
		draw();
	}

	// ---- Step 2: date & time ---------------------------------------------------------------
	function loadDates() {
		var today = ymd(new Date());
		api('coaches/' + S.coach.id + '/dates?duration=' + S.duration + '&from=' + today + '&days=30').then(function (r) {
			S.dates = r.dates || {};
			var keys = Object.keys(S.dates).sort();
			if (S.date && !S.dates[S.date]) { S.date = ''; }
			if (!S.date && keys.length === 1) { S.date = keys[0]; }
			if (S.date) { loadSlots(); } else { render(); }
		}).catch(function (e) { S.error = e.message; S.dates = {}; render(); });
	}
	function loadSlots() {
		S.slots = null; render();
		api('coaches/' + S.coach.id + '/slots?date=' + S.date + '&duration=' + S.duration).then(function (r) {
			S.slots = r.slots || [];
			render();
		}).catch(function (e) { S.error = e.message; S.slots = []; render(); });
	}
	function viewDateTime() {
		var c = S.coach;
		var body = h('div', { class: 'mc-step' }, [
			h('h2', { text: c.name }),
			h('p', { class: 'mc-muted', text: money(c.prices[S.duration]) + ' · ' + S.duration + ' ' + T.min })
		]);
		if (c.durations.length > 1) {
			var dur = h('div', { class: 'mc-chips', role: 'group', 'aria-label': T.duration });
			c.durations.forEach(function (m) {
				dur.appendChild(h('button', { type: 'button', class: 'mc-chip mc-chip--btn' + (m === S.duration ? ' is-on' : ''), 'aria-pressed': m === S.duration ? 'true' : 'false', onclick: function () {
					S.duration = m; S.time = ''; S.slots = null; S.dates = null; render();
				} }, [m + ' ' + T.min]));
			});
			body.appendChild(h('div', { class: 'mc-field' }, [h('span', { class: 'mc-label', text: T.duration }), dur]));
		}
		if (!S.dates) {
			body.appendChild(h('p', { text: T.loading }));
			frame(body, footer(T.next, false, null));
			loadDates();
			return;
		}
		var keys = Object.keys(S.dates).sort();
		body.appendChild(h('span', { class: 'mc-label', text: T.pickDate }));
		if (!keys.length) {
			body.appendChild(h('p', { class: 'mc-muted', text: T.noDates }));
		} else {
			var strip = h('div', { class: 'mc-dates', role: 'group', 'aria-label': T.pickDate });
			keys.forEach(function (k) {
				var d = parseYmd(k);
				strip.appendChild(h('button', { type: 'button', class: 'mc-date' + (k === S.date ? ' is-on' : ''), 'aria-pressed': k === S.date ? 'true' : 'false', onclick: function () { S.date = k; S.time = ''; loadSlots(); } }, [
					h('span', { class: 'mc-date__w', text: d.toLocaleDateString(undefined, { weekday: 'short' }) }),
					h('span', { class: 'mc-date__d', text: String(d.getDate()) }),
					h('span', { class: 'mc-date__m', text: d.toLocaleDateString(undefined, { month: 'short' }) })
				]));
			});
			body.appendChild(strip);
		}
		if (S.date) {
			body.appendChild(h('span', { class: 'mc-label', text: T.pickTime }));
			if (S.slots === null) { body.appendChild(h('p', { text: T.loading })); }
			else if (!S.slots.length) { body.appendChild(h('p', { class: 'mc-muted', text: T.noSlots })); }
			else {
				var wrap = h('div', { class: 'mc-chips mc-slots', role: 'group', 'aria-label': T.pickTime });
				S.slots.forEach(function (s) {
					wrap.appendChild(h('button', { type: 'button', class: 'mc-chip mc-chip--btn' + (s.start === S.time ? ' is-on' : ''), 'aria-pressed': s.start === S.time ? 'true' : 'false', onclick: function () {
						S.time = s.start; go(3); // tap a time = straight to the next step
					} }, [s.label]));
				});
				body.appendChild(wrap);
			}
		}
		frame(body, footer(T.next, !!S.time, function () { go(3); }, S.step > firstStep() || !cfg.coach));
	}

	// ---- Step 3: court ---------------------------------------------------------------------
	function courtFee() {
		if (S.mode === 'partner') {
			var f = facilityById(S.facilityId);
			if (f) { return { fee: f.court_fee || 0, note: f.pay_at_venue ? T.payAtVenue : (f.court_fee_note || '') }; }
		}
		if (S.mode === 'coach_venue') { return { fee: 0, note: T.payAtVenue }; }
		return { fee: 0, note: T.courtTba };
	}
	function facilityById(id) { return (cfg.facilities || []).filter(function (f) { return f.id === id; })[0]; }
	function courtReady() {
		if (S.mode === 'partner') { return !!S.facilityId; }
		if (S.mode === 'coach_venue') { return S.venueIndex >= 0; }
		return true;
	}
	function modeCard(id, title, desc) {
		return h('button', { type: 'button', class: 'mc-mode' + (S.mode === id ? ' is-on' : ''), role: 'radio', 'aria-checked': S.mode === id ? 'true' : 'false', onclick: function () {
			S.mode = id;
			if (id === 'coach_picks') { go(4); return; }
			render();
		} }, [h('strong', { text: title }), h('span', { class: 'mc-muted', text: desc })]);
	}
	function viewCourt() {
		var body = h('div', { class: 'mc-step' }, [h('h2', { text: T.courtTitle })]);
		var modes = h('div', { class: 'mc-modes', role: 'radiogroup', 'aria-label': T.courtTitle });
		var hasPartners = (cfg.facilities || []).length > 0;
		if (hasPartners) { modes.appendChild(modeCard('partner', T.modePartner, T.modePartnerD)); }
		modes.appendChild(modeCard('coach_venue', T.modeVenue, T.modeVenueD));
		modes.appendChild(modeCard('coach_picks', T.modeCoach, T.modeCoachD));
		body.appendChild(modes);

		if (S.mode === 'partner') {
			var box = h('div', { class: 'mc-sub' });
			var list = h('div', { class: 'mc-list' });
			(cfg.facilities || []).forEach(function (f) {
				list.appendChild(h('button', { type: 'button', class: 'mc-pick' + (S.facilityId === f.id ? ' is-on' : ''), onclick: function () { S.facilityId = f.id; go(4); } }, [
					f.photo ? h('img', { src: f.photo, alt: '', loading: 'lazy' }) : h('span', { class: 'mc-pick__ph', text: '🏓' }),
					h('span', { class: 'mc-pick__body' }, [
						h('strong', { text: f.name }),
						h('span', { class: 'mc-muted', text: [f.city, f.courts ? f.courts + ' ' + T.courts : '', f.pay_at_venue ? T.payAtVenue : (f.court_fee !== null ? T.courtFee + ' ' + money(f.court_fee) : '')].filter(Boolean).join(' · ') }),
						h('span', { class: 'mc-badges' }, [
							h('span', { class: 'mc-badge mc-badge--partner', text: T.partner }),
							f.live_availability ? h('span', { class: 'mc-badge', text: T.liveSoon }) : null // Phase 3-ready flag; no live check yet.
						])
					])
				]));
			});
			box.appendChild(list);
			if (window.L && window.MagstackMap && window.MagstackMap.pick) {
				var mapEl = h('div', { class: 'mc-pickmap', hidden: S.showMap ? null : true });
				box.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--sm mc-btn--ghost', onclick: function () {
					S.showMap = !S.showMap; mapEl.hidden = !S.showMap;
					if (S.showMap && !mapEl._init) {
						mapEl._init = true;
						window.MagstackMap.pick(mapEl, cfg.facilities, T, function (f) { S.facilityId = f.id; go(4); });
					}
				} }, [S.showMap ? T.hideMap : T.showMap]));
				box.appendChild(mapEl);
			}
			body.appendChild(box);
		}
		if (S.mode === 'coach_venue') {
			var vb = h('div', { class: 'mc-sub mc-list' });
			if (!S.coach.venues.length) { vb.appendChild(h('p', { class: 'mc-muted', text: T.noVenues })); }
			S.coach.venues.forEach(function (v, i) {
				vb.appendChild(h('button', { type: 'button', class: 'mc-pick' + (S.venueIndex === i ? ' is-on' : ''), onclick: function () { S.venueIndex = i; go(4); } }, [
					h('span', { class: 'mc-pick__body' }, [h('strong', { text: v.name }), h('span', { class: 'mc-muted', text: v.address || '' })])
				]));
			});
			body.appendChild(vb);
		}
		frame(body, footer(T.next, courtReady(), function () { go(4); }));
	}

	// ---- Step 4: details & confirm ---------------------------------------------------------
	function pricing() {
		var c = S.coach, court = courtFee();
		var single = c.prices[S.duration];
		var coachFee = single, label = T.coachFee;
		if (S.codeInfo) { coachFee = 0; label = T.coachFeePre; }
		else if (S.pkg) {
			var p = c.packages.filter(function (x) { return x.id === S.pkg; })[0];
			if (p) { coachFee = p.price; label = T.coachFeePkg + ' – ' + p.sessions + ' ' + T.sessions; }
		}
		return { coachFee: coachFee, label: label, courtFee: court.fee, courtNote: court.note, total: coachFee + court.fee };
	}
	function field(id, label, input) { return h('div', { class: 'mc-field' }, [h('label', { for: id, text: label }), input]); }
	function viewDetails() {
		var c = S.coach, P = S.player;
		var body = h('div', { class: 'mc-step' }, [h('h2', { text: T.yourDetails })]);
		var d = parseYmd(S.date);
		body.appendChild(h('p', { class: 'mc-recap', text: c.name + ' · ' + d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' + S.time + ' (' + S.duration + ' ' + T.min + ')' }));

		var form = h('form', { class: 'mc-form', novalidate: true, onsubmit: function (e) { e.preventDefault(); submit(); } });
		function bind(input, key) { input.addEventListener('input', function () { P[key] = input.value; }); return input; }
		form.appendChild(field('mcb-name', T.name, bind(h('input', { id: 'mcb-name', type: 'text', autocomplete: 'name', required: true, value: P.name }), 'name')));
		var row = h('div', { class: 'mc-row' });
		row.appendChild(field('mcb-email', T.email, bind(h('input', { id: 'mcb-email', type: 'email', autocomplete: 'email', inputmode: 'email', required: true, value: P.email }), 'email')));
		row.appendChild(field('mcb-mobile', T.mobile, bind(h('input', { id: 'mcb-mobile', type: 'tel', autocomplete: 'tel', inputmode: 'tel', required: true, placeholder: '09XX XXX XXXX', value: P.mobile }), 'mobile')));
		form.appendChild(row);
		var lvl = h('div', { class: 'mc-chips', role: 'group', 'aria-label': T.level });
		Object.keys(cfg.levels || {}).forEach(function (k) {
			lvl.appendChild(h('button', { type: 'button', class: 'mc-chip mc-chip--btn' + (P.level === k ? ' is-on' : ''), 'aria-pressed': P.level === k ? 'true' : 'false', onclick: function () { P.level = P.level === k ? '' : k; render(); } }, [cfg.levels[k]]));
		});
		form.appendChild(h('div', { class: 'mc-field' }, [h('span', { class: 'mc-label', text: T.level }), lvl]));
		var notes = h('textarea', { id: 'mcb-notes', rows: 2, maxlength: 1000, text: P.notes });
		form.appendChild(field('mcb-notes', T.notes, bind(notes, 'notes')));

		// Single session vs package
		if (c.packages.length || S.codeInfo) {
			var opts = h('div', { class: 'mc-modes', role: 'radiogroup' });
			function opt(val, title, desc) {
				return h('button', { type: 'button', class: 'mc-mode' + (S.pkg === val && !S.codeInfo ? ' is-on' : ''), role: 'radio', 'aria-checked': (S.pkg === val && !S.codeInfo) ? 'true' : 'false', onclick: function () { S.pkg = val; S.codeInfo = null; S.code = ''; render(); } }, [h('strong', { text: title }), h('span', { class: 'mc-muted', text: desc })]);
			}
			opts.appendChild(opt(0, T.single, money(c.prices[S.duration])));
			c.packages.forEach(function (p) { opts.appendChild(opt(p.id, p.name + ' – ' + p.sessions + ' ' + T.sessions, money(p.price) + ' · ' + T.firstSession)); });
			form.appendChild(opts);
		}
		// Package code (returning package holders)
		var codeWrap = h('details', { class: 'mc-code' }, [h('summary', { text: T.havePackage })]);
		var codeIn = h('input', { id: 'mcb-code', type: 'text', value: S.code, autocapitalize: 'characters' });
		codeIn.addEventListener('input', function () { S.code = codeIn.value; });
		var codeMsg = h('span', { class: 'mc-muted', role: 'status', text: S.codeInfo ? T.codeOk + ' ' + S.codeInfo.remaining : '' });
		codeWrap.appendChild(h('div', { class: 'mc-row' }, [field('mcb-code', T.packageCode, codeIn), h('div', { class: 'mc-field mc-field--actions' }, [h('button', { type: 'button', class: 'mc-btn mc-btn--sm', onclick: function () {
			api('package-check', 'POST', { code: S.code, email: P.email, coach_id: c.id, mc_ts: cfg.spam.mc_ts, mc_sig: cfg.spam.mc_sig }).then(function (r) { S.codeInfo = r; S.pkg = 0; render(); }).catch(function (e) { S.codeInfo = null; codeMsg.textContent = e.message; });
		} }, [T.checkCode])])]));
		codeWrap.appendChild(codeMsg);
		if (S.code) { codeWrap.open = true; }
		form.appendChild(codeWrap);

		// Price summary: coach fee and court fee are separate line items.
		var pr = pricing();
		var sum = h('dl', { class: 'mc-summary', 'aria-label': T.summary });
		sum.appendChild(h('dt', { text: pr.label })); sum.appendChild(h('dd', { text: money(pr.coachFee) }));
		sum.appendChild(h('dt', { text: T.courtFee })); sum.appendChild(h('dd', { text: pr.courtFee > 0 ? money(pr.courtFee) : (pr.courtNote || '—') }));
		if (pr.courtFee > 0 && pr.courtNote) { sum.appendChild(h('dd', { class: 'mc-muted mc-summary__note', text: pr.courtNote })); }
		sum.appendChild(h('dt', { class: 'mc-summary__total', text: T.total })); sum.appendChild(h('dd', { class: 'mc-summary__total', text: money(pr.total) }));
		var courtLabel = S.mode === 'partner' ? (facilityById(S.facilityId) || {}).name : (S.mode === 'coach_venue' ? (c.venues[S.venueIndex] || {}).name : T.modeCoach);
		form.appendChild(h('div', { class: 'mc-box' }, [h('strong', { text: T.summary }), h('p', { class: 'mc-muted', text: T.court + ': ' + (courtLabel || '') }), sum]));

		var btn = h('button', { type: 'submit', class: 'mc-btn mc-btn--lg mc-btn--block', disabled: S.busy ? true : null }, [S.busy ? T.sending : T.confirm]);
		form.appendChild(btn);
		body.appendChild(form);
		frame(body, footer(null, false, null, true, true));
	}

	function submit() {
		var P = S.player;
		if (!P.name.trim() || !P.email.trim() || !P.mobile.trim()) { S.error = T.required; render(); scrollTop(); return; }
		var hp = document.querySelector('#mc-booking [name="mc_website"]');
		var payload = {
			coach_id: S.coach.id, date: S.date, time: S.time, duration: S.duration,
			court_mode: S.mode, facility_id: S.mode === 'partner' ? S.facilityId : 0, venue_index: S.mode === 'coach_venue' ? S.venueIndex : -1,
			name: P.name, email: P.email, mobile: P.mobile, level: P.level, notes: P.notes,
			package_id: S.codeInfo ? 0 : S.pkg, package_code: S.codeInfo ? S.code : '',
			mc_website: hp ? hp.value : '', mc_ts: cfg.spam.mc_ts, mc_sig: cfg.spam.mc_sig
		};
		S.busy = true; S.error = ''; render();
		function send(token) {
			payload.mc_recaptcha = token || '';
			api('bookings', 'POST', payload).then(function (b) { S.busy = false; save(); done(b); }).catch(function (e) {
				S.busy = false;
				if (e.status === 409) { S.error = T.taken; S.time = ''; S.slots = null; S.dates = null; go(2); return; }
				S.error = e.message; render(); scrollTop();
			});
		}
		if (cfg.siteKey && window.grecaptcha) {
			window.grecaptcha.ready(function () { window.grecaptcha.execute(cfg.siteKey, { action: 'booking' }).then(send, function () { send(''); }); });
		} else { send(''); }
	}

	// ---- Confirmation / manage view --------------------------------------------------------
	function bookingCard(b, isNew) {
		var wrap = h('div', { class: 'mc-step mc-done' });
		if (isNew) {
			wrap.appendChild(h('h2', { text: T.doneTitle }));
			wrap.appendChild(h('p', { text: T.doneText }));
		} else {
			wrap.appendChild(h('h2', { text: T.session }));
		}
		var dl = h('dl', { class: 'mc-summary' });
		function row(k, v, cls) { dl.appendChild(h('dt', { class: cls || null, text: k })); dl.appendChild(h('dd', { class: cls || null, text: v })); }
		row(T.reference, b.reference);
		row(T.status, b.status_label);
		row(T.coach, b.coach.name);
		row(T.date, b.date_label);
		row(T.time, b.time_label);
		row(T.court, b.court);
		row(T.coachFee, money(b.coach_fee));
		row(T.courtFee, b.court_fee > 0 ? money(b.court_fee) : (b.court_fee_note || '—'));
		row(T.total, money(b.total), 'mc-summary__total');
		if (b.package) { row(T.pkgLeft, b.package.remaining + ' / ' + b.package.total + ' (' + b.package.code + ')'); }
		wrap.appendChild(dl);
		if (b.coach_contact) {
			wrap.appendChild(h('div', { class: 'mc-box' }, [h('strong', { text: T.coachContact }), h('p', { text: [b.coach_contact.mobile, b.coach_contact.email].filter(Boolean).join(' · ') })]));
		} else if (b.status === 'pending') {
			wrap.appendChild(h('p', { class: 'mc-muted', text: T.contactHidden }));
		}
		if (b.payment_html) { wrap.appendChild(h('div', { class: 'mc-box' }, [h('strong', { text: T.payTitle }), h('div', { html: b.payment_html })])); }
		if (isNew) {
			var url = new URL(window.location.href.split('?')[0]);
			url.searchParams.set('mc_ref', b.reference); url.searchParams.set('mc_t', b.token);
			wrap.appendChild(h('p', {}, [h('a', { href: url.toString() }, [T.manage])]));
		}
		if (!isNew && (b.status === 'pending' || b.status === 'confirmed')) {
			if (b.can_cancel) {
				wrap.appendChild(h('button', { type: 'button', class: 'mc-btn mc-btn--ghost', onclick: function () {
					if (!window.confirm(T.cancelSure)) { return; }
					api('bookings/cancel', 'POST', { ref: b.reference, t: cfg.token }).then(function (nb) { renderManage(nb); }).catch(function (e) { S.error = e.message; renderManage(b); });
				} }, [T.cancelBook]));
			} else { wrap.appendChild(h('p', { class: 'mc-muted', text: T.cancelClosed })); }
		}
		wrap.appendChild(h('p', {}, [h('a', { class: 'mc-btn mc-btn--ghost', href: cfg.directory }, [T.bookAnother])]));
		return wrap;
	}
	function done(b) {
		S.step = 5; root.textContent = '';
		root.appendChild(bookingCard(b, true));
		scrollTop();
	}
	function renderManage(b) {
		root.textContent = '';
		if (S.error) { root.appendChild(h('div', { class: 'mc-notice mc-notice--err', role: 'alert', text: S.error })); S.error = ''; }
		root.appendChild(bookingCard(b, false));
	}

	// ---- Boot ------------------------------------------------------------------------------
	loadSaved();
	if (cfg.facility) { S.mode = 'partner'; }
	if (cfg.ref && cfg.token) {
		api('bookings/lookup?ref=' + encodeURIComponent(cfg.ref) + '&t=' + encodeURIComponent(cfg.token)).then(renderManage).catch(function () {
			root.textContent = ''; root.appendChild(h('p', { class: 'mc-notice mc-notice--err', text: T.notFound }));
		});
	} else if (cfg.coach) {
		chooseCoach(cfg.coach);
	} else {
		viewCoaches();
	}
})();
