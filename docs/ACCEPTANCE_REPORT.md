# Final acceptance report

Salon Management System — Laravel 13.36 · PHP 8.3.6 · MySQL 8.0.46 · Blade / Bootstrap 5 / Chart.js.
Covers Phases 1–7 of the build plan ([`IMPLEMENTATION_PLAN.md`](IMPLEMENTATION_PLAN.md)). Everything stated as verified below was actually run in the development
container; everything not run is listed under "Not verified". Numbers are from the last full runs recorded in section 3.

## 1. Verdict

**All seven phases are implemented and the application passes its automated suite on SQLite and on a real MySQL 8 server, including parallel-process race tests, a real backup → restore → verify round trip, and a fresh-clone installation walk-through.**

**It is not declared "production-ready".** The master brief requires that deployment configuration be verified before that claim, and the web-server / TLS / SMTP / SMS / Windows-XAMPP parts could not be exercised in this container
(section 6). The system is ready for a **staged pilot / user-acceptance test** on a real server; go live after the checklist in section 8 is completed and signed off by the salon.

## 2. Acceptance criteria (brief §13)

| # | Criterion | Result | Evidence |
|---|---|---|---|
| 1 | A fresh installation works from the documented instructions | **Verified** (with one caveat) | Fresh `git clone` into an empty directory, `.env` from `.env.example`, `key:generate`, `migrate --seed` as a non-root MySQL user (70 tables), `salon:create-admin`, `storage:link`, `php artisan serve`, real HTTP login with a session cookie, 15 pages returned 200, security headers present, queue worker delivered a queued message, `schedule:run`, `salon:integrity-check`, `salon:backup --verify`, `salon:restore`. *Caveat:* `composer install` itself did not complete in the sandbox (it fell back to slow source clones), so the existing `vendor/` directory was linked into the clone. |
| 2 | An administrator can log in securely and manage permissions | **Verified** | No default account; `salon:create-admin`; AuthenticationTest (14), AuthorizationTest (10), UserAdministrationTest (9), SecurityTest; role editor with privilege-escalation guards; throttling, 2FA, session regeneration. |
| 3 | A receptionist can register customers and book appointments | **Verified** | CustomerTest (15), AppointmentFlowTest (18), BookingTest (24). |
| 4 | Scheduling prevents unauthorised conflicts | **Verified** | BookingTest; `ConcurrencyMysqlTest`: 10 processes race for one slot → exactly 1 booking; integrity check detects overlaps. |
| 5 | A cashier can create a sale, accept payments and print a receipt | **Verified** | CheckoutTest (25), PaymentTest (10), ReceiptAndHistoryTest (6), GeneralSettingsTest (receipt layout). POS screen rendered in headless Chromium. |
| 6 | Partial payments and balances calculate correctly | **Verified** | PaymentTest, CheckoutTest, FinanceTest (receivables), NotificationTest (payment reminders on partially paid invoices). |
| 7 | Authorised refunds preserve original financial history | **Verified** | RefundTest (15); payments, refunds and invoice lines are immutable and an issued invoice's frozen fields cannot change; integrity check compares invoice totals with payments and refunds; race test: 10 simultaneous refunds → 1 refund. |
| 8 | Product stock changes are recorded in the inventory ledger | **Verified** | StockLedgerTest (12), PosInventoryTest (12), PurchasingTest (9); ledger is immutable; `salon:integrity-check` reconciles balances with the ledger; race test: last unit sold once. |
| 9 | Employee commissions follow documented rules | **Verified** | CommissionTest (14); rule and rate are snapshotted per line; refunds/voids adjust proportionally; documented in README (Phase 5). |
| 10 | Customers and employees can be searched and managed | **Verified** | CustomerTest, CustomerSegmentTest, EmployeeTest (9); searches by name/phone/email/number; archive, anonymise, export. |
| 11 | Reports return database data and respect permissions | **Verified** | ReportTest (7): numbers checked against hand-computed values; per-report permission matrix; export needs `reports.export`; DashboardTest (3). |
| 12 | Sensitive actions generate audit records | **Verified** | AuditLogTest (6) plus `audit_logs` assertions in the feature tests (expenses, settings, refunds, voids, exports, adjustments); the log is immutable and redacted. |
| 13 | Automated tests cover critical workflows and security restrictions | **Verified** | 393 tests (section 3); `RouteSecuritySweepTest` walks every route (guests redirected; a user with no permissions refused everywhere except self-service pages) and statically requires an authorisation check on every controller action. |
| 14 | No critical navigation item or primary action is a placeholder | **Verified** | `PagesRenderTest` follows every sidebar link as an administrator (all 200) and scans every page for invalid nested forms; every link target is a working screen. |
| 15 | Installation, configuration, backup, restore and deployment are documented | **Documented; partly verified** | README. Install/backup/restore/scheduler/queue were run (criterion 1). Apache/Nginx/HTTPS/Windows-XAMPP instructions are written but were not executed (section 6). |

