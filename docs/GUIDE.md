# Magstack Coaches — Phase 1 Guide

Install: upload `dist/magstack-coaches.zip` in **Plugins → Add New → Upload**, activate.
Requires WordPress 6.x, PHP 8.1+, MySQL/MariaDB (InnoDB). Use pretty permalinks (Settings → Permalinks → Post name).

## 1. Folder structure
```
magstack-coaches/
├─ magstack-coaches.php        bootstrap, constants
├─ uninstall.php               optional full cleanup
├─ includes/                   namespace Magstack\Coaches\ (PSR-4 autoload)
│  ├─ Autoloader, Plugin, Activator, Assets, Settings, Helpers, Database, Roles, PostTypes, Spam
│  ├─ Coach/         Coach (model, fee/founding logic), Availability, Registration, Directory, Dashboard
│  ├─ Facility/      Facility (model), Map (shortcode + facility page)
│  ├─ Booking/       Service (atomic create, statuses), Repository, Shortcode, Reminders, Ics
│  ├─ Rest/          PublicController, CoachController   (namespace magstack/v1)
│  ├─ Payments/      GatewayInterface, ManualGateway, Registry
│  ├─ Notifications/ ChannelInterface, EmailChannel, HookChannel (SMS/Viber), Notifier, Templates
│  ├─ Seo/           LocationPages
│  └─ Admin/         Menu, CoachAdmin, FacilityAdmin, BookingsPage, BookingsTable, SettingsPage
├─ templates/        theme-overridable: {theme}/magstack-coaches/{name}.php
├─ assets/css/magstack.css   (CSS variables; --mc-brand from Settings)
├─ assets/js/        booking.js, dashboard.js, map.js (vanilla JS)
├─ assets/vendor/    leaflet, qrcode (bundled: no CDN, no API keys)
└─ languages/        magstack-coaches.pot
```

## 2. Database schema
CPTs: `magstack_coach` (coach_id = post ID, meta `_mc_*`), `magstack_facility` (meta `_mc_*`). Role: `magstack_coach` (front-end dashboard only).

**`{prefix}mc_bookings`**: id, reference (UNIQUE), token; coach_id, user_id, player_name/email/mobile/level, notes; booking_date, start_time, end_time, duration; court_mode (`partner|coach_venue|coach_picks`), facility_id, venue_label; **coach_fee, court_fee, court_fee_note, total**, currency; commission_rate, commission_amount (Phase 2, not enforced); package_id, purchase_id; status (`pending|confirmed|declined|completed|cancelled`), payment_status (`unpaid|paid`), gateway; **slot_key UNIQUE** (`coach:date:HHMM`, only while pending/confirmed/completed, NULL when declined/cancelled); reminded_24, reminded_2, admin_note, created_at, updated_at. Indexes: coach_id, (coach_id, booking_date), booking_date, status, player_email, purchase_id.
**`{prefix}mc_packages`**: id, coach_id, name, sessions, price, validity_days, active.
**`{prefix}mc_package_purchases`**: id, code (UNIQUE), package_id, coach_id, player_name/email, sessions_total, sessions_used, price, payment_status, expires_on. `sessions_used` is recomputed from active bookings, so decline/cancel returns credit.

Coach meta: status, tier (free/pro), verified, email, mobile (private), bio, dupr, certifications, years, levels[], rate (per 60 min; other durations pro-rated), durations[], city, venues[], venue_facilities[], socials{}, availability{weekly,overrides}, approved_at, founding_ends, fee_paid_until, rating_avg, rating_count.
Facility meta: partner, address, city, lat, lng, courts, surface, hours, contact, court_fee, pay_at_venue, court_fee_note, url.

**Double-booking**: `GET_LOCK('mc_coach_{id}')` + transaction → recompute free slots (overlap-aware) → INSERT; `UNIQUE(slot_key)` is the backstop if named locks are unavailable.

## 3. Shortcode reference
| Shortcode | Attributes | Purpose |
|---|---|---|
| `[magstack_coach_register]` | – | Application form → Pending → admin approval |
| `[magstack_coach_dashboard]` | – | Coach login; bookings, availability, packages, profile, booking link + QR PNG |
| `[magstack_coaches]` | `city`, `level`, `filters="0"` | Directory grid + filters (location, price, level, date) |
| `[magstack_booking]` | `coach`, `facility` | 4-step booking. URL params `?coach=ID&facility=ID&date=YYYY-MM-DD` |
| `[magstack_map]` | `height`, `city`, `partner_only="1"` | Leaflet/OSM map, partner badge, Near me, "Book a coach here" |
| `[magstack_locations]` | `type="coaches\|courts"` | City links for SEO pages |

