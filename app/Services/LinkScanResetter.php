<?php

declare(strict_types=1);

final class LinkScanResetter
{
    /** @var PDO */
    private $pdo;

    /** @var string */
    private $liveStateDir;

    public function __construct(PDO $pdo, ?string $liveStateDir = null)
    {
        $this->pdo = $pdo;
        $this->liveStateDir = $liveStateDir ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'link_scan_live';
    }

    /**
     * @return array<string, int>
     */
    public function resetAll(): array
    {
        $runningJobIds = $this->runningJobIds();
        $cancelRequested = 0;
        foreach ($runningJobIds as $jobId) {
            if ($this->writeCancelRequest($jobId)) {
                $cancelRequested++;
            }
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $closedRunning = 0;
        $deletedJobs = 0;
        $deletedDiscovered = 0;
        $deletedBroken = 0;

        $this->pdo->beginTransaction();
        try {
            $closeStmt = $this->pdo->prepare("
                UPDATE link_scan_jobs
                SET
                    finished_at = :finished_at,
                    status = 'failed',
                    duration_seconds = MAX(0, CAST(strftime('%s', :finished_at) AS INTEGER) - CAST(strftime('%s', started_at) AS INTEGER)),
                    error_message = :error_message
                WHERE status = 'running'
            ");
            $closeStmt->execute([
                'finished_at' => $now,
                'error_message' => 'Reset by user',
            ]);
            $closedRunning = $closeStmt->rowCount();

            $deletedDiscovered = $this->deleteFromTable('discovered_links');
            $deletedBroken = $this->deleteFromTable('broken_links');
            $deletedJobs = $this->deleteFromTable('link_scan_jobs');

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $deletedLiveFiles = $this->deleteLiveStateFiles();

        return [
            'running_cancel_requested' => $cancelRequested,
            'running_closed' => $closedRunning,
            'deleted_jobs' => $deletedJobs,
            'deleted_discovered_links' => $deletedDiscovered,
            'deleted_broken_links' => $deletedBroken,
            'deleted_live_files' => $deletedLiveFiles,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function runningJobIds(): array
    {
        $rows = $this->pdo->query("SELECT id FROM link_scan_jobs WHERE status = 'running'")->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    private function deleteFromTable(string $table): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $table);
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function writeCancelRequest(int $jobId): bool
    {
        if ($jobId < 1) {
            return false;
        }

        if (!is_dir($this->liveStateDir)) {
            @mkdir($this->liveStateDir, 0775, true);
        }

        $path = $this->liveStateDir . DIRECTORY_SEPARATOR . 'job_' . $jobId . '.cancel';
        return @file_put_contents($path, (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), LOCK_EX) !== false;
    }

    private function deleteLiveStateFiles(): int
    {
        if (!is_dir($this->liveStateDir)) {
            return 0;
        }

        $deleted = 0;
        foreach (['job_*.json', 'job_*.cancel'] as $pattern) {
            $files = glob($this->liveStateDir . DIRECTORY_SEPARATOR . $pattern);
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file) && @unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
