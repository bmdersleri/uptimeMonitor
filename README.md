# Ekont Uptime Monitor

Ekont Uptime Monitor is a small PHP/SQLite monitoring console for uptime checks, incident tracking, broken-link discovery, and notification retries.

Live site:
- https://www.kirbas.com/uptime

## Features

- Session-protected admin login/logout
- Dashboard with service status KPIs and monitor actions
- Monitor create, edit, activate/deactivate, archive/restore, and permanent delete
- Cron-based uptime checks
- Incident open/recovery tracking
- Email and Telegram notification infrastructure
- Notification settings and test-send screen at `/notifications.php`
- Notification retry queue and retry worker
- System health screen at `/health.php`
- Monitor detail page with recent checks, incidents, uptime trends, and link-scan summary
- Broken links screen at `/broken_links.php`
- Paginated broken-link list with status, monitor, HTTP code, search, and record-count filters
- Bulk cleanup for resolved broken-link records
- Broken-link ignore rules with contains, exact, and regex matching
- Link scan jobs screen at `/link_scans.php`
- Link scan job deletion from the jobs screen
- Paginated link scan job history with date and record-count filters
- Bulk cleanup for completed/failed link scan jobs
- Full authenticated link-scan data reset from the jobs screen
- Automatic stale running job closure
- Scan quality report for each selected link-scan job
- Dashboard link-scan job summary and stale job warning
- Manual link scan with live progress without refreshing the page
- Manual scans run in a detached worker on shared hosting when possible
- Shared-hosting-safe manual worker launch with shell/CGI argument fallback
- Live link-scan heartbeat, stalled-scan warning, and running-row progress updates
- Single active link-scan job policy to avoid SQLite write contention on shared hosting
- SQLite WAL mode and busy timeout for better scan/write reliability
- Per-monitor link scanning can be enabled or disabled
- Internal same-site crawling from the monitored URL
- Configurable link scan depth from monitor settings and from the manual scan screen
- Default link scan depth is `3`
- Parallel resource checks during link scans for faster broken-link detection
- Readable job detail UI with styled open actions and separated URL result rows

## Link Scanning Behavior

The link scanner starts from the monitor URL and scans resources found on that page. For page links (`<a href="...">`) that stay on the same host, it follows those pages up to the configured depth.

Examples:
- Depth `1`: scans the start page and follows/checks the first layer of same-site pages.
- Depth `3`: scans the start page and follows same-site pages up to three levels deep.

External hosts are ignored. Non-page resources such as images, scripts, stylesheets, and iframes are checked as resources, but only same-host page links are added to the crawl queue.

Resources discovered on the same page are checked in limited parallel batches. The default concurrency is `5`, and each resource check uses a shorter default timeout of `6` seconds. This speeds up broken-link detection without changing the cron interval or crawl depth.

Live scans write heartbeat metadata while fetching pages and while each resource batch starts or finishes. `/link_scans.php` uses this live state to update the active job row, progress bar, current source page, current target link, phase, and last-update age without a page refresh. If no heartbeat is received for `20` seconds, the scan is shown as possibly stalled; after `60` seconds it is shown as needing attention. The job is not auto-failed by these warnings, so the user can decide whether to wait, stop the scan, or start a new scan after stopping it.

Only one link-scan job is allowed to run at a time. This is intentional for the current SQLite/shared-hosting deployment: concurrent scans can lock the database and make otherwise healthy scans appear stuck. Status polling also throttles stale-job cleanup writes so the live progress endpoint does not compete with the scanner.

Broken-link ignore rules can be managed from `/broken_links.php`. Rules can match the target URL, the source page URL, or either side. Supported match types are `contains`, `exact`, and `regex`. Ignored links are removed from the active broken-link list and skipped during future scan persistence.

Each selected job on `/link_scans.php` includes a scan quality report with the most frequent broken targets, the source pages that contain the most distinct broken targets, and the status-code distribution for that job window.

