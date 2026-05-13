PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS monitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    expected_status TEXT NOT NULL DEFAULT '200,301,302',
    interval_seconds INTEGER NOT NULL DEFAULT 300,
    timeout_seconds INTEGER NOT NULL DEFAULT 10,
    response_warning_ms INTEGER NOT NULL DEFAULT 3000,
    fail_threshold INTEGER NOT NULL DEFAULT 3,
    recovery_threshold INTEGER NOT NULL DEFAULT 2,
    is_active INTEGER NOT NULL DEFAULT 1,
    current_status TEXT NOT NULL DEFAULT 'unknown' CHECK (current_status IN ('unknown','up','down','degraded')),
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    consecutive_successes INTEGER NOT NULL DEFAULT 0,
    last_check_at TEXT NULL,
    next_check_at TEXT NULL,
    link_scan_enabled INTEGER NOT NULL DEFAULT 1,
    link_scan_interval_seconds INTEGER NOT NULL DEFAULT 21600,
    link_scan_max_depth INTEGER NOT NULL DEFAULT 3,
    link_scan_max_urls INTEGER NOT NULL DEFAULT 120,
    last_link_scan_at TEXT NULL,
    next_link_scan_at TEXT NULL,
    archived_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status TEXT NOT NULL CHECK (status IN ('up','down','degraded')),
    http_code INTEGER NULL,
    response_time_ms INTEGER NULL,
    error_message TEXT NULL,
    final_url TEXT NULL,
    FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_checks_monitor_time ON checks (monitor_id, checked_at);

CREATE TABLE IF NOT EXISTS incidents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    resolved_at TEXT NULL,
    duration_seconds INTEGER NULL,
    reason TEXT NULL,
    last_error TEXT NULL,
    notification_sent INTEGER NOT NULL DEFAULT 0,
    recovery_notification_sent INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL,
    FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_incidents_monitor_time ON incidents (monitor_id, started_at);

CREATE TABLE IF NOT EXISTS notification_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    incident_id INTEGER NULL,
    event_type TEXT NOT NULL,
    channel TEXT NOT NULL CHECK (channel IN ('email','telegram')),
    status TEXT NOT NULL CHECK (status IN ('sent','failed')),
    error_message TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_notification_logs_monitor_time ON notification_logs (monitor_id, created_at);

CREATE TABLE IF NOT EXISTS notification_retry_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel TEXT NOT NULL CHECK (channel IN ('email','telegram')),
    payload_json TEXT NOT NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    status TEXT NOT NULL CHECK (status IN ('pending','retrying','sent','failed')) DEFAULT 'pending',
    next_attempt_at TEXT NOT NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NULL,
    sent_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_notification_retry_queue_status_time ON notification_retry_queue (status, next_attempt_at);

CREATE TABLE IF NOT EXISTS report_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_type TEXT NOT NULL CHECK (report_type IN ('daily','weekly')),
    period_start TEXT NOT NULL,
    period_end TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    html_body TEXT NULL,
    email_status TEXT NOT NULL DEFAULT 'skipped',
    telegram_status TEXT NOT NULL DEFAULT 'skipped',
    email_error TEXT NULL,
    telegram_error TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_report_runs_type_time ON report_runs (report_type, created_at);

CREATE TABLE IF NOT EXISTS link_scan_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    status TEXT NOT NULL CHECK (status IN ('running','completed','failed')) DEFAULT 'running',
    total_urls INTEGER NOT NULL DEFAULT 0,
    checked_urls INTEGER NOT NULL DEFAULT 0,
    broken_urls INTEGER NOT NULL DEFAULT 0,
    duration_seconds INTEGER NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_link_scan_jobs_monitor_time ON link_scan_jobs (monitor_id, started_at);

CREATE TABLE IF NOT EXISTS discovered_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    source_url TEXT NOT NULL,
    target_url TEXT NOT NULL,
    link_type TEXT NOT NULL DEFAULT 'other',
    last_checked_at TEXT NULL,
    last_status_code INTEGER NULL,
    last_status TEXT NOT NULL DEFAULT 'unknown' CHECK (last_status IN ('unknown','ok','broken','warning')),
    last_error TEXT NULL,
    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NULL,
    FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE,
    UNIQUE (monitor_id, source_url, target_url)
);
CREATE INDEX IF NOT EXISTS idx_discovered_links_monitor_status ON discovered_links (monitor_id, last_status);

CREATE TABLE IF NOT EXISTS broken_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id INTEGER NOT NULL,
    source_url TEXT NOT NULL,
    target_url TEXT NOT NULL,
    status_code INTEGER NULL,
    error_type TEXT NULL,
    error_message TEXT NULL,
    first_detected_at TEXT NOT NULL,
    last_detected_at TEXT NOT NULL,
    resolved_at TEXT NULL,
    ignored_at TEXT NULL,
    ignored_reason TEXT NULL,
    occurrence_count INTEGER NOT NULL DEFAULT 1,
    notification_sent INTEGER NOT NULL DEFAULT 0,
    recovery_notification_sent INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_broken_links_monitor_target ON broken_links (monitor_id, target_url);

CREATE TABLE IF NOT EXISTS broken_link_ignore_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern TEXT NOT NULL,
    match_type TEXT NOT NULL DEFAULT 'contains' CHECK (match_type IN ('contains','exact','regex')),
    scope TEXT NOT NULL DEFAULT 'target' CHECK (scope IN ('target','source','either')),
    note TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_broken_link_ignore_rules_active ON broken_link_ignore_rules (is_active);
