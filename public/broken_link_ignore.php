<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_auth();

header('Content-Type: application/json; charset=utf-8');

function json_response_ignore(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_broken_link_ignore_columns(PDO $pdo): void
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response_ignore(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$pdo = Database::connection();
ensure_broken_link_ignore_columns($pdo);
$repo = new BrokenLinkIgnoreRuleRepository($pdo);
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'add_rule') {
        $id = $repo->create([
            'pattern' => (string) ($_POST['pattern'] ?? ''),
            'match_type' => (string) ($_POST['match_type'] ?? 'contains'),
            'scope' => (string) ($_POST['scope'] ?? 'target'),
            'note' => (string) ($_POST['note'] ?? ''),
        ]);
        json_response_ignore(['ok' => true, 'id' => $id, 'message' => 'Ignore kuralı eklendi.']);
    }

    if ($action === 'delete_rule') {
        $ok = $repo->deleteById((int) ($_POST['rule_id'] ?? 0));
        json_response_ignore(['ok' => $ok, 'message' => $ok ? 'Ignore kuralı silindi.' : 'Ignore kuralı bulunamadı.'], $ok ? 200 : 404);
    }

    if ($action === 'ignore_link') {
        $brokenId = (int) ($_POST['broken_link_id'] ?? 0);
        if ($brokenId < 1) {
            json_response_ignore(['ok' => false, 'message' => 'Geçersiz broken link ID.'], 422);
        }

        $stmt = $pdo->prepare("SELECT * FROM broken_links WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $brokenId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            json_response_ignore(['ok' => false, 'message' => 'Broken link bulunamadı.'], 404);
        }

        $targetUrl = (string) ($row['target_url'] ?? '');
        if ($targetUrl === '') {
            json_response_ignore(['ok' => false, 'message' => 'Broken link hedef URL boş.'], 422);
        }

        $repo->create([
            'pattern' => $targetUrl,
            'match_type' => 'exact',
            'scope' => 'target',
            'note' => 'Created from broken link #' . $brokenId,
        ]);

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $update = $pdo->prepare("
            UPDATE broken_links
            SET ignored_at = :ignored_at,
                ignored_reason = :ignored_reason,
                resolved_at = COALESCE(resolved_at, :resolved_at)
            WHERE id = :id
        ");
        $update->execute([
            'ignored_at' => $now,
            'ignored_reason' => 'Ignored by user',
            'resolved_at' => $now,
            'id' => $brokenId,
        ]);

        json_response_ignore(['ok' => true, 'message' => 'Broken link ignore listesine alındı.']);
    }
} catch (Throwable $e) {
    json_response_ignore(['ok' => false, 'message' => $e->getMessage()], 500);
}

json_response_ignore(['ok' => false, 'message' => 'Unknown action'], 400);
