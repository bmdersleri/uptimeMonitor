<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

function broken_links_ensure_ignore_columns(PDO $pdo): void
{
    try {
        $columns = $pdo->query("PRAGMA table_info(broken_links)")->fetchAll();
        $names = [];
        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name'])) {
                $names[(string) $column['name']] = true;
            }
        }
        if (!isset($names['ignored_at'])) {
            $pdo->exec("ALTER TABLE broken_links ADD COLUMN ignored_at TEXT NULL");
        }
        if (!isset($names['ignored_reason'])) {
            $pdo->exec("ALTER TABLE broken_links ADD COLUMN ignored_reason TEXT NULL");
        }
    } catch (Throwable $e) {
    }
}

/**
 * @param array<string, mixed> $filters
 * @return array{where: array<int, string>, params: array<string, mixed>}
 */
function broken_links_filter_sql(array $filters): array
{
    $where = [];
    $params = [];

    if (isset($filters['monitor_id']) && (int) $filters['monitor_id'] > 0) {
        $where[] = 'b.monitor_id = :monitor_id';
        $params['monitor_id'] = (int) $filters['monitor_id'];
    }

    $status = isset($filters['status']) ? (string) $filters['status'] : 'active';
    if ($status === 'resolved') {
        $where[] = 'b.resolved_at IS NOT NULL';
    } else {
        $where[] = 'b.resolved_at IS NULL';
        $where[] = 'b.ignored_at IS NULL';
    }

    if (isset($filters['code']) && (string) $filters['code'] !== '') {
        $where[] = 'b.status_code = :status_code';
        $params['status_code'] = (int) $filters['code'];
    }

    if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
        $where[] = '(b.target_url LIKE :q OR b.source_url LIKE :q OR b.error_message LIKE :q)';
        $params['q'] = '%' . trim((string) $filters['q']) . '%';
    }

    return ['where' => $where, 'params' => $params];
}

/**
 * @param array<string, mixed> $params
 */
function broken_links_bind_params(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        if ($key === 'monitor_id' || $key === 'status_code') {
            $stmt->bindValue(':' . $key, (int) $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':' . $key, (string) $value);
        }
    }
}

/**
 * @param array<string, mixed> $filters
 */
function broken_links_count(PDO $pdo, array $filters): int
{
    $query = broken_links_filter_sql($filters);
    $sql = "
        SELECT COUNT(*) AS total
        FROM broken_links b
        INNER JOIN monitors m ON m.id = b.monitor_id
    ";
    if ($query['where'] !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $query['where']);
    }

    $stmt = $pdo->prepare($sql);
    broken_links_bind_params($stmt, $query['params']);
    $stmt->execute();
    $row = $stmt->fetch();
    return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
}

