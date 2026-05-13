# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Ekont Uptime Monitor — a plain-PHP/SQLite monitoring console for uptime checks, incident tracking, broken-link discovery, and notification retries. No framework, no Composer dependencies, no build step.

## Commands

```bash
# Local dev server
php -S localhost:8080 -t public

# Database initialization
php database/init_sqlite.php     # create schema
php database/migrate_sqlite.php  # run migrations
php database/create_admin.php    # create first admin user

# Cron workers (manual invocation)
php cron/check_uptime.php        # uptime checks
php cron/scan_links.php          # link scanning
php cron/run_manual_link_scan.php {monitor_id} {max_depth} # detached manual link scan worker
php cron/retry_notifications.php # notification retries
php cron/daily_report.php        # send daily report
php cron/weekly_report.php       # send weekly report

# Run a single test
php tests/LinkScannerDepthTest.php
php tests/MonitorRepositoryActionsTest.php
# etc. — each test file is a standalone script

# Run all tests
Get-ChildItem tests/*.php | ForEach-Object { php $_.FullName }
```

## Architecture

### File routing

Root-level `.php` files (e.g. `index.php`, `login.php`) are **bridge files** that `require` their `public/` counterparts. This supports shared-hosting deployment where the document root points to the project root rather than `public/`.

All page logic lives in `public/`. Every page starts with:
```php
require_once __DIR__ . '/../bootstrap.php';
require_auth();  // if the page requires login
```

### Bootstrap (`bootstrap.php`)

Loaded by every page and worker. It:
- Requires all classes from `app/`, `app/Repositories/`, and `app/Services/`
- Sets timezone from `APP_TIMEZONE`
- Defines global helper functions: `config()`, `url_for()`, `redirect_to()`, `require_auth()`, `auth()`, `current_user()`, `e()`
- Starts the PHP session (web only)

### Configuration system

Two-tier config:
1. **`.env` file** — flat `KEY=VALUE` pairs, accessed via `config('KEY')`. Values are trimmed of surrounding quotes.
2. **`config/*.php` files** — each returns an associative array. Accessed via dot notation: `config('app.brand.title')` reads `config/app.php` → `['brand']['title']`.

### Database

`Database::connection()` is a singleton returning a PDO instance. SQLite is the primary driver; MySQL support exists but is secondary. Connection sets `ERRMODE_EXCEPTION` and `FETCH_ASSOC`.

### Data layer: Repositories

Each table has a Repository class in `app/Repositories/` that wraps PDO queries:
- `UserRepository`, `MonitorRepository`, `IncidentRepository`, `BrokenLinkRepository`, `BrokenLinkIgnoreRuleRepository`, `LinkScanRepository`, `NotificationRetryRepository`

Some repositories include inline `ensure*Column()` methods for lightweight schema migration (adding missing columns via `ALTER TABLE`).

### Business logic: Services

- `AuthService` — session-based auth with `password_verify`. Stores user ID in `$_SESSION['auth_user_id']`. Use `require_auth()` on protected pages, `auth()->user()` to get current user.
- `UptimeChecker` — performs HTTP checks against monitors, updates check history, manages incident open/recovery lifecycle
- `LinkScanner` — crawls same-host pages up to configurable depth, checks resources in parallel batches, discovers broken links
- `LinkScanRunner` — wraps LinkScanner for both cron and manual (live-progress) scan execution
- `LinkScanProcessLauncher` — starts manual link scans as detached workers and logs launch failures to `storage/logs/manual_link_scan_launch.log`
- `LinkScanResetter` — clears link scan jobs, discovered links, broken links, and live state files while preserving users, monitors, uptime data, notification data, and ignore rules
- `Notifier` — sends email (PHP `mail()`) and Telegram (bot API) notifications for incidents and broken-link summaries
- `ReportService` — builds daily/weekly operational summaries, sends them through configured email/Telegram channels, and stores delivery status in `report_runs`

### URL generation

Always use `url_for('/path.php', ['param' => 'value'])` instead of hardcoding paths. This prepends `APP_BASE_PATH` so the app works under a subdirectory (e.g. `/uptime`).

### Tests

Tests are standalone PHP scripts in `tests/` with no test framework. Each test prints `"TestName OK"` on success and throws `RuntimeException` on failure. Tests rely on `bootstrap.php` for class loading and config. Some tests use fake subclasses of services (e.g. `FakeDepthLinkScanner`) that override `fetchHtml()` and `checkResource()` to return canned responses without making real HTTP calls.

### Cron workers (`cron/`)

Three CLI scripts meant to be invoked by system cron:
- `check_uptime.php` — queries monitors due for check, runs `UptimeChecker`, handles incident lifecycle
- `scan_links.php` — queries monitors due for link scan, runs `LinkScanRunner`
- `retry_notifications.php` — processes pending items in the notification retry queue
- `daily_report.php` and `weekly_report.php` — generate and send report summaries, then store rows in `report_runs`

`ReportService` now stores both plain-text and HTML bodies in `report_runs`. Email sends should remain multipart/plain-text + HTML, while Telegram continues to use the plain-text body. The reports screen also exposes CSV export through `/reports_export.php`.

Manual scans are started by `public/link_scan_run.php`. When shell execution is available, it delegates to `LinkScanProcessLauncher`, which starts `cron/run_manual_link_scan.php` outside the HTTP request. Shared hosting may execute `/usr/bin/php` as CGI/FastCGI instead of CLI; the manual worker therefore rejects direct browser execution but can accept shell-provided `$argv` or `UPTIME_MONITOR_ID` / `UPTIME_MAX_DEPTH` environment values.

SQLite connections enable WAL mode and `busy_timeout` to reduce lock contention between link-scan workers, live status polling, and UI actions.

### Inline schema migration

Pages that reference newer columns (e.g. `broken_links.ignored_at`) include `ensure*Columns()` functions that check `PRAGMA table_info()` and add missing columns on the fly. This avoids requiring a migration script on every deploy.

### Link scan UI notes

`public/link_scans.php` is URL-heavy. Keep open/detail actions styled with `.btn btn-small btn-open`, render long URLs in `.url-card` / `.url-link` blocks, and keep live recent links visually separated so scan results remain readable after large crawls.
