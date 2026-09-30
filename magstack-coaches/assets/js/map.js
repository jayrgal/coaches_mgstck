/* Magstack Coaches – facility map (Leaflet + OpenStreetMap). */
(function () {
	'use strict';

	var DEFAULT_CENTER = [12.8797, 121.774]; // Philippines
	var i18n = (window.magstackMap && window.magstackMap.i18n) || {};

	function t(k, d) { return i18n[k] || d; }
	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = text; }
		return n;
	}
	function money(v) {
		return (window.magstackMap && window.magstackMap.currency || '₱') + Number(v).toLocaleString();
	}
	function km(a, b) {
		var R = 6371, rad = Math.PI / 180;
		var dLat = (b[0] - a[0]) * rad, dLng = (b[1] - a[1]) * rad;
		var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a[0] * rad) * Math.cos(b[0] * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
		return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
	}

	function popup(f, opts) {
		var box = el('div', 'mc-pop');
		if (f.partner) { box.appendChild(el('span', 'mc-badge mc-badge--partner', t('partner', 'Partner'))); }
		box.appendChild(el('strong', 'mc-pop__title', f.name));
		if (f.address) { box.appendChild(el('div', 'mc-pop__line', f.address)); }
		var meta = [];
		if (f.courts) { meta.push(f.courts + ' ' + t('courts', 'courts')); }
		if (f.surface) { meta.push(f.surface); }
		if (meta.length) { box.appendChild(el('div', 'mc-pop__line', meta.join(' · '))); }
		if (f.hours) { box.appendChild(el('div', 'mc-pop__line', f.hours)); }
		var fee = f.pay_at_venue ? t('payAtVenue', 'Court fee: pay at venue') : (f.court_fee !== null ? t('courtFee', 'Court fee') + ': ' + money(f.court_fee) : '');
		if (fee) { box.appendChild(el('div', 'mc-pop__line', fee)); }
		if (f.court_fee_note) { box.appendChild(el('div', 'mc-pop__line mc-muted', f.court_fee_note)); }
		var a = el('a', 'mc-btn mc-btn--sm', t('bookHere', 'Book a coach here'));
		a.href = opts.bookUrl(f);
		box.appendChild(a);
		return box;
	}

	function init(root) {
		var facilities;
		try { facilities = JSON.parse(root.getAttribute('data-facilities') || '[]'); } catch (e) { facilities = []; }
		var mapEl = root.querySelector('.mc-map__canvas');
		var listEl = root.querySelector('.mc-map__list');
		var nearBtn = root.querySelector('[data-mc-near]');
		var status = root.querySelector('.mc-map__status');
		if (!mapEl || typeof L === 'undefined') { return; }

		var map = L.map(mapEl, { scrollWheelZoom: false }).setView(DEFAULT_CENTER, 6);
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(map);

		var markers = {};
		var bounds = [];
		facilities.forEach(function (f) {
			if (f.lat === null || f.lng === null) { return; }
			var icon = L.divIcon({
				className: 'mc-marker' + (f.partner ? ' mc-marker--partner' : ''),
				html: '<span></span>',
				iconSize: [26, 26],
				iconAnchor: [13, 26],
				popupAnchor: [0, -24]
			});
			var m = L.marker([f.lat, f.lng], { icon: icon, title: f.name, alt: f.name }).addTo(map);
			m.bindPopup(function () { return popup(f, { bookUrl: function (x) { return x.book_url; } }); });
			markers[f.id] = m;
			bounds.push([f.lat, f.lng]);
		});
		if (bounds.length === 1) { map.setView(bounds[0], 14); }
		else if (bounds.length > 1) { map.fitBounds(bounds, { padding: [30, 30] }); }

		var origin = null;
		function renderList() {
			if (!listEl) { return; }
			listEl.textContent = '';
			var items = facilities.slice();
			items.forEach(function (f) {
				f._d = (origin && f.lat !== null) ? km(origin, [f.lat, f.lng]) : null;
			});
			items.sort(function (a, b) {
				if (a.partner !== b.partner) { return a.partner ? -1 : 1; } // partners first
				if (a._d !== null && b._d !== null) { return a._d - b._d; }
				return a.name.localeCompare(b.name);
			});
			items.forEach(function (f) {
				var li = el('li', 'mc-map__item' + (f.partner ? ' is-partner' : ''));
				var btn = el('button', 'mc-map__itembtn');
				btn.type = 'button';
				var title = el('strong', '', f.name);
				btn.appendChild(title);
				if (f.partner) { btn.appendChild(el('span', 'mc-badge mc-badge--partner', t('partner', 'Partner'))); }
				var sub = [f.city, f._d !== null ? f._d.toFixed(1) + ' km' : ''].filter(Boolean).join(' · ');
				if (sub) { btn.appendChild(el('span', 'mc-muted', sub)); }
				btn.addEventListener('click', function () {
					var m = markers[f.id];
					if (m) { map.setView(m.getLatLng(), 15); m.openPopup(); mapEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
				});
				li.appendChild(btn);
				listEl.appendChild(li);
			});
		}
		renderList();

		if (nearBtn) {
			if (!navigator.geolocation) { nearBtn.hidden = true; }
			nearBtn.addEventListener('click', function () {
				if (status) { status.textContent = t('locating', 'Finding your location…'); }
				navigator.geolocation.getCurrentPosition(function (pos) {
					origin = [pos.coords.latitude, pos.coords.longitude];
					L.circleMarker(origin, { radius: 8, color: '#2563eb', fillOpacity: 0.8 }).addTo(map).bindTooltip(t('you', 'You are here'));
					var near = null, best = Infinity;
					facilities.forEach(function (f) {
						if (f.lat === null) { return; }
						var d = km(origin, [f.lat, f.lng]);
						if (d < best) { best = d; near = f; }
					});
					if (near) { map.fitBounds([origin, [near.lat, near.lng]], { padding: [40, 40], maxZoom: 15 }); }
					else { map.setView(origin, 13); }
					renderList();
					if (status) { status.textContent = near ? t('nearest', 'Nearest court:') + ' ' + near.name + ' (' + best.toFixed(1) + ' km)' : ''; }
				}, function () {
					if (status) { status.textContent = t('locDenied', 'Could not get your location. Please allow location access.'); }
				}, { enableHighAccuracy: false, timeout: 10000 });
			});
		}
		setTimeout(function () { map.invalidateSize(); }, 200);
	}

	/** Compact picker used by the booking wizard: markers with a "choose this court" button. */
	function pick(el, facilities, labels, onSelect) {
		var map = L.map(el, { scrollWheelZoom: false }).setView(DEFAULT_CENTER, 6);
		L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
		}).addTo(map);
		var bounds = [];
		facilities.forEach(function (f) {
			if (f.lat === null || f.lng === null) { return; }
			var icon = L.divIcon({ className: 'mc-marker mc-marker--partner', html: '<span></span>', iconSize: [26, 26], iconAnchor: [13, 26], popupAnchor: [0, -24] });
			var m = L.marker([f.lat, f.lng], { icon: icon, title: f.name, alt: f.name }).addTo(map);
			m.bindPopup(function () {
				var box = el2('div', 'mc-pop');
				box.appendChild(el2('strong', 'mc-pop__title', f.name));
				if (f.address) { box.appendChild(el2('div', 'mc-pop__line', f.address)); }
				var b = el2('button', 'mc-btn mc-btn--sm', (labels && labels.chooseCourt) || 'Choose this court');
				b.type = 'button';
				b.addEventListener('click', function () { onSelect(f); });
				box.appendChild(b);
				return box;
			});
			bounds.push([f.lat, f.lng]);
		});
		if (bounds.length > 1) { map.fitBounds(bounds, { padding: [24, 24] }); } else if (bounds.length === 1) { map.setView(bounds[0], 14); }
		setTimeout(function () { map.invalidateSize(); }, 150);
		return map;
	}
	function el2(tag, cls, text) { return el(tag, cls, text); }

	window.MagstackMap = { init: init, pick: pick, km: km };

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.mc-map[data-facilities]').forEach(init);
	});
})();
