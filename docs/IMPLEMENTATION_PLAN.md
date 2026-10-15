# Salon Management System — Implementation Plan & Checklist

Stack: Laravel 13 (PHP 8.3), MySQL 8 (tests run on in-memory SQLite), Blade + Bootstrap 5 (CDN), Chart.js (CDN).
Environment notes: the dev container has no MySQL server; migrations are written to be portable and the
MySQL path must be verified on a real MySQL 8 instance (XAMPP) — see README.

Decisions
- Own lightweight RBAC (roles / permissions / pivots) with `Gate::before`; no external package.
- Money: `DECIMAL(14,2)` columns; arithmetic via integer minor units / bcmath helpers (Phase 3).
- Front-end assets via pinned CDN links + `public/css|js` (no Node build required on XAMPP).
- Work proceeds phase by phase; **the user must approve before each next phase starts.**

## Phase 1 — Foundation  [DONE]
- [x] Inspect environment, initialise Laravel 13
- [x] MySQL configuration, salon config (currency, timezone)
- [x] Layout, navigation, reusable Blade components
- [x] Authentication: login/logout, password reset, throttling, activation, login activity
- [x] Profile, password change, optional TOTP 2FA, forced password change
- [x] Roles & permissions (catalog, predefined roles, custom roles, escalation guards)
- [x] User administration
- [x] Audit log (immutable, redacted) + correlation id + viewer
- [x] Secure admin bootstrap command (`salon:create-admin`), no default password
- [x] Tests, README (initial)

## Phase 2 — Core salon operations  [DONE]
- [x] Customer CRM (CRUD, search, duplicates, archive, anonymise, preferences, restricted notes, export)
- [x] Services, categories, add-ons, packages, price history & snapshots
- [x] Employees, skills, status changes, weekly schedules, dated shifts, staff schedule view, chairs/rooms
- [x] Appointments: calendar, booking, walk-in, availability, reschedule, status workflow, cancellation policy
- [x] Booking settings (hours, buffer, policy); stylist-scoped visibility
- Deferred by design: service images, service consumables (Phase 4 needs products), add-ons selected at checkout (Phase 3),
  loyalty/membership/LTV (Phase 5), reminder delivery (Phase 6; `reminder_at` is already computed), leave-aware availability (Phase 5).
## Phase 3 — POS and finance  [DONE]
- [x] Invoices (drafts, gap-free numbering, frozen once issued), server-side pricing, tax and discount policy
- [x] POS checkout, split / partial payments, sales on account, idempotent payments, printable receipts + reprint tracking
- [x] Refunds (item based, capped by paid amount and per method), void rules
- [x] Cash drawers, sessions, paid-in/out, reconciliation, variance rules, daily summary
- [x] Financial audit trail; events for later modules; POS settings
- Deferred by design: retail product lines (Phase 4), commissions / loyalty (Phase 5), expenses & profit (Phase 6), payment gateways (needs provider credentials).

## Phase 4 — Inventory and procurement
Products, suppliers, POs, goods receipt, immutable stock ledger, consumables.
## Phase 5 — HR and CRM extensions
Attendance, leave, commissions, loyalty, memberships, promotions, reminders.
## Phase 6 — Reporting and administration
Dashboard analytics, reports, CSV exports, settings UI, notifications.
## Phase 7 — Hardening and release
Full tests, security/perf review, backup/restore verification, deployment docs, acceptance report.
