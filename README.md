# Salon Management System

Full-stack salon ERP + CRM built with Laravel 13 (PHP 8.3+), MySQL 8, Blade, Bootstrap 5.

> **Status: Phases 1–4 complete (foundation, core salon operations, POS & finance, inventory & procurement).** HR extensions, loyalty, reports
> and release hardening follow — see [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md). This README is extended each phase.

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

## Phase 4 — inventory and procurement
- **Products** (SKU, optional barcode, category, supplier, unit of measure, selling price + tax, reorder level, expiry, sellable vs consumable) with search and low-stock / expiring / inactive filters.
  Quantity and cost can **not** be typed into a product: stock only moves through the ledger.
- **Immutable stock ledger** (`stock_movements`): every change is one append-only row (type, signed quantity, balance after, unit cost, reference, user, reason) written together with the balance under a row lock.
  `php artisan` integrity checks (`InventoryService::reconcile`) compare each balance with the sum of its ledger. Quantities use 3 decimals, money 2.
  Duplicate deductions are impossible (unique idempotency key per sale line). Negative stock is refused unless enabled in *Settings › Inventory* — then every negative balance is audited.
- **Purchasing:** suppliers, purchase orders (draft → placed → part/fully received, cancel, close short), goods receipts with supplier reference and expiry. Receiving updates stock and the **weighted-average cost**; over-receipt is refused.
- **Adjustments:** stock-count correction, damaged, expired, found; **returns to suppliers**. Reason mandatory, `inventory.adjust` / `inventory.purchasing` permissions, audited.
- **POS integration:** retail products (with barcode entry) can be sold; stock is deducted in the *same transaction* as the invoice, so a sale that cannot be fully supplied is rolled back completely.
  **Service consumables** (e.g. 30 ml of colour per colouring) are deducted when the service is invoiced. Refunds can return goods to stock at their original cost; voiding an invoice restores whatever has not already been returned.
- **Valuation** (on-hand × average cost) and the ledger are available to users with `inventory.costs`; cost prices are hidden from everyone else (cashiers see stock, not costs).
- Not enabled: multiple storage locations / stock transfers (single location by design), batch/lot tracking (one expiry date per product).

**Database isolation level:** the MySQL connection runs in `READ COMMITTED` (`DB_ISOLATION_LEVEL`, set per connection in `config/database.php`; no server change needed). The app locks rows and then re-reads (stock, bookings, invoice numbers); under MySQL's default REPEATABLE READ that pattern allowed double bookings in load tests.
Transactions also retry automatically when MySQL reports a deadlock (`App\Support\Atomic`).

**Upgrading from Phase 3:** `php artisan migrate && php artisan salon:sync-permissions` (new permission `inventory.costs`; grant it to roles that should see costs).

## Phase 5 — HR and CRM extensions
- **Attendance & leave:** clock in/out (self-service via `attendance.self`, or recorded by HR), manual correction with reason, monthly summaries; leave requests (annual/sick/unpaid/other) with approval; approved leave blocks the employee in availability and booking.
- **Commissions:** the rule (service/product, percent or fixed, per employee override) and rate are **snapshotted** on each commission row. Base = line net after discounts, before tax. Accrues when the invoice is issued (or when fully paid — *Settings › HR*); refunds and voids adjust proportionally via append-only adjustment rows. Statement, CSV export (formula-injection guarded) and a "my commissions" page for stylists.
- **Loyalty:** an append-only points ledger. Points are earned when an invoice is **fully paid**, on net spend (excluding tax, memberships and the part paid with points) × earn rate × membership multiplier. Points are redeemed as a payment method (`loyalty`, no cash drawer movement); refunds claw back earned points (never below zero balance). VIP threshold drives the *VIP* segment.
- **Memberships:** plans sold as POS items; terms (discount %, points multiplier, validity) are snapshotted per customer membership; an active membership discount is applied automatically (a manual discount wins). `php artisan salon:expire-memberships` expires overdue ones.
- **Promotions & coupons:** percentage or fixed, minimum purchase, date window, total / per-customer limits. Coupons are validated while pricing and **re-validated under lock** when the invoice is issued, allocated across lines to the cent, and released when the invoice is voided.
- **POS:** totals shown in the till come from a server-side preview endpoint, so what the cashier sees is what is stored.
- **CRM segments** on the customer list: new, returning, inactive, VIP, owing, members; lifetime value is shown only to users with `pos.invoices`.