The jobs screen also includes an authenticated full reset action. It clears `link_scan_jobs`, `discovered_links`, `broken_links`, and live scan state files while preserving monitors, uptime checks, incidents, notification history, users, and ignore rules. Use it when scan history needs a clean restart without rebuilding the whole application database.

Manual scans are launched through `LinkScanProcessLauncher` as a detached worker when the hosting environment supports shell execution. On shared hosting where `/usr/bin/php` may run as CGI/FastCGI instead of CLI, `cron/run_manual_link_scan.php` accepts only shell-provided arguments or environment values and still rejects browser-triggered HTTP execution.

## Operations Screens

- `/notifications.php` shows email and Telegram configuration status, masks sensitive values, provides test-send buttons, and lists recent notification delivery logs.
- `/health.php` shows runtime checks, PHP extension status, writable storage checks, cron log freshness, running/stale link-scan jobs, and notification retry queue health.

## SQLite Setup

1. Copy `.env.example` to `.env`.
2. Create the SQLite schema:

```bash
php database/init_sqlite.php
```

3. Run migrations, especially when updating an existing database:

```bash
php database/migrate_sqlite.php
```

4. Create the first admin user:

```bash
php database/create_admin.php
```

Alternative web setup:
- Set `FIRST_ADMIN_SETUP_TOKEN` in `.env`.
- Open `https://www.kirbas.com/uptime/setup_admin.php?token=YOUR_TOKEN`.
- Create the first admin user from the form.

## Production Deployment

Target URL:
- `https://www.kirbas.com/uptime`

Server project path:
- `/home2/cylcoinc/kirbas.com/uptime`

Recommended `.env` values:

```env
APP_URL=https://www.kirbas.com/uptime
APP_BASE_PATH=/uptime
DB_DRIVER=sqlite
DB_PATH=/home2/cylcoinc/kirbas.com/uptime/database/database.sqlite
DEFAULT_LINK_SCAN_MAX_DEPTH=3
DEFAULT_LINK_SCAN_CONCURRENCY=5
DEFAULT_LINK_SCAN_REQUEST_TIMEOUT_SECONDS=6
DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES=60
DEFAULT_LINK_SCAN_STALL_WARNING_SECONDS=20
DEFAULT_LINK_SCAN_STALL_ATTENTION_SECONDS=60
LINK_SCAN_BATCH_SIZE=5
```

Notes:
- All internal links and redirects are generated through `APP_BASE_PATH`.
- If the app path changes, update only `APP_BASE_PATH`.
- The repository includes root bridge files such as `index.php`, `login.php`, and `setup_admin.php`, so the app can work when the document root points directly to `/home2/cylcoinc/kirbas.com/uptime`.
- Endpoint bridge files and their `public/` counterparts must be deployed together, especially for link-scan actions such as run, status, delete, bulk cleanup, and reset.
- If you see `Index of /uptime/`, the bridge files or `.htaccess` may not have been uploaded yet.
- Keep `HTTP_SSL_VERIFY=true` in production. Use `HTTP_SSL_VERIFY=false` only for local certificate-chain troubleshooting.
- If manual scans appear to start but no job row is created, check `storage/logs/manual_link_scan_launch.log` first. A `403 Forbidden` entry usually means the hosting PHP binary is executing as CGI/FastCGI and the deployed `cron/run_manual_link_scan.php` is stale.

## Optional MySQL Setup

SQLite is the current supported deployment path. MySQL can be added later by setting `DB_DRIVER=mysql` and adapting `database/schema.sql` for MySQL-specific syntax.

## Local Development Server

```bash
php -S localhost:8080 -t public
```

When testing with `APP_BASE_PATH=/uptime`, either use the root bridge files or temporarily adjust the local base path.

## Manual Workers

Uptime worker:

```bash
php cron/check_uptime.php
```

Link scanner worker:

```bash
php cron/scan_links.php
```

Detached manual link scan worker:

