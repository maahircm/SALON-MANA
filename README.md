# Salon Management System

Full-stack salon ERP + CRM built with Laravel 13 (PHP 8.3+), MySQL 8, Blade, Bootstrap 5.

> **Status: Phases 1–3 complete (foundation, core salon operations, POS & finance).** Inventory, HR extensions, loyalty and reports
> are built in later phases — see [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md). This README is extended each phase.

## Implemented so far
- Login/logout, throttling, password reset, activation/deactivation (takes effect immediately), login activity log
- Profile, password change, forced password change for new accounts, optional TOTP two-factor auth with recovery codes
- Role-based access control: 9 predefined roles, custom roles, granular permissions (`config/rbac.php`), privilege-escalation guards
- User administration; immutable, redacted audit log with correlation IDs; unauthorized (403) / error pages
- Reusable Blade components (KPI card, inputs, table, pagination, modal, confirm form, alerts)

## Phase 2 — core salon operations
- **Customers:** register / edit / search / archive, auto customer numbers, duplicate-phone warning, preferences, restricted allergy & medical notes
  (`customers.sensitive`), appointment history, birthday-month and marketing-consent filters, audited CSV export, privacy erasure (anonymise) action.
  Marketing consent is recorded explicitly (default: no consent). Purchases, loyalty and lifetime value appear when POS/loyalty land.
- **Services & pricing:** categories, services (duration, price, promo price with dates, tax rate, required skill), add-ons, packages, price history.
  Only `services.price` holders can change prices. Appointment lines snapshot name/price/tax/duration, so later changes never rewrite history.
- **Employees:** directory, profile, skills, employment status changes (audited, reason), commission settings, salary (restricted to `employees.sensitive`),
  link to a user account, weekly schedule, dated shifts / days off, staff schedule overview, chairs & rooms.
- **Appointments:** day/week/month calendar, list, booking (multi-service, per-service stylist and chair/room), walk-ins, free-slot finder, reschedule,
  cancellation with reason and policy, no-show, status history. Statuses: Pending → Confirmed → Checked In → In Service → Completed (+ Cancelled, No Show).
- **Booking rules (all server-side, inside a DB transaction that locks the stylist/room rows first):** no overlapping bookings per stylist or chair/room,
  configurable buffer, stylist working hours + salon opening hours, required skills, notice/advance limits, customer cannot be double-booked,
  completed appointments editable only with `appointments.override` + a reason. A completed appointment is **not** marked paid.
- **Booking settings** (Administration › Booking Settings, `settings.manage`): opening hours, buffer, slot interval, cancellation notice and late-cancellation policy
  (flag or block), reminder lead time, optional stylist overlap.
- **Stylists** only see appointments they perform (their user account must be linked to their employee record); `appointments.all` shows everyone's.

**Upgrading from Phase 1:** run `php artisan migrate` then `php artisan salon:sync-permissions`. The new `appointments.all` permission is given to new roles automatically, but
existing roles keep their customised permissions, so tick it for Receptionist/Cashier/Manager under *Roles & Permissions* if they should see all appointments.

**Time zone:** appointment times are stored in the salon time zone (`APP_TIMEZONE`, default `Africa/Mogadishu`, no daylight saving). Changing the zone later does not
shift existing rows, so set it before going live.

**Money:** all amounts are `DECIMAL(12,2)` columns; PHP arithmetic uses `App\Support\Money` (integer cents). No `bcmath` extension is required.

**Calendar:** uses FullCalendar from a pinned CDN URL (jsDelivr). The browser needs internet access to load it (as for Bootstrap).

