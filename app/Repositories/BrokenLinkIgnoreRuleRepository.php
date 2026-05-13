<?php

declare(strict_types=1);

final class BrokenLinkIgnoreRuleRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(bool $activeOnly = false): array
    {
        $sql = "
            SELECT *
            FROM broken_link_ignore_rules
        ";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $pattern = trim((string) ($data['pattern'] ?? ''));
        if ($pattern === '') {
            throw new InvalidArgumentException('Ignore pattern cannot be empty');
        }

        $matchType = (string) ($data['match_type'] ?? 'contains');
        if (!in_array($matchType, ['contains', 'exact', 'regex'], true)) {
            $matchType = 'contains';
        }

        $scope = (string) ($data['scope'] ?? 'target');
        if (!in_array($scope, ['target', 'source', 'either'], true)) {
            $scope = 'target';
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            INSERT INTO broken_link_ignore_rules (pattern, match_type, scope, note, is_active, created_at)
            VALUES (:pattern, :match_type, :scope, :note, :is_active, :created_at)
        ");
        $stmt->execute([
            'pattern' => $pattern,
            'match_type' => $matchType,
            'scope' => $scope,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteById(int $id): bool
    {
        if ($id < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM broken_link_ignore_rules WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function shouldIgnore(string $targetUrl, string $sourceUrl): bool
    {
        foreach ($this->all(true) as $rule) {
            if ($this->matchesRule($rule, $targetUrl, $sourceUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function matchesRule(array $rule, string $targetUrl, string $sourceUrl): bool
    {
        $pattern = (string) ($rule['pattern'] ?? '');
        if ($pattern === '') {
            return false;
        }

        $scope = (string) ($rule['scope'] ?? 'target');
        $haystacks = [];
        if ($scope === 'target' || $scope === 'either') {
            $haystacks[] = $targetUrl;
        }
        if ($scope === 'source' || $scope === 'either') {
            $haystacks[] = $sourceUrl;
        }

        foreach ($haystacks as $haystack) {
            $matchType = (string) ($rule['match_type'] ?? 'contains');
            if ($matchType === 'exact' && $haystack === $pattern) {
                return true;
            }
            if ($matchType === 'contains' && stripos($haystack, $pattern) !== false) {
                return true;
            }
            if ($matchType === 'regex') {
                $ok = @preg_match($pattern, $haystack);
                if ($ok === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS broken_link_ignore_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pattern TEXT NOT NULL,
                match_type TEXT NOT NULL DEFAULT 'contains' CHECK (match_type IN ('contains','exact','regex')),
                scope TEXT NOT NULL DEFAULT 'target' CHECK (scope IN ('target','source','either')),
                note TEXT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_broken_link_ignore_rules_active ON broken_link_ignore_rules (is_active)");
    }
}
