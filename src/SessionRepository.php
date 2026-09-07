<?php

declare(strict_types=1);

namespace CodexAutoResume;

use PDO;
use RuntimeException;

final class SessionRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    public function assertSchema(): void
    {
        $this->pdo->query('SELECT 1 FROM codex_sessions LIMIT 1');
        $usageColumn = $this->pdo->query('SHOW COLUMNS FROM codex_scan_state LIKE "last_usage_json"')->fetchColumn();
        if ($usageColumn === false) {
            $this->pdo->exec('ALTER TABLE codex_scan_state ADD COLUMN last_usage_json LONGTEXT NULL AFTER last_event_timestamp');
        }
        $this->pdo->exec('DELETE FROM codex_sessions WHERE status = "REMOVED"');
    }

    public function acquireWorkerLock(): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute(['lock_name' => 'codex_auto_resume.worker']);

        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseWorkerLock(): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => 'codex_auto_resume.worker']);
    }

    public function heartbeat(int $pid, bool $dryRun): void
    {
        $this->setSetting('worker_heartbeat_at', Util::dbTime(Util::utcNow()) ?? '');
        $this->setSetting('worker_pid', (string) $pid);
        $this->setSetting('worker_mode', $dryRun ? 'DRY_RUN' : 'LIVE');
    }

    public function setSetting(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute(['setting_key' => $key, 'setting_value' => $value]);
    }

    public function getSetting(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key');
        $statement->execute(['setting_key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function recordUsage(array $usage, ?string $timestamp): void
    {
        $encoded = json_encode($usage, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        $this->setSetting('usage_snapshot', $encoded);
        $this->setSetting('usage_updated_at', Util::dbTime($timestamp ?: Util::utcNow()) ?? '');
    }

    public function usageSnapshot(): ?array
    {
        $encoded = $this->getSetting('usage_snapshot');
        if ($encoded === null) {
            return null;
        }

        $usage = json_decode($encoded, true);
        if (!is_array($usage)) {
            return null;
        }

        $usage['updated_at'] = Util::isoFromDb($this->getSetting('usage_updated_at'));
        return $usage;
    }

    public function managedSessionIds(): array
    {
        return $this->pdo->query('SELECT session_id FROM codex_sessions')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM codex_sessions WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function findBySessionId(string $sessionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM codex_sessions WHERE session_id = :session_id');
        $statement->execute(['session_id' => strtolower($sessionId)]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function listSessions(): array
    {
        return $this->pdo->query(
            'SELECT * FROM codex_sessions
             ORDER BY FIELD(status, "RESUMING", "WAITING_FOR_RESET", "RETRY_WAIT", "ACTIVE_ELSEWHERE", "WATCHING", "RESUMED_EXTERNALLY", "COMPLETED", "CANCELLED", "ERROR"),
                      COALESCE(next_retry_at, reset_at, updated_at), id'
        )->fetchAll();
    }

    public function listLogs(int $limit = 80): array
    {
        $limit = max(1, min(200, $limit));
        return $this->pdo->query(
            'SELECT id, session_id, event_type, message, created_at FROM activity_logs ORDER BY id DESC LIMIT ' . $limit
        )->fetchAll();
    }

    public function clearLogs(): int
    {
        return (int) $this->pdo->exec('DELETE FROM activity_logs');
    }

    public function scanState(string $sessionId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM codex_scan_state WHERE session_id = :session_id');
        $statement->execute(['session_id' => strtolower($sessionId)]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saveScanState(
        string $sessionId,
        string $rolloutPath,
        int $offset,
        ?string $timestamp,
        ?array $usage = null,
    ): void
    {
        $encodedUsage = $usage !== null ? json_encode($usage, JSON_UNESCAPED_SLASHES) : null;
        $statement = $this->pdo->prepare(
            'INSERT INTO codex_scan_state (session_id, rollout_path, byte_offset, last_event_timestamp, last_usage_json)
             VALUES (:session_id, :rollout_path, :byte_offset, :last_event_timestamp, :last_usage_json)
             ON DUPLICATE KEY UPDATE rollout_path = VALUES(rollout_path), byte_offset = VALUES(byte_offset),
                                     last_event_timestamp = VALUES(last_event_timestamp),
                                     last_usage_json = COALESCE(VALUES(last_usage_json), last_usage_json),
                                     updated_at = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([
            'session_id' => strtolower($sessionId),
            'rollout_path' => $rolloutPath,
            'byte_offset' => $offset,
            'last_event_timestamp' => Util::dbTime($timestamp),
            'last_usage_json' => $encodedUsage === false ? null : $encodedUsage,
        ]);
    }

    public function upsertManual(array $thread, ?string $displayName, ?string $resumePrompt): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO codex_sessions
                (session_id, codex_title, display_name, project_path, rollout_path, status, resume_prompt, auto_resume,
                 last_codex_activity_at)
             VALUES
                (:session_id, :codex_title, :display_name, :project_path, :rollout_path, "WATCHING", :resume_prompt, 1,
                 :last_codex_activity_at)
             ON DUPLICATE KEY UPDATE
                codex_title = VALUES(codex_title), display_name = VALUES(display_name), project_path = VALUES(project_path),
                rollout_path = VALUES(rollout_path), resume_prompt = VALUES(resume_prompt), auto_resume = 1,
                status = IF(status IN ("CANCELLED", "ERROR", "COMPLETED", "RESUMED_EXTERNALLY"), "WATCHING", status),
                updated_at = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([
            'session_id' => $thread['session_id'],
            'codex_title' => $thread['codex_title'],
            'display_name' => $this->nullableTrimmed($displayName, 255),
            'project_path' => $thread['project_path'],
            'rollout_path' => $thread['rollout_path'],
            'resume_prompt' => $this->nullableTrimmed($resumePrompt, 8000),
            'last_codex_activity_at' => $this->millisecondsToDbTime($thread['updated_at_ms'] ?? 0),
        ]);

        $this->log($thread['session_id'], 'SESSION_ADDED', 'Session added to monitoring.');
        return $this->findBySessionId($thread['session_id']) ?? throw new RuntimeException('Unable to load stored session.');
    }

    public function updateMetadata(array $thread): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET codex_title = :codex_title, project_path = :project_path, rollout_path = :rollout_path
             WHERE session_id = :session_id'
        );
        $statement->execute([
            'session_id' => $thread['session_id'],
            'codex_title' => $thread['codex_title'],
            'project_path' => $thread['project_path'],
            'rollout_path' => $thread['rollout_path'],
        ]);
    }

    public function queueRateLimit(array $thread, array $event): void
    {
        $detectedAt = Util::dbTime($event['timestamp'] ?? Util::utcNow());
        $previous = $this->findBySessionId($thread['session_id']);
        $isNewDetection = $previous === null
            || $previous['limit_detected_at'] === null
            || ($detectedAt !== null && $detectedAt > $previous['limit_detected_at']);
        $resetAt = isset($event['reset_at']) && is_int($event['reset_at']) ? Util::dbTime($event['reset_at']) : null;
        $nextRetryAt = $resetAt;
        if ($nextRetryAt === null) {
            $nextRetryAt = Util::dbTime(Util::utcNow()->modify('+' . $this->config->int('UNKNOWN_RESET_BACKOFF_SECONDS', 60) . ' seconds'));
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO codex_sessions
                (session_id, codex_title, project_path, rollout_path, status, auto_resume, limit_detected_at,
                 limit_turn_id, limit_event_ordinal, reset_at, next_retry_at, last_codex_activity_at)
             VALUES
                (:session_id, :codex_title, :project_path, :rollout_path, "WAITING_FOR_RESET", 1, :limit_detected_at,
                 :limit_turn_id, :limit_event_ordinal, :reset_at, :next_retry_at, :last_codex_activity_at)
             ON DUPLICATE KEY UPDATE
                codex_title = VALUES(codex_title), project_path = VALUES(project_path), rollout_path = VALUES(rollout_path),
                status = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01") AND auto_resume = 1,
                            "WAITING_FOR_RESET", status),
                limit_turn_id = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"),
                                   VALUES(limit_turn_id), limit_turn_id),
                limit_event_ordinal = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"),
                                         VALUES(limit_event_ordinal), limit_event_ordinal),
                reset_at = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"),
                              VALUES(reset_at), reset_at),
                next_retry_at = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"),
                                   VALUES(next_retry_at), next_retry_at),
                resume_lock = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"), NULL, resume_lock),
                resume_lock_at = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"), NULL, resume_lock_at),
                last_error = IF(VALUES(limit_detected_at) > COALESCE(limit_detected_at, "1970-01-01"), NULL, last_error),
                limit_detected_at = GREATEST(COALESCE(limit_detected_at, "1970-01-01"), VALUES(limit_detected_at)),
                updated_at = CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([
            'session_id' => $thread['session_id'],
            'codex_title' => $thread['codex_title'],
            'project_path' => $thread['project_path'],
            'rollout_path' => $thread['rollout_path'],
            'limit_detected_at' => $detectedAt,
            'limit_turn_id' => $event['turn_id'] ?? null,
            'limit_event_ordinal' => $event['ordinal'] ?? null,
            'reset_at' => $resetAt,
            'next_retry_at' => $nextRetryAt,
            'last_codex_activity_at' => $detectedAt,
        ]);

        if ($isNewDetection) {
            $this->log($thread['session_id'], 'LIMIT_DETECTED', $resetAt === null
                ? 'Rate limit detected; safe fallback retry scheduled.'
                : 'Rate limit detected; session queued until the reported reset time.');
        }
    }

    public function recordActivity(string $sessionId, ?string $timestamp, ?string $turnId): void
    {
        $activityAt = Util::dbTime($timestamp);
        if ($activityAt === null) {
            return;
        }

        $current = $this->findBySessionId($sessionId);
        if ($current === null) {
            return;
        }

        $eligibleStatuses = ['WAITING_FOR_RESET', 'RETRY_WAIT', 'ACTIVE_ELSEWHERE', 'RESUMING'];
        $isNewTurn = $turnId === null || $current['limit_turn_id'] === null || $turnId !== $current['limit_turn_id'];
        $isAfterLimit = $current['limit_detected_at'] !== null && $activityAt > $current['limit_detected_at'];
        $resumedExternally = in_array($current['status'], $eligibleStatuses, true) && $isNewTurn && $isAfterLimit;

        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET
                last_codex_activity_at = GREATEST(COALESCE(last_codex_activity_at, "1970-01-01"), :activity_at),
                last_codex_turn_id = COALESCE(:turn_id, last_codex_turn_id),
                status = :status, resume_lock = :resume_lock, resume_lock_at = :resume_lock_at,
                reset_at = :reset_at, next_retry_at = :next_retry_at, last_error = :last_error,
                active_writer_detected_at = :active_writer_detected_at,
                active_writer_retry_at = :active_writer_retry_at
             WHERE session_id = :session_id'
        );
        $statement->execute([
            'session_id' => strtolower($sessionId),
            'activity_at' => $activityAt,
            'turn_id' => $turnId,
            'status' => $resumedExternally ? 'RESUMED_EXTERNALLY' : $current['status'],
            'resume_lock' => $resumedExternally ? null : $current['resume_lock'],
            'resume_lock_at' => $resumedExternally ? null : $current['resume_lock_at'],
            'reset_at' => $resumedExternally ? null : $current['reset_at'],
            'next_retry_at' => $resumedExternally ? null : $current['next_retry_at'],
            'last_error' => $resumedExternally ? null : $current['last_error'],
            'active_writer_detected_at' => $resumedExternally ? null : $current['active_writer_detected_at'],
            'active_writer_retry_at' => $resumedExternally ? null : $current['active_writer_retry_at'],
        ]);

        if ($resumedExternally) {
            $this->log($sessionId, 'RESUMED_EXTERNALLY', 'New local Codex activity prevented a duplicate automatic resume.');
        }
    }

    public function findDueSession(): ?array
    {
        $cooldown = $this->config->int('RESUME_COOLDOWN_SECONDS', 0);
        $statement = $this->pdo->prepare(
            'SELECT * FROM codex_sessions
             WHERE auto_resume = 1
               AND status IN ("WAITING_FOR_RESET", "RETRY_WAIT", "ACTIVE_ELSEWHERE")
               AND COALESCE(next_retry_at, reset_at) IS NOT NULL
               AND COALESCE(next_retry_at, reset_at) <= CURRENT_TIMESTAMP(6)
               AND resume_lock IS NULL
               AND (last_resume_attempt_at IS NULL OR last_resume_attempt_at <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL :cooldown SECOND))
             ORDER BY COALESCE(next_retry_at, reset_at), id LIMIT 1'
        );
        $statement->bindValue(':cooldown', $cooldown, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function acquireResumeLock(int $id, string $token): bool
    {
        $cooldown = $this->config->int('RESUME_COOLDOWN_SECONDS', 0);
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "RESUMING", resume_lock = :resume_lock,
                 resume_lock_at = CURRENT_TIMESTAMP(6), last_resume_attempt_at = CURRENT_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6)
             WHERE id = :id AND auto_resume = 1
               AND status IN ("WAITING_FOR_RESET", "RETRY_WAIT", "ACTIVE_ELSEWHERE")
               AND resume_lock IS NULL
               AND COALESCE(next_retry_at, reset_at) <= CURRENT_TIMESTAMP(6)
               AND (last_resume_attempt_at IS NULL OR last_resume_attempt_at <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL :cooldown SECOND))'
        );
        $statement->bindValue(':resume_lock', $token);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':cooldown', $cooldown, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    public function completeResume(int $id, string $token): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "COMPLETED", resume_count = resume_count + 1,
                 last_resume_success_at = CURRENT_TIMESTAMP(6), retry_count = 0, next_retry_at = NULL,
                 resume_lock = NULL, resume_lock_at = NULL, last_error = NULL
             WHERE id = :id AND resume_lock = :resume_lock'
        );
        $statement->execute(['id' => $id, 'resume_lock' => $token]);
    }

    public function activeWriterBlocked(int $id, string $token): void
    {
        $seconds = $this->config->int('ACTIVE_WRITER_RETRY_SECONDS', 60);
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "ACTIVE_ELSEWHERE", retry_count = retry_count + 1,
                 active_writer_detected_at = CURRENT_TIMESTAMP(6),
                 active_writer_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL :active_seconds SECOND),
                 next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL :next_seconds SECOND),
                 resume_lock = NULL, resume_lock_at = NULL,
                 last_error = "Session has an active writer; automatic resume is paused until retry."
             WHERE id = :id AND resume_lock = :resume_lock'
        );
        $statement->bindValue(':active_seconds', $seconds, PDO::PARAM_INT);
        $statement->bindValue(':next_seconds', $seconds, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':resume_lock', $token);
        $statement->execute();
    }

    public function rateLimitRetry(int $id, string $token, ?int $resetTimestamp): void
    {
        $seconds = $this->config->int('RATE_LIMIT_RETRY_SECONDS', 60);
        $nextRetry = $resetTimestamp !== null
            ? Util::dbTime($resetTimestamp)
            : Util::dbTime(Util::utcNow()->modify('+' . $seconds . ' seconds'));
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "RETRY_WAIT", retry_count = retry_count + 1,
                 reset_at = COALESCE(:reset_at, reset_at), next_retry_at = :next_retry_at,
                 resume_lock = NULL, resume_lock_at = NULL,
                 last_error = "Codex is still rate limited; waiting locally before another attempt."
             WHERE id = :id AND resume_lock = :resume_lock'
        );
        $statement->execute([
            'id' => $id,
            'resume_lock' => $token,
            'reset_at' => $resetTimestamp !== null ? Util::dbTime($resetTimestamp) : null,
            'next_retry_at' => $nextRetry,
        ]);
    }

    public function genericRetry(int $id, string $token, string $reason): void
    {
        $row = $this->findById($id);
        $retryCount = (int) ($row['retry_count'] ?? 0) + 1;
        $base = $this->config->int('RATE_LIMIT_RETRY_SECONDS', 60);
        $seconds = min(3600, $base * (2 ** min(4, $retryCount - 1)));
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "RETRY_WAIT", retry_count = retry_count + 1,
                 next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL :seconds SECOND),
                 resume_lock = NULL, resume_lock_at = NULL, last_error = :last_error
             WHERE id = :id AND resume_lock = :resume_lock'
        );
        $statement->bindValue(':seconds', $seconds, PDO::PARAM_INT);
        $statement->bindValue(':last_error', Util::truncate($reason, 1000));
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':resume_lock', $token);
        $statement->execute();
    }

    public function stopWithError(int $id, string $token, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "ERROR", auto_resume = 0, next_retry_at = NULL,
                 resume_lock = NULL, resume_lock_at = NULL, last_error = :last_error
             WHERE id = :id AND resume_lock = :resume_lock'
        );
        $statement->execute([
            'id' => $id,
            'resume_lock' => $token,
            'last_error' => Util::truncate($reason, 1000),
        ]);
    }

    public function staleResumingSessions(): array
    {
        $seconds = $this->config->int('STALE_RESUME_LOCK_SECONDS', 60);
        $statement = $this->pdo->prepare(
            'SELECT * FROM codex_sessions WHERE status = "RESUMING" AND resume_lock_at IS NOT NULL
             AND resume_lock_at <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL :seconds SECOND)'
        );
        $statement->bindValue(':seconds', $seconds, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function waitingSessions(): array
    {
        return $this->pdo->query(
            'SELECT * FROM codex_sessions WHERE auto_resume = 1
             AND status IN ("WAITING_FOR_RESET", "RETRY_WAIT", "ACTIVE_ELSEWHERE")'
        )->fetchAll();
    }

    public function pauseForManualReview(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "ERROR", auto_resume = 0, next_retry_at = NULL,
                 resume_lock = NULL, resume_lock_at = NULL,
                 last_error = "The saved limit/reset event is too old for safe automatic recovery. Review locally, then use Resume Now if continuation is still required."
             WHERE id = :id AND status IN ("WAITING_FOR_RESET", "RETRY_WAIT", "ACTIVE_ELSEWHERE")'
        );
        $statement->execute(['id' => $id]);
    }

    public function releaseStaleResume(int $id, bool $newActivity): void
    {
        if ($newActivity) {
            $statement = $this->pdo->prepare(
                'UPDATE codex_sessions SET status = "RESUMED_EXTERNALLY", resume_lock = NULL, resume_lock_at = NULL,
                     last_error = "New Codex activity was found after an interrupted resume."
                 WHERE id = :id AND status = "RESUMING"'
            );
            $statement->execute(['id' => $id]);
            return;
        }

        $cooldown = $this->config->int('RESUME_COOLDOWN_SECONDS', 0);
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET status = "RETRY_WAIT", resume_lock = NULL, resume_lock_at = NULL,
                 next_retry_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL :seconds SECOND),
                 last_error = "Interrupted resume lock expired; retry delayed by cooldown."
             WHERE id = :id AND status = "RESUMING"'
        );
        $statement->bindValue(':seconds', $cooldown, PDO::PARAM_INT);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function updateSession(int $id, ?string $displayName, ?string $resumePrompt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET display_name = :display_name, resume_prompt = :resume_prompt
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'display_name' => $this->nullableTrimmed($displayName, 255),
            'resume_prompt' => $this->nullableTrimmed($resumePrompt, 8000),
        ]);

        return $statement->rowCount() === 1;
    }

    public function queueResumeNow(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET auto_resume = 1, status = "WAITING_FOR_RESET", reset_at = CURRENT_TIMESTAMP(6),
                 next_retry_at = CURRENT_TIMESTAMP(6), limit_detected_at = CURRENT_TIMESTAMP(6),
                 limit_turn_id = last_codex_turn_id, resume_lock = NULL, resume_lock_at = NULL, last_error = NULL
             WHERE id = :id AND status <> "RESUMING"'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function cancel(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET auto_resume = 0, status = "CANCELLED", next_retry_at = NULL,
                 resume_lock = NULL, resume_lock_at = NULL, last_error = NULL
             WHERE id = :id AND status <> "RESUMING"'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function removeSession(int $id): ?string
    {
        $session = $this->findById($id);
        if ($session === null || $session['status'] === 'RESUMING') {
            return null;
        }

        $statement = $this->pdo->prepare('DELETE FROM codex_sessions WHERE id = :id AND status <> "RESUMING"');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1 ? $session['session_id'] : null;
    }

    public function enable(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE codex_sessions SET auto_resume = 1,
                 status = IF(limit_detected_at IS NULL, "WATCHING", "WAITING_FOR_RESET"),
                 next_retry_at = IF(limit_detected_at IS NULL, NULL, COALESCE(reset_at, CURRENT_TIMESTAMP(6)))
             WHERE id = :id AND status <> "RESUMING"'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function log(?string $sessionId, string $type, string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO activity_logs (session_id, event_type, message) VALUES (:session_id, :event_type, :message)'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'event_type' => Util::truncate($type, 50),
            'message' => Util::truncate($message, 500),
        ]);

        $this->trimLogs();
    }

    private function trimLogs(): void
    {
        $limit = $this->config->int('LOG_RETENTION', 50);
        $threshold = $this->pdo->query(
            'SELECT id FROM activity_logs ORDER BY id DESC LIMIT 1 OFFSET ' . $limit
        )->fetchColumn();
        if ($threshold === false) {
            return;
        }

        $statement = $this->pdo->prepare('DELETE FROM activity_logs WHERE id <= :threshold');
        $statement->execute(['threshold' => $threshold]);
    }

    private function nullableTrimmed(?string $value, int $length): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : Util::truncate($value, $length);
    }

    private function millisecondsToDbTime(int $milliseconds): ?string
    {
        return $milliseconds > 0 ? Util::dbTime((int) floor($milliseconds / 1000)) : null;
    }
}