**Upgrading from Phase 4:** `php artisan migrate && php artisan salon:sync-permissions` (new permissions `attendance.self`, `leave.request`, `commissions.own`, `pos.void`, `pos.discount_unlimited`; cashiers no longer have `pos.close`).

## Phase 6 — reporting, finance, settings and notifications
- **Dashboard:** operational tiles by permission (today's appointments, next appointments, new customers, low stock, expenses and leave awaiting decision) and, for `dashboard.financials`, a date-range filtered *Business performance* block: **revenue** (invoiced excl. tax, net of refunds), **cash collected** (received less refunded), **refunds**, **expenses** (approved + commissions) and **estimated profit** are shown separately because they differ. Charts use Chart.js from a CDN (pinned version); if the CDN is unreachable the figures still show.
- **Expenses:** categories (Rent, Salaries, Utilities, Supplies, Marketing, Maintenance, Transport, Other — editable), submit → approve / reject (reason required) / cancel, mark paid (method, date, reference), append-only approval history, optional receipt (JPG/PNG/PDF ≤ 5 MB) stored on the **private** disk under a random name and served only through an authorised route. Nobody approves their own expense unless *Settings › General* allows it (Super Administrators always may).
- **Financial summary** (`/finance`): net sales, tax, sales returns, net revenue, cash received/refunded, receivables, cost of goods (stock ledger unit cost), gross profit, approved expenses, commissions and net operating result, with CSV export. It is a management view derived from operational records, **not a double-entry ledger**; commissions are listed separately — if payroll expenses already include commissions, exclude one to avoid counting twice.
- **Reports** (`/reports`): 18 reports (sales by day/week/month, payments by method, sales by service/product, refunds, outstanding invoices, cashier closing, appointment status, cancellations/no-shows, new vs returning customers, lifetime value, employee productivity, commissions, expenses by category, profitability by month, inventory valuation, stock movements, low stock). Each has its own permission, date filter, pagination, totals, print view and CSV export (needs `reports.export`, audited, spreadsheet-formula-safe). Reports return at most 20,000 rows; narrow the period if told to.
- **Settings › General:** salon name, contact details, logo (PNG/JPG/WebP ≤ 1 MB — run `php artisan storage:link` once), timezone, currency, date/time formats, tax label/number/default rate, receipt layout (paper width, logo, tax number, cashier). Values are applied on every request and in queue workers. The **timezone and currency lock** once appointments/invoices exist (changing them would silently shift or mislabel history).
- **Notifications:** in-app bell and inbox, email, and SMS through a gateway abstraction. Every outbound message is written to a delivery log (queued → sent / failed, attempts, error) with a dedupe key so it can never be sent twice, and failed messages can be retried from *Message Delivery Log*. The shipped SMS driver is **`log`**: it writes messages to `storage/logs` and the log shows them as *Logged, not delivered*. Real SMS delivery needs a gateway driver and credentials (see "External services" in the deployment notes). Email uses Laravel's mailer (`MAIL_*` in `.env`; the default `log` mailer only writes emails to the log).
- **Automatic messages:** booking confirmation, cancellation, appointment reminder (when `reminder_at` is reached), low-stock alert (daily, in-app/email to inventory managers), payment reminder (off by default; after N days, repeated, capped) and birthday greeting (off by default; **marketing consent required**). Commands: `salon:appointment-reminders`, `salon:low-stock-alerts`, `salon:payment-reminders`, `salon:birthday-greetings`, `salon:expire-memberships`; all are on the scheduler (`php artisan schedule:run` every minute) and email/SMS go out through the queue (`php artisan queue:work`).

**Upgrading from Phase 5:** `php artisan migrate && php artisan salon:sync-permissions`, then `php artisan storage:link` if you want a logo. Timestamps of append-only records are now set by the application (not the database clock), so they follow the salon timezone.

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
