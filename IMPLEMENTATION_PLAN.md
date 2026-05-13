# Ekont Uptime Monitor Implementation Plan

## 1. Current Status

The MVP is implemented as a plain PHP application with SQLite storage, protected admin pages, cron workers, and operational dashboards.

Completed:
- Project structure: `app/`, `public/`, `cron/`, `database/`, `config/`, and `storage/`
- `.env` configuration loader
- File-based configuration groups in `config/`
- Subdirectory-safe URL generation with `APP_BASE_PATH=/uptime`
- Root bridge files for shared-hosting deployment
- SQLite schema and migration scripts
- Session-based authentication
- Web and CLI first-admin setup
- Uptime worker and check history
- Incident open/recovery tracking
- Email and Telegram notification infrastructure
- Notification logs and retry queue
- Notification settings and test-send screen
- System health screen for runtime, cron, queue, and storage checks
- Modern dashboard with status KPIs
- Monitor create/edit/detail flows
- Monitor activation, deactivation, archive/restore, and permanent delete
- Broken links screen
- Paginated broken-link list with filters and resolved-record cleanup
- Broken-link ignore rules with target/source/either matching
- Link scan jobs screen with live progress
- Link scan job deletion from the jobs screen
- Paginated link scan job history with date and record-count filters
- Bulk cleanup for completed/failed link scan jobs
- Automatic stale running job closure
- Manual link scan trigger
- Per-monitor link scan enable/disable
- Per-monitor and manual scan depth control
- Same-site internal page crawling for link scans
- Limited parallel resource checks for faster broken-link detection
- Link scan quality reports on job and monitor detail screens
- Monitor detail link scan and broken resource summaries

Live deployment target:
- https://www.kirbas.com/uptime

## 2. Active Architecture Decisions

- Runtime: PHP without a framework.
- Database: SQLite.
- Production database path: `/home2/cylcoinc/kirbas.com/uptime/database/database.sqlite`.
- Public base path: `/uptime`.
- Production URL: `https://www.kirbas.com/uptime`.
- Production SSL verification stays enabled with `HTTP_SSL_VERIFY=true`.
- Link scans are opt-in per monitor through `link_scan_enabled`.
- Default link scan depth is `3`.
- Default link scan resource-check concurrency is `5`.
- Default per-resource link scan request timeout is `6` seconds.
- Link scan jobs still marked running after `60` minutes are closed as failed automatically.
- Manual scans can override depth for that run without changing the saved monitor setting.
- Broken-link ignore rules are evaluated during scan result persistence, so ignored links do not re-enter the active broken-link queue.

## 3. Link Scan Design

The link scanner starts at the monitor URL and extracts:
- Page links from `<a href="...">`
- Images from `<img src="...">`
- Scripts from `<script src="...">`
- Stylesheets from `<link href="...">`
- Frames from `<iframe src="...">`

Only URLs on the same host as the monitor URL are scanned. External hosts are ignored to keep the job bounded and to avoid scanning unrelated websites.

Page links are queued for crawling when:
- the link type is `page`,
- the URL is on the same host,
- the current depth is lower than the configured max depth,
- the page has not already been visited.

Resources are checked with `HEAD` first and then `GET` as a fallback. Resource checks discovered on the same page are processed in bounded parallel batches controlled by `DEFAULT_LINK_SCAN_CONCURRENCY`, with per-resource timeout controlled by `DEFAULT_LINK_SCAN_REQUEST_TIMEOUT_SECONDS`. Broken resources are persisted in `broken_links`, and scan jobs are persisted in `link_scan_jobs`.

Ignored broken-link rules are stored in `broken_link_ignore_rules`. They support `contains`, `exact`, and `regex` matching against the target URL, source URL, or either value. When an active broken link is ignored from the UI, an exact target rule is created and the current row is marked resolved/ignored.

Each selected job exposes a quality report derived from `broken_links` within the job time window:
- top broken targets by hit count,
- source pages by distinct broken target count,
- status-code distribution.

## 4. Sprint Status

### Sprint 1: Authentication and Core Dashboard

Completed:
- Login/logout
- Session guard through `require_auth()`
- Dashboard KPI cards
- Active monitor table
- Critical monitor panel