Player booking view: `{booking page}?mc_ref=REF&mc_t=TOKEN` (linked in emails). REST base `/wp-json/magstack/v1`: `GET coaches`, `coaches/{id}`, `coaches/{id}/slots?date&duration`, `coaches/{id}/dates`, `facilities`, `POST bookings`, `GET bookings/lookup`, `POST bookings/cancel`, `POST package-check`; authenticated `coach/me|availability|packages|bookings|profile`.

Hooks — filters: `magstack_payment_gateways`, `magstack_notification_channels`, `magstack_channel_sms_send`, `magstack_channel_viber_send`, `magstack_notification_text`, `magstack_notification_send`, `magstack_spam_check`, `magstack_coach_listable`, `magstack_coach_reviews`. Actions: `magstack_booking_created`, `magstack_booking_status_changed`, `magstack_booking_payment_changed`, `magstack_booking_reminder`, `magstack_coach_registered|approved|rejected`.

## 4. Setup guide
1. Activate: pages are auto-created (Find a Coach, Book a Coach, Become a Coach, Coach Dashboard, Pickleball Courts Map) and mapped in **Settings → General**.
2. General: currency (PHP/₱), brand color, admin email. Booking: lead time, max advance, cancellation window, slot step, .ics.
3. Monetization: *Flat monthly listing fee*, Founding period (days from approval), tier fees, "Hide unpaid coaches". Commission % and tiers are stored, not enforced (Phase 2).
4. Payments: GCash instructions + QR image. Emails: edit templates (blank = default). Spam: optional reCAPTCHA v3.
5. Add **Facilities** (address, lat/lng, courts, court fee/note); tick *Partner* for curated partners.
6. Add pages to your menu. Make sure WP-Cron runs (real cron hitting `wp-cron.php` every 5–15 min) for reminders.
7. SEO pages: `/pickleball-coaches/{city}/`, `/pickleball-courts/{city}/` (toggle + regenerate in Settings → SEO). With Yoast/Rank Math active we pass title/description/canonical through their filters and skip our own meta tags. Exclude booking, dashboard and registration pages from page caching (per-visitor nonces).

Lifecycle: application → **Pending** → admin *Approve* → coach email with password link, Founding period starts → after it ends the coach shows **Fee due** until you click *Mark fee paid (+1 month)*.

## 5. Test checklist
- **Registration**: submit with photo + 2 packages → Pending, admin emailed, hidden from directory; duplicate email blocked; honeypot / too-fast / rate-limit rejected.
- **Approval**: Approve → published, role assigned, email w/ password link; Reject → email, stays hidden; Verified toggle shows badge on card and profile.
- **Founding / fee due**: approval sets end = approval + N days ("Founding (free)"); past end date → "Fee due"; *Hide unpaid* on → hidden from directory/booking/REST; *Mark fee paid* → visible, "Paid until".
- **Availability**: weekly hours + closed-day override; slots respect lead time, step, duration, max advance; booked slots disappear.
- **Booking, 3 court modes**: partner facility (court fee line; "live availability soon" flag only), coach venue, coach picks. Summary and emails show coach fee and court fee as separate lines. Deep links `?coach=`, `?facility=`, `?date=`.
- **Packages**: buy (fee = package price, remaining n−1); redeem code + email (fee 0); decline/cancel returns a session; wrong code/email rejected.
- **Contact hiding**: profile, cards, REST and pending emails never show coach email/mobile; after Confirm, confirmation email, manage page and lookup show them.
- **Double booking**: two browsers, same slot (second gets "just taken"); overlapping duration blocked; raw duplicate insert rejected by `slot_key`; declined slot bookable again.
- **Emails**: each status change mails player, coach, admin; .ics on confirm; templates editable; SMS/Viber only when a provider filter is hooked and channel enabled.
- **Reminders**: confirmed booking 3–24h away → one 24h reminder; ≤2h → one 2h reminder (`wp cron event run magstack_coaches_reminders`).
- **Cancellation**: player cancel via manage link only outside the cancellation window.
- **Map**: markers, partner badge, partners first, Near me, "Book a coach here" prefills step 3.
- **SEO**: unique title/meta/intro/listing/JSON-LD per city; unknown city 404; toggle off → 404; Yoast/Rank Math not duplicated.
- **Admin**: bookings filters, CSV export, row actions, detail edit; settings save per tab keeps others. **Uninstall** with cleanup on removes tables, posts, options, role.

## 6. Roadmap
**Phase 2**: PayMongo/WooCommerce gateways (implement `GatewayInterface`, register on `magstack_payment_gateways`); commission + Free/Pro enforcement (`commission_rate` already snapshotted per booking; add payout ledger); verified-player reviews feeding `rating_avg/count` and `magstack_coach_reviews`; DUPR badge verification of `_mc_dupr`; SMS/Viber providers on the channel filters; recurring bookings.
**Phase 3**: Magstack court-reservation integration: replace the `live_availability` flag with real slot lookups for partner facilities (`magstack_url`), hold a court while the coach confirms, auto-fill court fee, two-way sync.