```bash
php cron/run_manual_link_scan.php {monitor_id} {max_depth}
```

Notification retry worker:

```bash
php cron/retry_notifications.php
```

## Main Routes

- Dashboard: `/index.php`
- Login: `/login.php`
- First admin setup: `/setup_admin.php`
- Create monitor: `/monitors_create.php`
- Edit monitor: `/monitor_edit.php?id={monitor_id}`
- Monitor detail: `/monitor_detail.php?id={monitor_id}`
- Broken links: `/broken_links.php`
- Broken link ignore endpoint: `/broken_link_ignore.php` (POST)
- Broken link bulk cleanup endpoint: `/broken_link_bulk_delete.php` (POST)
- Link scan jobs: `/link_scans.php`
- Manual scan endpoint: `/link_scan_run.php` (POST)
- Live scan status endpoint: `/link_scan_status.php` (GET)
- Link scan delete endpoint: `/link_scan_delete.php` (POST)
- Link scan bulk cleanup endpoint: `/link_scan_bulk_delete.php` (POST)
- Link scan full reset endpoint: `/link_scan_reset.php` (POST)
- Retry queue: `/retry_queue.php`
- Notifications: `/notifications.php`
- System health: `/health.php`
- Logout: `/logout.php`

## Cron Examples

Run uptime checks every 5 minutes:

```cron
*/5 * * * * /usr/bin/php /home2/cylcoinc/kirbas.com/uptime/cron/check_uptime.php >> /home2/cylcoinc/kirbas.com/uptime/storage/logs/cron.log 2>&1
```

Run link scans every 6 hours:

```cron
0 */6 * * * /usr/bin/php /home2/cylcoinc/kirbas.com/uptime/cron/scan_links.php >> /home2/cylcoinc/kirbas.com/uptime/storage/logs/link_scan.log 2>&1
```

Run notification retries every 5 minutes:

```cron
*/5 * * * * /usr/bin/php /home2/cylcoinc/kirbas.com/uptime/cron/retry_notifications.php >> /home2/cylcoinc/kirbas.com/uptime/storage/logs/retry_notifications.log 2>&1
```

## Configuration

Key `.env` values:

```env
DEFAULT_UPTIME_INTERVAL_SECONDS=300
DEFAULT_TIMEOUT_SECONDS=10
DEFAULT_FAIL_THRESHOLD=3
DEFAULT_RECOVERY_THRESHOLD=2
DEFAULT_RESPONSE_WARNING_MS=3000
DEFAULT_LINK_SCAN_INTERVAL_SECONDS=21600
DEFAULT_LINK_SCAN_MAX_DEPTH=3
DEFAULT_LINK_SCAN_MAX_URLS=120
DEFAULT_LINK_SCAN_CONCURRENCY=5
DEFAULT_LINK_SCAN_REQUEST_TIMEOUT_SECONDS=6
DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES=60
DEFAULT_LINK_SCAN_STALL_WARNING_SECONDS=20
DEFAULT_LINK_SCAN_STALL_ATTENTION_SECONDS=60
LINK_SCAN_BATCH_SIZE=5
```

Notification values:

```env
NOTIFY_EMAIL_ENABLED=false
NOTIFY_EMAIL_TO=
NOTIFY_TELEGRAM_ENABLED=false
TELEGRAM_BOT_TOKEN=
TELEGRAM_DEFAULT_CHAT_ID=
NOTIFY_RETRY_BATCH_SIZE=30
```

Broken-link notification throttling is configured in `config/notifications.php`.

## File Structure

- `app/`: core application classes, repositories, and services
- `config/`: app, dashboard, and notification settings
- `cron/`: background workers
- `database/schema.sql`: database schema
- `database/init_sqlite.php`: SQLite initializer
- `database/migrate_sqlite.php`: SQLite migration script
- `database/create_admin.php`: admin user creation script
- `public/`: web UI and HTTP endpoints
- `tests/`: lightweight PHP behavior tests