### Sprint 2: Uptime Checks and Notifications

Completed:
- Uptime check worker
- Check history storage
- Incident open/recovery logic
- Email and Telegram notification plumbing
- Notification log table

### Sprint 3: Monitor Detail and Reporting

Completed:
- Monitor detail page
- Recent checks table
- Incident history
- 24-hour uptime trend
- 7-day uptime distribution
- 24-hour vs 7-day comparison

### Sprint 4: Broken Links and Retry Operations

Completed:
- Link scanner worker
- Link scan jobs table
- Live scan status endpoint
- Job deletion endpoint for scan history cleanup
- Scan quality report for selected jobs
- Job pagination, date filters, and bulk cleanup
- Stale running job cleanup on status checks and manual scan start
- Manual scan endpoint
- Manual scan depth override
- Same-site internal page crawl support
- Parallel resource checks within each scanned page
- Broken links table and management screen
- Broken-link ignore rule management screen and endpoint
- Broken-link pagination, source-page visibility, and resolved cleanup
- Broken link summary notifications with throttling
- Notification retry queue
- Retry queue admin screen
- Manual retry and bulk retry

### Sprint 5: Monitor Lifecycle Management

Completed:
- Activate/deactivate monitor
- Archive/restore monitor
- Permanent monitor delete
- Dashboard archive view
- Cascading cleanup through database foreign keys

### Sprint 6: Operational Controls and Scan Quality

Completed:
- Notification settings/status page with email and Telegram test actions
- System health page with runtime, extension, storage, cron log, job, and retry queue checks
- Broken-link ignore rule repository, schema, endpoint, and UI controls
- Scan quality report repository methods and UI panels
- Monitor detail quality summary for the latest link scan

## 5. MVP Completion Criteria

The MVP is considered complete when:
- Admin login protects all operational pages.
- Monitors can be created, edited, activated/deactivated, archived/restored, and deleted.
- Cron uptime checks run reliably.
- Incident open/recovery behavior is verified.
- Dashboard status reflects current service health.
- At least one notification channel can be configured for production use.
- Link scans can be run automatically and manually.
- Link scans crawl same-site internal pages up to the configured depth.
- Broken resources are visible from the broken links and link scan job screens.
- Ignored broken links do not keep cluttering active broken-link lists.
- Notification and health pages expose production readiness checks.
- Retry queue can recover failed notification attempts.

## 6. Operational Checklist

Before or after deployment to `https://www.kirbas.com/uptime`:

1. Upload application files to `/home2/cylcoinc/kirbas.com/uptime`.
2. Confirm `.env` contains:

```env
APP_URL=https://www.kirbas.com/uptime
APP_BASE_PATH=/uptime
DB_DRIVER=sqlite
DB_PATH=/home2/cylcoinc/kirbas.com/uptime/database/database.sqlite
HTTP_SSL_VERIFY=true
DEFAULT_LINK_SCAN_MAX_DEPTH=3
DEFAULT_LINK_SCAN_CONCURRENCY=5
DEFAULT_LINK_SCAN_REQUEST_TIMEOUT_SECONDS=6
DEFAULT_LINK_SCAN_STALE_AFTER_MINUTES=60
```

3. Run:

```bash
php database/migrate_sqlite.php
```

4. Confirm the admin account exists.
5. Configure cron jobs for:
   - `cron/check_uptime.php`
   - `cron/scan_links.php`
   - `cron/retry_notifications.php`
6. Open `https://www.kirbas.com/uptime`.
7. Confirm dashboard login, monitor list, link scans, broken links, and retry queue pages load.
8. Open `/health.php` and confirm storage, PHP extensions, cron logs, and job health checks.
9. Open `/notifications.php` and send test messages for the enabled channels.

## 7. Next Improvements

Recommended future work:
- Add CSRF tokens to monitor action forms.
- Store scan depth on each `link_scan_jobs` row for historical audit.
- Add richer trend charts for scan quality over time.
- Add role-based access if multiple admin types are needed.
- Add MySQL-compatible schema if SQLite is no longer sufficient.
- Add deployment automation for the live host.