/**
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function broken_links_list(PDO $pdo, array $filters, int $limit, int $offset): array
{
    $query = broken_links_filter_sql($filters);
    $sql = "
        SELECT
            b.*,
            m.name AS monitor_name,
            m.url AS monitor_url
        FROM broken_links b
        INNER JOIN monitors m ON m.id = b.monitor_id
    ";
    if ($query['where'] !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $query['where']);
    }
    $sql .= ' ORDER BY b.last_detected_at DESC, b.id DESC LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    broken_links_bind_params($stmt, $query['params']);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

$pdo = Database::connection();
$monitorRepo = new MonitorRepository($pdo);
$brokenRepo = new BrokenLinkRepository($pdo);
$ignoreRepo = new BrokenLinkIgnoreRuleRepository($pdo);
broken_links_ensure_ignore_columns($pdo);
$user = current_user();
$brandTitle = (string) config('app.brand.title', config('APP_NAME', 'Uptime Monitor'));

$status = isset($_GET['status']) ? (string) $_GET['status'] : 'active';
if ($status !== 'resolved') {
    $status = 'active';
}
$monitorId = isset($_GET['monitor_id']) ? (int) $_GET['monitor_id'] : 0;
$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 50;
if (!in_array($perPage, [25, 50, 100, 300], true)) {
    $perPage = 50;
}
$page = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
$offset = ($page - 1) * $perPage;

$loadError = null;
$rows = [];
$counts = ['active_count' => 0, 'resolved_count' => 0];
$ignoreRules = [];
$totalRows = 0;
$totalPages = 1;
try {
    $filters = [
        'status' => $status,
        'monitor_id' => $monitorId,
        'code' => $code,
        'q' => $q,
    ];
    $totalRows = broken_links_count($pdo, $filters);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
    $rows = broken_links_list($pdo, $filters, $perPage, $offset);
    $counts = $brokenRepo->quickCounts();
    $ignoreRules = $ignoreRepo->all();
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}
$monitors = $monitorRepo->all();
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broken Links • <?= e($brandTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: #0ea5e9;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --text: #0f172a;
            --muted: #475569;
            --card: rgba(255, 255, 255, 0.84);
            --line: rgba(148, 163, 184, 0.35);
            --bg1: #f8fafc;
            --bg2: #ecfeff;
        }
        html[data-theme="dark"] {
            --text: #e2e8f0;
            --muted: #94a3b8;
            --card: rgba(15, 23, 42, 0.84);
            --line: rgba(71, 85, 105, 0.5);
            --bg1: #0b1220;
            --bg2: #111827;
        }
        * { box-sizing:border-box; }
        body {
            margin:0;
            min-height:100vh;
            color:var(--text);
            font-family:"IBM Plex Sans","Segoe UI",sans-serif;
            background:
                radial-gradient(circle at 8% 10%, rgba(14,165,233,0.16), transparent 32%),
                radial-gradient(circle at 92% 18%, rgba(16,185,129,0.14), transparent 30%),
                linear-gradient(160deg, var(--bg1), var(--bg2));
        }
        .shell { width:min(1200px, 94vw); margin:24px auto 36px; }
        .topbar {
            background:var(--card);
            border:1px solid var(--line);
            border-radius:22px;
            padding:18px 20px;
            display:flex;
            gap:16px;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            backdrop-filter:blur(8px);
            box-shadow:0 14px 40px rgba(15,23,42,0.06);
        }
        .title {
            margin:0;
            font-family:"Space Grotesk",sans-serif;
            font-size:clamp(1.15rem,2.7vw,1.85rem);
        }
        .subtitle { margin:4px 0 0; color:var(--muted); font-size:0.93rem; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; }
        .btn {
            text-decoration:none;
            padding:10px 14px;
            border-radius:12px;
            font-weight:700;
            border:1px solid var(--line);
            font-size:0.92rem;
            cursor:pointer;
            color:var(--text);
            background:#ffffffc9;
        }
        .btn-primary {
            color:#fff;
            border-color:transparent;
            background:linear-gradient(135deg,var(--accent),#0284c7);
            box-shadow:0 8px 20px rgba(14,165,233,0.24);
        }
        .btn-danger {
            color:#7f1d1d;
            border-color:rgba(239,68,68,0.35);
            background:rgba(254,226,226,0.82);
        }
        html[data-theme="dark"] .btn {
            color:#e2e8f0;
            border-color:rgba(148,163,184,0.34);
            background:rgba(30,41,59,0.92);
        }
        html[data-theme="dark"] .btn-primary {
            color:#fff;
            border-color:transparent;
            background:linear-gradient(135deg,#0ea5e9,#0284c7);
        }
        html[data-theme="dark"] .btn-danger {
            color:#fecaca;
            border-color:rgba(248,113,113,0.48);
            background:rgba(127,29,29,0.82);
        }
        .grid { margin-top:14px; display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
        .card, .panel {
            background:var(--card);
            border:1px solid var(--line);
            border-radius:16px;
            padding:14px;
            box-shadow:0 10px 26px rgba(15,23,42,0.05);
        }
        .panel { margin-top:12px; overflow:hidden; }
        .k { color:var(--muted); font-size:0.82rem; }
        .v { margin-top:6px; font-size:1.5rem; font-weight:700; font-family:"Space Grotesk",sans-serif; }
        .v-danger { color:var(--danger); }
        .v-success { color:var(--success); }
        .v-accent { color:var(--accent); }
        .filters { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; align-items:end; }
        .toolbar-row {
            margin-top:12px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            flex-wrap:wrap;
        }
        .inline-tools {
            display:flex;
            align-items:end;
            gap:8px;
            flex-wrap:wrap;
        }
        .inline-tools select { min-width:220px; }
        .pagination {
            display:flex;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }
        .pagination .btn[aria-disabled="true"] {
            opacity:0.5;
            pointer-events:none;
        }
        label { display:block; margin:0 0 6px; color:var(--muted); font-size:0.82rem; font-weight:700; }
        input, select {
            width:100%;
            padding:10px 11px;
            border:1px solid var(--line);
            border-radius:11px;
            background:rgba(255,255,255,0.9);
            color:var(--text);
            font:inherit;
        }
        .rule-form { display:grid; grid-template-columns:1.3fr 130px 130px 1fr auto; gap:10px; align-items:end; }
        .rules-list { margin-top:12px; display:grid; gap:8px; }
        .rule-item { display:flex; align-items:center; justify-content:space-between; gap:10px; border:1px solid var(--line); border-radius:12px; padding:10px; }
        .row-actions { display:flex; gap:6px; flex-wrap:wrap; }
        .btn-mini { padding:7px 9px; border-radius:9px; font-size:0.78rem; }
        html[data-theme="dark"] input,
        html[data-theme="dark"] select {
            background:rgba(15,23,42,0.75);
            color:#e2e8f0;
        }
        table { width:100%; border-collapse:collapse; font-size:0.9rem; }
        th, td { border-bottom:1px solid var(--line); padding:11px 12px; text-align:left; vertical-align:top; }
        th { font-size:0.76rem; text-transform:uppercase; color:#334155; letter-spacing:0.45px; background:rgba(148,163,184,0.08); }
        html[data-theme="dark"] th { color:#cbd5e1; background:rgba(148,163,184,0.12); }
        tbody tr:hover { background:rgba(14,165,233,0.07); }
        .table-link { color:var(--text); text-decoration:none; font-weight:700; }
        .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:0.8rem; word-break:break-all; }
        .badge { display:inline-flex; padding:4px 9px; border-radius:999px; font-size:0.75rem; font-weight:800; letter-spacing:0.25px; }
        .active { color:#7f1d1d; background:rgba(239,68,68,0.16); border:1px solid rgba(239,68,68,0.28); }
        .resolved { color:#065f46; background:rgba(16,185,129,0.18); border:1px solid rgba(16,185,129,0.28); }
        html[data-theme="dark"] .active { color:#fecaca; background:rgba(127,29,29,0.68); }
        html[data-theme="dark"] .resolved { color:#bbf7d0; background:rgba(6,95,70,0.55); }
        .muted { color:var(--muted); }
        .alert {
            margin-bottom:10px;
            padding:10px;
            border:1px solid rgba(239,68,68,0.32);
            background:rgba(254,226,226,0.72);
            color:#7f1d1d;
            border-radius:12px;
        }
        html[data-theme="dark"] .alert { color:#fecaca; background:rgba(127,29,29,0.5); }
        .notice {
            margin-top:10px;
            border-radius:12px;
            padding:10px 11px;
            display:none;
            font-size:0.84rem;
        }
        .notice.ok { background:rgba(16,185,129,0.14); border:1px solid rgba(16,185,129,0.32); color:#065f46; }
        .notice.err { background:rgba(239,68,68,0.14); border:1px solid rgba(239,68,68,0.32); color:#7f1d1d; }
        html[data-theme="dark"] .notice.ok { color:#bbf7d0; }
        html[data-theme="dark"] .notice.err { color:#fecaca; }
        @media (max-width: 1020px) { .filters, .rule-form { grid-template-columns:repeat(2,minmax(0,1fr)); } .grid { grid-template-columns:1fr; } }
        @media (max-width: 700px) { th:nth-child(2),td:nth-child(2),th:nth-child(6),td:nth-child(6) { display:none; } }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <div>
                <h1 class="title">Broken Links</h1>
                <p class="subtitle">Broken resource kayıtları ve tarama kaynakları</p>
            </div>
            <div class="actions">
                <span class="btn"><?= e((string) ($user['email'] ?? '-')); ?></span>
                <a class="btn" href="<?= e(url_for('/index.php')); ?>">Dashboard</a>
                <a class="btn" href="<?= e(url_for('/link_scans.php')); ?>">Link Scans</a>
                <a class="btn" href="<?= e(url_for('/notifications.php')); ?>">Notifications</a>
                <a class="btn" href="<?= e(url_for('/health.php')); ?>">Health</a>
                <button class="btn" id="theme-toggle" type="button">Tema</button>
                <a class="btn" href="<?= e(url_for('/logout.php')); ?>">Çıkış</a>
            </div>
        </header>

        <section class="grid">
            <article class="card">
                <div class="k">Aktif Broken Link</div>
                <div class="v v-danger"><?= (int) $counts['active_count']; ?></div>
            </article>
            <article class="card">
                <div class="k">Resolved Broken Link</div>
                <div class="v v-success"><?= (int) $counts['resolved_count']; ?></div>
            </article>
            <article class="card">
                <div class="k">Listelenen Sonuç</div>
                <div class="v v-accent"><?= count($rows); ?></div>
            </article>
        </section>

        <section class="panel">
            <?php if ($loadError !== null): ?>
                <div class="alert">
                    Broken link tabloları hazır değil. `php database/migrate_sqlite.php` çalıştırın.
                </div>
            <?php endif; ?>
            <form method="get" class="filters">
                <input type="hidden" name="page" value="1">
                <div>
                    <label>Durum</label>
                    <select name="status">
                        <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="resolved" <?= $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    </select>
                </div>
                <div>
                    <label>Monitör</label>
                    <select name="monitor_id">
                        <option value="0">Hepsi</option>
                        <?php foreach ($monitors as $m): ?>
                            <option value="<?= (int) $m['id']; ?>" <?= $monitorId === (int) $m['id'] ? 'selected' : ''; ?>>
                                <?= e((string) $m['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>HTTP Kod</label>
                    <input name="code" value="<?= e($code); ?>" placeholder="404">
                </div>
                <div>
                    <label>Arama</label>
                    <input name="q" value="<?= e($q); ?>" placeholder="url veya error">
                </div>
                <div>
                    <label>Kayıt</label>
                    <select name="per_page">
                        <option value="25" <?= $perPage === 25 ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?= $perPage === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?= $perPage === 100 ? 'selected' : ''; ?>>100</option>
                        <option value="300" <?= $perPage === 300 ? 'selected' : ''; ?>>300</option>
                    </select>
                </div>
                <div>
                    <button class="btn btn-primary" type="submit">Filtrele</button>
                </div>
            </form>
            <div id="bulk-notice" class="notice"></div>
            <div class="toolbar-row">
                <div class="muted">
                    <?= (int) $totalRows; ?> kayıt bulundu • Sayfa <?= (int) $page; ?>/<?= (int) $totalPages; ?>
                </div>
                <div class="inline-tools">
                    <div>
                        <label>Resolved temizlik</label>
                        <select id="resolved-retention">
                            <option value="30">30 günden eski resolved</option>
                            <option value="7">7 günden eski resolved</option>
                            <option value="90">90 günden eski resolved</option>
                            <option value="180">180 günden eski resolved</option>
                            <option value="365">365 günden eski resolved</option>
                            <option value="0">Tüm resolved kayıtlar</option>
                        </select>
                    </div>
                    <button class="btn btn-danger" type="button" id="resolved-clean-btn">Temizle</button>
                </div>
            </div>
        </section>

        <section class="panel">
            <h2 style="margin:0 0 10px;font-size:1rem;">Ignore Rules</h2>
            <form class="rule-form" id="ignore-rule-form">
                <div>
                    <label>Pattern</label>
                    <input name="pattern" placeholder="/wp-json/ veya tam URL" required>
                </div>
                <div>
                    <label>Match</label>
                    <select name="match_type">
                        <option value="contains">Contains</option>
                        <option value="exact">Exact</option>
                        <option value="regex">Regex</option>
                    </select>
                </div>
                <div>
                    <label>Scope</label>
                    <select name="scope">
                        <option value="target">Target</option>
                        <option value="source">Source</option>
                        <option value="either">Either</option>
                    </select>
                </div>
                <div>
                    <label>Note</label>
                    <input name="note" placeholder="Neden ignore ediliyor?">
                </div>
                <button class="btn btn-primary" type="submit">Ekle</button>
            </form>
            <div id="ignore-notice" class="notice"></div>
            <div class="rules-list">
                <?php foreach ($ignoreRules as $rule): ?>
                    <div class="rule-item">
                        <div>
                            <strong class="mono"><?= e((string) $rule['pattern']); ?></strong>
                            <div class="muted"><?= e((string) $rule['match_type']); ?> • <?= e((string) $rule['scope']); ?><?= trim((string) ($rule['note'] ?? '')) !== '' ? ' • ' . e((string) $rule['note']) : ''; ?></div>
                        </div>
                        <button class="btn btn-danger btn-mini js-delete-rule" type="button" data-rule-id="<?= (int) $rule['id']; ?>">Sil</button>
                    </div>
                <?php endforeach; ?>
                <?php if ($ignoreRules === []): ?>
                    <div class="muted">Henüz ignore kuralı yok.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Monitor</th>
                        <th>Broken Target</th>
                        <th>Found On</th>
                        <th>Code</th>
                        <th>Occur.</th>
                        <th>First / Last</th>
                        <th>Status</th>
                        <th>Error</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $isActive = ($r['resolved_at'] ?? null) === null; ?>
                        <tr>
                            <td>
                                <a class="table-link" href="<?= e(url_for('/monitor_detail.php', ['id' => (int) $r['monitor_id']])); ?>">
                                    <?= e((string) $r['monitor_name']); ?>
                                </a>
                            </td>
                            <td>
                                <div class="mono"><?= e((string) $r['target_url']); ?></div>
                                <div class="muted"><a href="<?= e((string) $r['target_url']); ?>" target="_blank" rel="noopener" style="color:inherit;">hedefi aç</a></div>
                            </td>
                            <td>
                                <div class="mono"><?= e((string) $r['source_url']); ?></div>
                                <div class="muted"><a href="<?= e((string) $r['source_url']); ?>" target="_blank" rel="noopener" style="color:inherit;">kaynak sayfayı aç</a></div>
                            </td>
                            <td><?= $r['status_code'] !== null ? (int) $r['status_code'] : '-'; ?></td>
                            <td><?= (int) $r['occurrence_count']; ?></td>
                            <td class="muted">
                                <?= e((string) $r['first_detected_at']); ?><br>
                                <?= e((string) $r['last_detected_at']); ?>
                            </td>
                            <td>
                                <span class="badge <?= $isActive ? 'active' : 'resolved'; ?>">
                                    <?= $isActive ? 'ACTIVE' : 'RESOLVED'; ?>
                                </span>
                            </td>
                            <td class="muted"><?= e((string) ($r['error_message'] ?? '-')); ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <div class="row-actions">
                                        <button class="btn btn-danger btn-mini js-ignore-link" type="button" data-broken-id="<?= (int) $r['id']; ?>">Ignore</button>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="9">Filtreye uygun broken link kaydı yok.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="toolbar-row">
                <div class="muted">
                    <?= (int) $offset + ($rows === [] ? 0 : 1); ?>-<?= (int) min($offset + count($rows), $totalRows); ?> / <?= (int) $totalRows; ?>
                </div>
                <div class="pagination">
                    <?php
                    $basePageParams = [
                        'status' => $status,
                        'monitor_id' => $monitorId,
                        'code' => $code,
                        'q' => $q,
                        'per_page' => $perPage,
                    ];
                    ?>
                    <a class="btn" aria-disabled="<?= $page <= 1 ? 'true' : 'false'; ?>" href="<?= e(url_for('/broken_links.php', $basePageParams + ['page' => max(1, $page - 1)])); ?>">Önceki</a>
                    <span class="muted">Sayfa <?= (int) $page; ?> / <?= (int) $totalPages; ?></span>
                    <a class="btn" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false'; ?>" href="<?= e(url_for('/broken_links.php', $basePageParams + ['page' => min($totalPages, $page + 1)])); ?>">Sonraki</a>
                </div>
            </div>
        </section>
    </div>
    <script>
        (function () {
            var key = 'ui-theme';
            var root = document.documentElement;
            var btn = document.getElementById('theme-toggle');
            if (!btn) { return; }
            var saved = localStorage.getItem(key);
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var theme = saved || (prefersDark ? 'dark' : 'light');
            root.setAttribute('data-theme', theme);
            btn.textContent = theme === 'dark' ? 'Light' : 'Dark';
            btn.addEventListener('click', function () {
                theme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', theme);
                localStorage.setItem(key, theme);
                btn.textContent = theme === 'dark' ? 'Light' : 'Dark';
            });
        })();

        (function () {
            var cleanBtn = document.getElementById('resolved-clean-btn');
            var retention = document.getElementById('resolved-retention');
            var notice = document.getElementById('bulk-notice');
            var bulkUrl = <?= json_encode(url_for('/broken_link_bulk_delete.php')); ?>;
            var bulkFallbackUrl = <?= json_encode(url_for('/public/broken_link_bulk_delete.php')); ?>;
            var ignoreUrl = <?= json_encode(url_for('/broken_link_ignore.php')); ?>;
            var ignoreFallbackUrl = <?= json_encode(url_for('/public/broken_link_ignore.php')); ?>;

            function setNotice(type, text) {
                if (!notice) { return; }
                notice.className = 'notice ' + (type === 'ok' ? 'ok' : 'err');
                notice.style.display = 'block';
                notice.textContent = text;
            }

            function postJson(urls, body) {
                var index = 0;

                function tryNext(lastMessage) {
                    if (index >= urls.length) {
                        throw new Error(lastMessage || 'Sunucu JSON yanıtı döndürmedi.');
                    }

                    var url = urls[index++];
                    return fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    }).then(function (r) {
                        return r.text().then(function (text) {
                            var data = null;
                            try {
                                data = text ? JSON.parse(text) : null;
                            } catch (e) {
                                if (index < urls.length) {
                                    return tryNext('Endpoint JSON yerine farklı yanıt döndürdü.');
                                }
                                throw new Error('Endpoint JSON yerine farklı yanıt döndürdü. HTTP ' + r.status);
                            }

                            if (!r.ok) {
                                throw new Error((data && data.message) ? data.message : ('HTTP ' + r.status));
                            }

                            return data;
                        });
                    }).catch(function (err) {
                        if (index < urls.length) {
                            return tryNext(err && err.message ? err.message : 'İstek başarısız.');
                        }
                        throw err;
                    });
                }

                return tryNext('');
            }

            if (!cleanBtn || !retention) {
                cleanBtn = null;
            }

            if (cleanBtn && retention) {
                cleanBtn.addEventListener('click', function () {
                var days = parseInt(retention.value || '30', 10);
                var label = days > 0 ? days + ' günden eski resolved kayıtlar' : 'tüm resolved kayıtlar';
                if (!window.confirm(label + ' silinsin mi?')) {
                    return;
                }

                cleanBtn.disabled = true;
                setNotice('ok', 'Resolved kayıtlar temizleniyor...');

                var body = new URLSearchParams();
                body.append('retention_days', String(days));

                postJson([bulkUrl, bulkFallbackUrl], body)
                    .then(function (data) {
                        if (data && data.ok) {
                            setNotice('ok', data.message || 'Resolved kayıtlar temizlendi.');
                            window.location.reload();
                        } else {
                            setNotice('err', (data && data.message) ? data.message : 'Resolved kayıtlar temizlenemedi.');
                            cleanBtn.disabled = false;
                        }
                    })
                    .catch(function (err) {
                        setNotice('err', err && err.message ? err.message : 'Toplu temizlik isteği sırasında hata oluştu.');
                        cleanBtn.disabled = false;
                    });
                });
            }

            var ignoreNotice = document.getElementById('ignore-notice');
            function setIgnoreNotice(type, text) {
                if (!ignoreNotice) { return; }
                ignoreNotice.className = 'notice ' + (type === 'ok' ? 'ok' : 'err');
                ignoreNotice.style.display = 'block';
                ignoreNotice.textContent = text;
            }

            var ruleForm = document.getElementById('ignore-rule-form');
            if (ruleForm) {
                ruleForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    var body = new URLSearchParams(new FormData(ruleForm));
                    body.append('action', 'add_rule');
                    setIgnoreNotice('ok', 'Ignore kuralı ekleniyor...');
                    postJson([ignoreUrl, ignoreFallbackUrl], body)
                        .then(function (data) {
                            if (data && data.ok) {
                                setIgnoreNotice('ok', data.message || 'Ignore kuralı eklendi.');
                                window.location.reload();
                            } else {
                                setIgnoreNotice('err', (data && data.message) ? data.message : 'Ignore kuralı eklenemedi.');
                            }
                        })
                        .catch(function (err) {
                            setIgnoreNotice('err', err && err.message ? err.message : 'Ignore kuralı eklenemedi.');
                        });
                });
            }

            document.querySelectorAll('.js-delete-rule').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!window.confirm('Ignore kuralı silinsin mi?')) {
                        return;
                    }
                    btn.disabled = true;
                    var body = new URLSearchParams();
                    body.append('action', 'delete_rule');
                    body.append('rule_id', btn.getAttribute('data-rule-id') || '0');
                    postJson([ignoreUrl, ignoreFallbackUrl], body)
                        .then(function (data) {
                            if (data && data.ok) {
                                window.location.reload();
                            } else {
                                setIgnoreNotice('err', (data && data.message) ? data.message : 'Kural silinemedi.');
                                btn.disabled = false;
                            }
                        })
                        .catch(function (err) {
                            setIgnoreNotice('err', err && err.message ? err.message : 'Kural silinemedi.');
                            btn.disabled = false;
                        });
                });
            });

            document.querySelectorAll('.js-ignore-link').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!window.confirm('Bu broken link ignore kuralına eklensin mi?')) {
                        return;
                    }
                    btn.disabled = true;
                    var body = new URLSearchParams();
                    body.append('action', 'ignore_link');
                    body.append('broken_link_id', btn.getAttribute('data-broken-id') || '0');
                    postJson([ignoreUrl, ignoreFallbackUrl], body)
                        .then(function (data) {
                            if (data && data.ok) {
                                window.location.reload();
                            } else {
                                setIgnoreNotice('err', (data && data.message) ? data.message : 'Broken link ignore edilemedi.');
                                btn.disabled = false;
                            }
                        })
                        .catch(function (err) {
                            setIgnoreNotice('err', err && err.message ? err.message : 'Broken link ignore edilemedi.');
                            btn.disabled = false;
                        });
                });
            });
        })();
    </script>
</body>
</html>