## 3. Test and measurement evidence

| Run | Result |
|---|---|
| Whole suite, SQLite (in-memory), `php artisan test` | **393 tests: 387 passed, 6 skipped** (the 6 are the MySQL-only tests), 2,782 assertions |
| Whole suite, MySQL 8.0.46 (`salon_mana_test`) | **393 of 393 passed, 0 skipped**, 2,861 assertions (final run, including the MySQL-only race and mysqldump tests) |
| MySQL-only group (`--group=mysql`): 5 race tests with 10 real parallel PHP processes + `mysqldump` round trip | passed |
| Backup → tamper → restore → scratch-database verify (SQLite temp file and real MySQL) | passed; a damaged archive is refused by checksum |
| Code style | `vendor/bin/pint` clean |

**Concurrency (real MySQL, 10 parallel processes released together):** last unit sold once · one stylist slot booked once · one checkout submitted ten times → 1 invoice, 1 payment, ten identical answers ·
single-use coupon redeemed once · one item refunded once. After each race `salon:integrity-check` finds nothing.

**Performance (MySQL, scratch database with 30,000 invoices, 30,000 lines, 29,950 payments, 5,000 customers, 8,000 appointments — one year of a busy salon):**

| Operation | Time |
|---|---|
| 17 of 18 reports, one year | 1 – 430 ms each (1–3 queries) |
| Profitability by month, 12 months | ~1.8 s (195 queries: the finance summary runs once per month) |
| Dashboard (financial block, one year) | ~0.65 s, 31 queries |
| Financial summary, one year | ~0.28 s |
| Customer list (before / after fix) | 710 ms → ~10 ms; segment filters 20–290 ms |
| Invoice list, appointment list | ~25 ms |
| Integrity check over the whole data set | ~3.8 s |

**Fresh-install and UI check:** pages loaded in headless Chromium (Chart.js, Bootstrap served from local copies because the sandbox blocks the CDN): dashboard KPIs and four charts painted with data, POS, reports,
finance, settings and expenses screens loaded with **no console errors or Content-Security-Policy violations**. Checked in Chromium only; no Firefox/Safari/mobile devices.

## 4. Business rules (brief §9)

| Rule | Where it is enforced / proven |
|---|---|
| 1–3 customers, appointments, per-line employees | BookingTest, AppointmentFlowTest |
| 4 no stylist overlap unless permitted | BookingTest, race test, integrity check, setting `allow_stylist_overlap` |
| 5–6 multiple/partial payments, balance | PaymentTest, CheckoutTest |
| 7 refunds reference valid transactions, never exceed refundable | RefundTest, race test |
| 8 history keeps prices and tax | invoice lines snapshot price/tax/discount; ReceiptAndHistoryTest |
| 9–10 product sales → stock movement, never deducted twice | PosInventoryTest, idempotency keys, StockLedgerTest, race test |
| 11–12 documented, configurable commissions; cancelled/refunded correct | CommissionTest |
| 13 loyalty points not awarded twice | LoyaltyTest (unique idempotency key per qualifying invoice) |
| 14 permission-enforced actions | AuthorizationTest, RouteSecuritySweepTest, per-module 403 tests |
| 15 sensitive adjustments auditable | AuditLogTest and audit assertions per module |
| 16 exact money arithmetic | integer minor units (`App\Support\Money`), `DECIMAL` columns, MoneyTest, rounding/allocation tests (largest remainder) |
| 17 daily closing reconciliation | CashDrawerTest (opening, cash payments, refunds, paid-ins/outs, counted, variance); integrity check re-derives variance |
| 18 historical reports stable when prices/rates change | line and commission snapshots; stock movements store unit cost; FinanceTest |
| 19 salon timezone everywhere | `APP_TIMEZONE`/Settings; **fixed during hardening:** append-only tables used the database clock — now stamped by the application; timezone locks once data exists |
| 20 external callbacks authenticated and idempotent | **Not applicable yet:** no payment or notification callbacks are implemented (see section 6). |

## 5. Security and performance review (Phase 7)

**Reviewed:** authentication, authorisation on every route, mass assignment, SQL construction, output escaping, file uploads, redirects, rate limits, private files, headers, session settings, dangerous functions, secrets in the repository.

Findings and what was done:

| Finding | Action |
|---|---|
| Append-only tables relied on `DEFAULT CURRENT_TIMESTAMP` (database server clock/time zone), which could disagree with the salon time zone and misplace records near midnight in reports | Fixed: `StampsCreatedAt` trait on 12 models; tests for reports/finance on both databases |
| No browser hardening headers; 404s from the router bypassed middleware | Added `SecurityHeaders` (CSP, frame-ancestors, nosniff, referrer, permissions, COOP, HSTS over HTTPS) as global middleware; test; no CSP violations in the browser run |
| No automated proof that every route is protected | `RouteSecuritySweepTest` (guest sweep, no-permission sweep, static check that each controller action authorises, scan for raw request input into models) — passes; it found no unprotected route |
| Hidden N+1 queries | `Model::preventLazyLoading` outside production → the full suite now fails on lazy loads; two real ones found and fixed (purchase receipt lines, notification recipients) |
| Customer list evaluated a per-row subquery for every customer before paging (700 ms at 5,000 customers) | Fixed (loaded for the visible page only) |
| VIP segment compared a float binding on SQLite (silently matched nothing) | Fixed with a decimal cast; covered by CustomerSegmentTest |
| Receipt omitted the coupon discount line (totals did not add up on paper) | Fixed; GeneralSettingsTest |
| Raw SQL | Only fixed expressions with bound parameters; no user input concatenated |
| `{!! !!}` in views | Only constant markup (two icon cells) and `nl2br(e(...))` |
| Uploads | Receipts: JPG/PNG/PDF by content, ≤ 5 MB, random name, private disk, served through an authorised route with `nosniff` and a locked-down CSP. Logo: PNG/JPG/WebP ≤ 1 MB, dimensions checked, SVG refused, random name |
| Rate limits | Login 20/min, password reset 5/min, 2FA 10/min, sensitive actions/exports 10/min, POS 60/min |
| Mass assignment | 34 models use `$guarded = []`. No controller passes raw request data to a model (enforced by test); writes use validated arrays or explicit values. *Residual:* switching these models to explicit `$fillable` would add defence in depth. |
| Secrets | None committed; `.env.example` has no credentials; no default admin; backups use a 0600 option file, not the command line |

## 6. Not verified, known limitations and external dependencies

Not verified in this environment:
- **Web server and TLS:** the Nginx/Apache snippets, HTTPS redirect, HSTS over a real certificate and `TRUSTED_PROXIES` behaviour were not exercised (only `php artisan serve` and request-level tests).
- **Windows / XAMPP / phpMyAdmin:** instructions are written from the standard XAMPP layout; not run on Windows.
- **`composer install` from scratch** (see criterion 1) and the first-time download of dependencies.
- **Real email:** only the `log` and `array` mailers were used; no SMTP server.
- **Real SMS:** only the `log` driver exists. Messages are recorded and shown as "Logged, not delivered"; the system never reports them as sent.
- **Online payment gateways:** not implemented (payments are recorded by staff). There are no inbound webhooks, so rule 20 is not applicable yet; any future callback must verify a signature and use idempotency keys.
- **Browsers:** headless Chromium only; the CDN scripts were substituted by identical local copies in that run. Charts depend on `cdn.jsdelivr.net` at runtime (figures work without it).
- **Load beyond the measured data set**, multiple application servers, and MySQL replication.
- **Scheduler/queue under a real supervisor** (Supervisor/systemd/Task Scheduler): the commands were run by hand.

Known limitations:
- Content-Security-Policy still allows `'unsafe-inline'` scripts/styles (inline handlers in views); removing it needs nonces.
- Reports are capped at 20,000 rows per run; "Profitability by month" costs ~16 queries per month.
- The financial summary is a management view, not a double-entry ledger; commissions and payroll expenses may overlap if both are entered.
- One location / one currency / one timezone per installation; no batch/lot stock tracking; no multi-branch stock transfers.
- Receipt printing is browser printing (80 mm or A4 layout); no ESC/POS driver.
- Timezone and currency lock once appointments or invoices exist (by design).
- English only.

## 7. Defect log for the hardening phase (all fixed and covered by tests)
Timestamp clock mismatch on append-only tables · missing coupon line on receipts · customer-list performance · SQLite float comparison in the VIP segment · 404 responses without security headers · lazy-loading N+1 in purchase receiving and notification recipients ·
`MySQL` auto-increment-dependent test (`/invoices/1/receipt`) · scratch-database verification refused for users without `CREATE DATABASE` (now supports a pre-created `--scratch` database).

## 8. Go-live checklist (owner: whoever deploys)
1. Provision a server (PHP 8.3, MySQL 8, Nginx/Apache, Supervisor or systemd, cron) and follow README → *Production deployment*; set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`.
2. Install a TLS certificate and confirm HTTP redirects to HTTPS; confirm the response headers in the browser developer tools.
3. Run `php artisan migrate --force`, `db:seed --force`, `salon:create-admin`; create named staff accounts; remove or lock any shared account; enable 2FA for the administrator and owner.
4. Configure SMTP and send a test password-reset; decide on an SMS provider and implement the `SmsGateway` driver, or leave SMS off.
5. Start the scheduler entry and a supervised queue worker; check **Message Delivery Log** after a test booking.
6. Schedule `salon:backup --files --verify`; copy backups off the server; **perform one restore into a test database** (`salon:verify-backup`) and record the date.
7. Enter real settings (hours, tax, receipts, loyalty, commission rules, payment methods, opening stock) and run a day of parallel paper/system operation (user-acceptance test) before switching over.
8. Run `php artisan test` on the target PHP/MySQL versions and `php artisan salon:integrity-check` after go-live and weekly (scheduled).
