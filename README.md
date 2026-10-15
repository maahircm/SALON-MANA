# Salon Management System

Full-stack salon ERP + CRM: appointments, customers, point of sale, payments and refunds, cash drawer, inventory and purchasing, staff attendance / leave /
commissions, loyalty and memberships, promotions, expenses, finance summary, reports, notifications. Laravel 13 (PHP 8.3+), MySQL 8, Blade, Bootstrap 5, Chart.js.

> **Release status.** All seven build phases are implemented and verified; see [`docs/ACCEPTANCE_REPORT.md`](docs/ACCEPTANCE_REPORT.md) for exactly what was
> tested (and on which database), what was measured, and what depends on configuration only you can supply (SMTP, an SMS gateway, TLS certificate, off-site
> backup storage). Read its "Known limitations" before going live.

Contents: [Install](#install) · [Configuration](#configuration) · [First administrator](#first-administrator) · [Scheduler and queue](#scheduler-and-queue-worker) ·
[Backup and restore](#backup-and-restore) · [Integrity check](#data-integrity-check) · [Tests](#tests) · [Production deployment](#production-deployment) ·
[Troubleshooting](#troubleshooting) · [Roles](#roles) · [Security notes](#security-notes) · [Feature reference](#feature-reference)

## Install

### Prerequisites
- PHP **8.3+** with extensions `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `tokenizer`, `xml`, `curl`, `zip` (`intl` and `gd` recommended). Check with `php -m`.
- Composer 2, MySQL **8.0+** (or MariaDB 10.6+; the test-suite and the race tests were run on MySQL 8). `mysqldump` and `mysql` command-line tools for backups.
- No Node/npm build: Bootstrap, Bootstrap Icons and Chart.js are loaded from pinned jsDelivr URLs. An air-gapped installation must self-host those files and adjust the
  Content-Security-Policy in `app/Http/Middleware/SecurityHeaders.php`.

### Linux / macOS
```bash
git clone <repository-url> salon && cd salon
composer install --no-dev --optimize-autoloader   # omit --no-dev on a development machine
cp .env.example .env
php artisan key:generate
# create the database and a least-privilege user (see "Database" below), then edit DB_* in .env
php artisan migrate --seed          # tables + roles/permissions only. No user, no password.
php artisan salon:create-admin      # prompts for name, email and password
php artisan storage:link            # public logo; receipts stay private
php artisan serve                   # development server on http://127.0.0.1:8000
```

### Windows with XAMPP (Apache + MySQL + phpMyAdmin)
1. Install **XAMPP with PHP 8.3** and **Composer**. In `C:\xampp\php\php.ini` make sure these lines are *not* commented out: `extension=pdo_mysql`, `extension=mbstring`, `extension=openssl`,
   `extension=fileinfo`, `extension=zip`, `extension=curl`, `extension=intl`, `extension=gd`. Restart Apache afterwards. Add `C:\xampp\php` and `C:\xampp\mysql\bin` to the Windows `PATH`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin` → **New** → database name `salon_mana`, collation `utf8mb4_unicode_ci` → **Create**.
   Then *User accounts → Add user account*: user `salon_app`, host `localhost`, a strong password, *Grant all privileges on database* `salon_mana` (nothing else). (XAMPP's `root` with an empty password is for a throw-away local test only.)
4. In **Command Prompt** in the project folder:
   ```bat
   composer install
   copy .env.example .env
   php artisan key:generate
   ```
5. Edit `.env` with Notepad: `DB_DATABASE=salon_mana`, `DB_USERNAME=salon_app`, `DB_PASSWORD=<the password>`. For backups also set
   `MYSQLDUMP_PATH="C:\xampp\mysql\bin\mysqldump.exe"` and `MYSQL_CLIENT_PATH="C:\xampp\mysql\bin\mysql.exe"`.
6. ```bat
   php artisan migrate --seed
   php artisan salon:create-admin
   php artisan storage:link
   php artisan serve
   ```
   Browse to `http://127.0.0.1:8000`. To serve through Apache instead, point a virtual host's `DocumentRoot` at the project's `public` folder (see "Production deployment").

### Database
```sql
CREATE DATABASE salon_mana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'salon_app'@'127.0.0.1' IDENTIFIED BY '<strong password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON salon_mana.* TO 'salon_app'@'127.0.0.1';
```
(`CREATE/ALTER/DROP` are needed by `php artisan migrate`; once installed you may revoke them and grant them again only for upgrades.)

**Isolation level.** The application sets `READ COMMITTED` on every MySQL connection (`DB_ISOLATION_LEVEL`, applied in `config/database.php`; no server change needed). Booking,
stock and document numbering lock rows and then re-read them; under MySQL's default REPEATABLE READ that pattern allowed double bookings in load tests. Deadlocks are retried automatically.

## Configuration
Everything secret lives in `.env` (never committed). Salon-specific values (name, logo, timezone, currency, formats, tax, receipts, notifications, POS/inventory/HR rules) are edited in the
application under **Administration → Settings** and stored in the database. `.env.example` documents every variable; the important ones:

| Variable | Meaning |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | `production`, `false` and the real `https://` URL in production |
| `APP_TIMEZONE` | salon timezone (editable in Settings only before the first appointment/invoice) |
| `DB_*`, `DB_ISOLATION_LEVEL` | database connection; keep `READ COMMITTED` |
| `SESSION_SECURE_COOKIE` | `true` when served over HTTPS |
| `MAIL_*` | SMTP for real email (`MAIL_MAILER=log` only writes emails to `storage/logs`) |
| `SMS_DRIVER` | `log` (default: texts are logged, **not delivered**) |
| `QUEUE_CONNECTION` | `database` (default); a worker must run — see below |
| `TRUSTED_PROXIES` | proxy IPs or `*` when behind a load balancer |
| `BACKUP_DIRECTORY`, `BACKUP_KEEP`, `MYSQLDUMP_PATH`, `MYSQL_CLIENT_PATH` | backups |

After changing `.env` in production run `php artisan config:clear` (or `config:cache` again).

## First administrator
There is **no default user and no default password**. `php artisan salon:create-admin` prompts for a name, an email and a password (minimum length and complexity enforced) and creates a Super Administrator.
For unattended provisioning set the environment variable `SALON_ADMIN_PASSWORD` for that single command only. Create the day-to-day accounts (owner, manager, receptionist, cashier, …) under **Users**, then
use the Super Administrator only for administration. New accounts must change their password at first sign-in; two-factor authentication (TOTP) can be enabled per user.

## Scheduler and queue worker
The application actually uses both:

- **Scheduler** — one entry runs everything: `salon:appointment-reminders` (every 10 min), `salon:low-stock-alerts` (08:00), `salon:birthday-greetings` (08:30), `salon:payment-reminders` (09:00),
  `salon:expire-memberships` (00:15), `salon:integrity-check --notify` (Mondays 03:00). Times are salon time.
  - Linux cron (`crontab -e`): `* * * * * cd /var/www/salon && php artisan schedule:run >> /dev/null 2>&1`
  - Windows Task Scheduler: *Create Basic Task* → trigger **Daily**, repeat every **1 minute** indefinitely → action *Start a program* `C:\xampp\php\php.exe`, arguments `artisan schedule:run`, *Start in* the project folder.
- **Queue worker** — email/SMS delivery is queued (3 attempts, back-off 1 and 5 minutes). Keep a worker running:
  - `php artisan queue:work --tries=3 --max-time=3600` (Linux: run under **Supervisor** or a systemd unit so it restarts; Windows: Task Scheduler "At startup" or NSSM).
  - After deploying new code run `php artisan queue:restart`.
  Without a worker, messages stay *Queued* in **Administration → Message Delivery Log**.

## Backup and restore
```bash
php artisan salon:backup --files --verify   # gzip dump + SHA-256 + (receipts/logo zip) ; --verify restores it into a scratch database and checks it
php artisan salon:backup --keep=30          # keep the newest 30 backups (default BACKUP_KEEP=14)
php artisan salon:verify-backup storage/app/backups/salon-salon_mana-20261015-030000.sql.gz --scratch=salon_verify
php artisan down                            # take the site offline before a restore
php artisan salon:restore storage/app/backups/salon-salon_mana-20261015-030000.sql.gz
php artisan salon:integrity-check && php artisan up
```
- Backups are written to `storage/app/backups` (mode 0600). **Copy them off the server** (another disk, NAS or cloud storage): a backup on the same disk does not survive a disk failure. Schedule `salon:backup` with cron / Task Scheduler (e.g. nightly), and copy the files on the same schedule.
- `--verify` needs a scratch database: either let the tool create one (the DB user needs `CREATE`/`DROP`), or create an empty database yourself, grant the application user all privileges on it and pass `--scratch=<name>`; it is emptied afterwards and the live database is never touched.
- Restore replaces **every table** with the backup's contents and asks for confirmation (`--force` skips it). Receipts/logo are restored by unzipping `salon-files-*.zip` into `storage/app/`.
- Credentials are passed to `mysqldump`/`mysql` through a temporary 0600 option file, never on the command line. Dumps use `--single-transaction`, so the site stays usable while a backup runs.

## Data integrity check
`php artisan salon:integrity-check` (also weekly on the scheduler, alerting administrators in-app) recomputes and compares: stock balances vs the stock ledger, negative stock, loyalty balances vs the points ledger,
invoice arithmetic and line sums, payments/refunds vs invoice amounts, payment status / overpayment / voided invoices holding money, document counters, coupon usage, cash-drawer variance, double-booked staff or rooms, and expense approvals.
It only reports (exit code 1 when something is wrong, `--json` for monitoring); it never "fixes" financial data.

## Tests
The test-suite never touches your real data: it uses an in-memory SQLite database by default.
```bash
php artisan test                                  # whole suite on SQLite
# against a real MySQL test database (create it first; the name must end in _test for the race tests):
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=salon_mana_test DB_USERNAME=salon_app DB_PASSWORD=... php artisan test
php artisan test --group=mysql                    # only the MySQL-only tests: real parallel-process race tests and the mysqldump round trip
```
`ConcurrencyMysqlTest` starts 10 real PHP processes against MySQL and proves that exactly one cashier sells the last unit, one receptionist wins a slot, one checkout submitted ten times creates one
invoice, a single-use coupon is redeemed once and one item is refunded once. **Warning:** it rebuilds (`migrate:fresh`) the configured database and refuses to run unless its name ends in `_test`.
Format code with `vendor/bin/pint`.

## Production deployment
1. **Server**: PHP 8.3-FPM (or Apache mod_php) with the extensions above, MySQL 8, a web server, Composer, Supervisor (or systemd), cron.
2. **Code**: `git clone` / `git pull`, then `composer install --no-dev --optimize-autoloader`, `cp .env.example .env` (first time), `php artisan key:generate` (first time only — never regenerate it afterwards; it encrypts sessions and two-factor secrets).
3. **`.env`** (production values): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://salon.example.com`, `SESSION_SECURE_COOKIE=true`, real `DB_*`, `MAIL_*`, `TRUSTED_PROXIES` if behind a proxy.
4. **Database**: `php artisan migrate --force` (+ `php artisan salon:sync-permissions` after upgrades), first time also `php artisan db:seed --force` and `php artisan salon:create-admin`.
5. **Optimise**: `php artisan storage:link`, `php artisan config:cache && php artisan route:cache && php artisan view:cache`, `php artisan queue:restart`.
6. **Permissions**: the web user must be able to write `storage/` and `bootstrap/cache/` (`chown -R www-data:www-data storage bootstrap/cache`, `chmod -R ug+rwX storage bootstrap/cache`). Everything else should be read-only to it. `.env` mode `640`.
7. **Document root** is the `public/` directory only.

**Nginx**
```nginx
server {
    listen 443 ssl http2;
    server_name salon.example.com;
    root /var/www/salon/public;
    index index.php;
    ssl_certificate     /etc/letsencrypt/live/salon.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/salon.example.com/privkey.pem;
    client_max_body_size 8m;                       # receipts are limited to 5 MB
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.3-fpm.sock; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
    location ~ /\.(?!well-known).* { deny all; }
}
server { listen 80; server_name salon.example.com; return 301 https://$host$request_uri; }
```
**Apache** (`mod_rewrite` on): `DocumentRoot /var/www/salon/public`, `<Directory /var/www/salon/public> AllowOverride All Require all granted </Directory>` (the shipped `public/.htaccess` does the rewriting), and a `<VirtualHost *:80>` that redirects to HTTPS.
On XAMPP, enable `mod_rewrite` and `mod_ssl` and set `DocumentRoot "C:/path/to/salon/public"` in `httpd-vhosts.conf`.

**HTTPS.** Obtain a certificate (Let's Encrypt: `sudo certbot --nginx -d salon.example.com` or `--apache`; on an internal network use your own CA), redirect port 80 to 443, then set `APP_URL=https://…` and `SESSION_SECURE_COOKIE=true`.
With an `https://` `APP_URL` all generated links use HTTPS and the application sends `Strict-Transport-Security`. Behind a TLS-terminating proxy set `TRUSTED_PROXIES`.

**Upgrading**: `php artisan down` → `git pull` → `composer install --no-dev -o` → **`php artisan salon:backup`** → `php artisan migrate --force && php artisan salon:sync-permissions` → `php artisan config:cache route:cache view:cache` → `php artisan queue:restart` → `php artisan up`.

### External services you must supply
| Need | Status in this repository |
|---|---|
| Outgoing email | Laravel mailer — set `MAIL_MAILER=smtp` and credentials. Not verified against a real SMTP server here (only the `log`/`array` mailers). |
| SMS | Only the `log` driver exists (messages are recorded, never delivered). To deliver, implement `App\Contracts\SmsGateway` for your provider and bind it in `AppServiceProvider::register()` for your `SMS_DRIVER` name. |
| Online card / mobile-money gateway | Not implemented: payments are recorded by the cashier (cash, card terminal, mobile money, bank transfer with a reference). No inbound callbacks exist, so there is no webhook to authenticate. If you add one it must verify a signature and use idempotency keys like the POS does. |
| TLS certificate, off-site backup storage, process supervisor | Yours to provide (see above). |
| CDN for Bootstrap/Chart.js | jsDelivr at pinned versions; self-host for offline use. |

## Troubleshooting
| Symptom | Check |
|---|---|
| `could not find driver` | enable `pdo_mysql` in `php.ini` (XAMPP: `extension=pdo_mysql`) and restart Apache; `php -m \| findstr pdo`. |
| `SQLSTATE[HY000] [1045] Access denied` | `DB_USERNAME`/`DB_PASSWORD` in `.env`, then `php artisan config:clear`. |
| `Base table or view not found` | run `php artisan migrate`. |
| 500 / blank page | `storage/logs/laravel.log`; make `storage` and `bootstrap/cache` writable; `php artisan config:clear view:clear`. |
| "No application encryption key" | `php artisan key:generate` (first install only). |
| Logged out immediately / 419 Page Expired | `SESSION_SECURE_COOKIE=true` while using plain `http://`; wrong `APP_URL`; clock skew; cleared cookies. |
| Logo or pictures missing | `php artisan storage:link`. |
| Messages stay *Queued* | no queue worker running — `php artisan queue:work`; check `failed_jobs`. |
| No emails arrive | `MAIL_MAILER=log` (emails are only in `storage/logs`) or SMTP settings; see the Delivery Log for the error. |
| Texts show "Logged, not delivered" | expected with `SMS_DRIVER=log`; install a real gateway driver. |
| Reminders never go out | scheduler not running (`php artisan schedule:list`, cron / Task Scheduler entry), reminders disabled in Notification Settings, or the appointment has no email/phone. |
| `mysqldump` not found | set `MYSQLDUMP_PATH`/`MYSQL_CLIENT_PATH`; XAMPP: `C:\xampp\mysql\bin\…`. |
| Charts empty/not drawn | the browser cannot reach `cdn.jsdelivr.net`; figures above the charts are unaffected. |
| "Timezone/currency locked" | by design once appointments/invoices exist. |
| Booking or sale error mentioning a deadlock | retried automatically; if persistent, check long-running queries and that `DB_ISOLATION_LEVEL` is `READ COMMITTED`. |

## Roles
Super Administrator (everything), Salon Owner, Salon Manager, Receptionist, Stylist / Beautician, Cashier, Inventory Manager, Accountant, HR Officer.
Defaults are least-privilege and editable under *Roles & Permissions*. After upgrades run `php artisan salon:sync-permissions` to register new
permissions (existing roles keep their customised assignments; grant new permissions in the UI).

## Security notes
Passwords hashed (bcrypt), CSRF on every form, output escaped, login / 2FA / password-reset throttling, session regeneration and encrypted sessions, optional TOTP two-factor, role- and permission-based authorisation with an explicit check on every
controller action (enforced by `RouteSecuritySweepTest`), immutable redacted audit log, private receipt files served only to authorised users, validated and re-named uploads (images only for the logo — SVG refused), spreadsheet-formula-safe CSV exports,
browser hardening headers (CSP, frame-ancestors, nosniff, referrer policy, HSTS over HTTPS) on every response, secrets only in `.env`, no default credentials. In production use `APP_DEBUG=false`, HTTPS and `SESSION_SECURE_COOKIE=true`.
The Content-Security-Policy still allows inline scripts/styles (the views use a few inline handlers); tightening it to nonces is listed under known limitations.

## Feature reference
### Phase 1 — foundation
- Login/logout, throttling, password reset, activation/deactivation (takes effect immediately), login activity log
- Profile, password change, forced password change for new accounts, optional TOTP two-factor auth with recovery codes
- Role-based access control: 9 predefined roles, custom roles, granular permissions (`config/rbac.php`), privilege-escalation guards
- User administration; immutable, redacted audit log with correlation IDs; unauthorized (403) / error pages
- Reusable Blade components (KPI card, inputs, table, pagination, modal, confirm form, alerts)

### Phase 2 — core salon operations
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

### Phase 3 — POS and finance
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

### Phase 4 — inventory and procurement
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

### Phase 5 — HR and CRM extensions
- **Attendance & leave:** clock in/out (self-service via `attendance.self`, or recorded by HR), manual correction with reason, monthly summaries; leave requests (annual/sick/unpaid/other) with approval; approved leave blocks the employee in availability and booking.
- **Commissions:** the rule (service/product, percent or fixed, per employee override) and rate are **snapshotted** on each commission row. Base = line net after discounts, before tax. Accrues when the invoice is issued (or when fully paid — *Settings › HR*); refunds and voids adjust proportionally via append-only adjustment rows. Statement, CSV export (formula-injection guarded) and a "my commissions" page for stylists.
- **Loyalty:** an append-only points ledger. Points are earned when an invoice is **fully paid**, on net spend (excluding tax, memberships and the part paid with points) × earn rate × membership multiplier. Points are redeemed as a payment method (`loyalty`, no cash drawer movement); refunds claw back earned points (never below zero balance). VIP threshold drives the *VIP* segment.
- **Memberships:** plans sold as POS items; terms (discount %, points multiplier, validity) are snapshotted per customer membership; an active membership discount is applied automatically (a manual discount wins). `php artisan salon:expire-memberships` expires overdue ones.
- **Promotions & coupons:** percentage or fixed, minimum purchase, date window, total / per-customer limits. Coupons are validated while pricing and **re-validated under lock** when the invoice is issued, allocated across lines to the cent, and released when the invoice is voided.
- **POS:** totals shown in the till come from a server-side preview endpoint, so what the cashier sees is what is stored.
- **CRM segments** on the customer list: new, returning, inactive, VIP, owing, members; lifetime value is shown only to users with `pos.invoices`.

**Upgrading from Phase 4:** `php artisan migrate && php artisan salon:sync-permissions` (new permissions `attendance.self`, `leave.request`, `commissions.own`, `pos.void`, `pos.discount_unlimited`; cashiers no longer have `pos.close`).

### Phase 6 — reporting, finance, settings and notifications
- **Dashboard:** operational tiles by permission (today's appointments, next appointments, new customers, low stock, expenses and leave awaiting decision) and, for `dashboard.financials`, a date-range filtered *Business performance* block: **revenue** (invoiced excl. tax, net of refunds), **cash collected** (received less refunded), **refunds**, **expenses** (approved + commissions) and **estimated profit** are shown separately because they differ. Charts use Chart.js from a CDN (pinned version); if the CDN is unreachable the figures still show.
- **Expenses:** categories (Rent, Salaries, Utilities, Supplies, Marketing, Maintenance, Transport, Other — editable), submit → approve / reject (reason required) / cancel, mark paid (method, date, reference), append-only approval history, optional receipt (JPG/PNG/PDF ≤ 5 MB) stored on the **private** disk under a random name and served only through an authorised route. Nobody approves their own expense unless *Settings › General* allows it (Super Administrators always may).
- **Financial summary** (`/finance`): net sales, tax, sales returns, net revenue, cash received/refunded, receivables, cost of goods (stock ledger unit cost), gross profit, approved expenses, commissions and net operating result, with CSV export. It is a management view derived from operational records, **not a double-entry ledger**; commissions are listed separately — if payroll expenses already include commissions, exclude one to avoid counting twice.
- **Reports** (`/reports`): 18 reports (sales by day/week/month, payments by method, sales by service/product, refunds, outstanding invoices, cashier closing, appointment status, cancellations/no-shows, new vs returning customers, lifetime value, employee productivity, commissions, expenses by category, profitability by month, inventory valuation, stock movements, low stock). Each has its own permission, date filter, pagination, totals, print view and CSV export (needs `reports.export`, audited, spreadsheet-formula-safe). Reports return at most 20,000 rows; narrow the period if told to.
- **Settings › General:** salon name, contact details, logo (PNG/JPG/WebP ≤ 1 MB — run `php artisan storage:link` once), timezone, currency, date/time formats, tax label/number/default rate, receipt layout (paper width, logo, tax number, cashier). Values are applied on every request and in queue workers. The **timezone and currency lock** once appointments/invoices exist (changing them would silently shift or mislabel history).
- **Notifications:** in-app bell and inbox, email, and SMS through a gateway abstraction. Every outbound message is written to a delivery log (queued → sent / failed, attempts, error) with a dedupe key so it can never be sent twice, and failed messages can be retried from *Message Delivery Log*. The shipped SMS driver is **`log`**: it writes messages to `storage/logs` and the log shows them as *Logged, not delivered*. Real SMS delivery needs a gateway driver and credentials (see "External services" in the deployment notes). Email uses Laravel's mailer (`MAIL_*` in `.env`; the default `log` mailer only writes emails to the log).
- **Automatic messages:** booking confirmation, cancellation, appointment reminder (when `reminder_at` is reached), low-stock alert (daily, in-app/email to inventory managers), payment reminder (off by default; after N days, repeated, capped) and birthday greeting (off by default; **marketing consent required**). Commands: `salon:appointment-reminders`, `salon:low-stock-alerts`, `salon:payment-reminders`, `salon:birthday-greetings`, `salon:expire-memberships`; all are on the scheduler (`php artisan schedule:run` every minute) and email/SMS go out through the queue (`php artisan queue:work`).

**Upgrading from Phase 5:** `php artisan migrate && php artisan salon:sync-permissions`, then `php artisan storage:link` if you want a logo. Timestamps of append-only records are now set by the application (not the database clock), so they follow the salon timezone.

