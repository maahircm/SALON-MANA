# Salon Management System

Full-stack salon ERP + CRM built with Laravel 13 (PHP 8.3+), MySQL 8, Blade, Bootstrap 5.

> **Status: Phase 1 (Foundation) complete.** Business modules (customers, appointments, POS, inventory, finance, reports)
> are built in later phases — see [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md). This README is extended each phase.

## Implemented so far
- Login/logout, throttling, password reset, activation/deactivation (takes effect immediately), login activity log
- Profile, password change, forced password change for new accounts, optional TOTP two-factor auth with recovery codes
- Role-based access control: 9 predefined roles, custom roles, granular permissions (`config/rbac.php`), privilege-escalation guards
- User administration; immutable, redacted audit log with correlation IDs; unauthorized (403) / error pages
- Reusable Blade components (KPI card, inputs, table, pagination, modal, confirm form, alerts)

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