## Phase 3 — POS and finance
- **POS checkout** (`/pos`): search a customer, add services (or open a booked appointment's *Checkout*), assign the provider per line, quantities, authorised discounts,
  tax, split payments (cash / card / bank transfer / mobile money; methods are rows in `payment_methods`), partial payments and sales on account, draft sales, printable receipts.
  Totals shown in the browser are for display only: the **server re-prices every cart** from the catalogue / booked line (the browser cannot set prices, tax or totals).
- **Invoices:** gap-free numbers (`INV-000001`, prefix/digits configurable), one customer, many payments, status *draft → issued → voided*, payment status *unpaid / partially paid / paid*.
  An issued invoice's lines, prices, tax and totals are frozen (enforced in the models). `Balance due = total − payments`; refunds never change it.
- **Payments** are append-only and idempotent (`idempotency_key`): double-clicks, back-button resubmits and retries record one payment. An invoice is `paid` only when payments ≥ total; overpayment is refused.
- **Refunds** are item/quantity based, need `pos.refund` + a reason, can never exceed money actually paid and not yet refunded, and must go back through a method the customer paid
  with (capped per method). They add a linked record (`RF-000001`); the invoice and its payments stay untouched. **Void** (`pos.void`) is only possible when no net customer money is held.
- **Discounts:** per line, % or fixed. Needs `pos.discount`; above the limit (Settings › POS, default 10%) needs `pos.discount_unlimited`; approvals are flagged in the audit log.
- **Cash drawer:** a cashier opens a session (opening cash), all payments/refunds attach to it, paid-in / paid-out are recorded with reasons, and closing computes
  `expected = opening + cash sales − cash refunds + paid-in − paid-out`, compares it with the counted cash, **requires an explanation for any variance**, stores an immutable snapshot and freezes the session.
  One open session per drawer and per cashier (database-enforced). Managers (`pos.close`) can review/close any session, manage drawers and view the **Daily Summary**.
- **Audit trail:** invoice issue/void, every payment, refund, drawer open/close/movements, receipt reprints and POS settings changes are written to the audit log.
- **Extension points:** `InvoiceIssued`, `PaymentRecorded` and `RefundProcessed` events fire after commit; stock deduction, commissions and loyalty (later phases) subscribe to them.

Accounting vocabulary used on screen: *Sales (invoiced)*, *Payments collected*, *Refunds*, *Outstanding balances (receivables)* — kept separate; nothing here is called profit.
This is a point-of-sale cash book, **not** a double-entry general ledger.

**Not in Phase 3 (by design):** retail product lines and stock deduction (Phase 4), add-ons at checkout, commissions and loyalty points (Phase 5), expenses and profit estimates (Phase 6),
external card/mobile-money gateways (not implemented: record the gateway reference manually; any gateway needs provider credentials and its own authenticated, idempotent webhook).

**Upgrading from Phase 2:** `php artisan migrate && php artisan salon:sync-permissions`. New permissions `pos.void` and `pos.discount_unlimited` exist; new installs give Cashiers only
`pos.drawer` (they close their own session) and Managers `pos.refund`, `pos.void`, `pos.close`. Existing roles keep their customised permissions — adjust them under *Roles & Permissions*.
The migration seeds four payment methods and one drawer ("Main drawer"); add more under *Cash Drawer › Manage drawers*.

**Daily routine:** cashier opens a drawer session → takes sales/payments → at shift end counts the drawer and closes the session → manager reviews *Daily Summary*.

## Prerequisites
PHP 8.3+ with extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `tokenizer`, `xml`, `curl`, `zip` (`intl`, `gd` recommended); Composer 2; MySQL 8.
Bootstrap and Bootstrap Icons (and later Chart.js) load from pinned CDN URLs — no Node build is needed.

## Install (Windows + XAMPP)
1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. Open phpMyAdmin (`http://localhost/phpmyadmin`) → *New* → create database `salon_mana`, collation `utf8mb4_unicode_ci`.
   (Optionally create a user `salon_app` with privileges on that database only.)
3. In a terminal in the project folder:
   ```
   composer install
   copy .env.example .env
   php artisan key:generate
   ```
4. Edit `.env`: set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (XAMPP default is `root` with an empty password — local use only).
5. ```
   php artisan migrate --seed
   php artisan salon:create-admin
   php artisan serve
   ```
   `migrate --seed` creates roles and permissions only. **No default user or password exists**; `salon:create-admin`
   prompts for the first Super Administrator's name, email and password.
6. Open `http://127.0.0.1:8000` and sign in.

Linux/macOS: use `cp` instead of `copy`.

## Roles
Super Administrator (everything), Salon Owner, Salon Manager, Receptionist, Stylist / Beautician, Cashier, Inventory Manager, Accountant, HR Officer.
Defaults are least-privilege and editable under *Roles & Permissions*. After upgrades run `php artisan salon:sync-permissions` to register new
permissions (existing roles keep their customised assignments; grant new permissions in the UI).

## Tests
Tests use an in-memory SQLite database (never your real database):
```
php artisan test
```
**Verification caveat:** no MySQL server was available in the development container, so migrations have been run on SQLite only.
Run `php artisan migrate` on MySQL 8 as part of installation.

## Mail / password reset
`MAIL_MAILER=log` by default: reset links are written to `storage/logs/laravel.log`. Configure SMTP in `.env` for real delivery.

## Security notes
Passwords hashed, CSRF on all forms, output escaped, login & 2FA throttling, session regeneration on login, encrypted sessions,
secrets only in `.env`. In production set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` and serve over HTTPS.
