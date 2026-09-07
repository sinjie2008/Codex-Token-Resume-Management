<?php

declare(strict_types=1);

namespace CodexAutoResume;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class Worker
{
    private readonly RecoveryPolicy $recoveryPolicy;

    public function __construct(
        private readonly Config $config,
        private readonly SessionRepository $repository,
        private readonly CodexLocalStore $localStore,
        private readonly RolloutScanner $scanner,
        private readonly CodexRunner $runner,
        private readonly bool $dryRun,
    ) {
        $this->recoveryPolicy = new RecoveryPolicy($config);
    }

    public function run(bool $once): void
    {
        if ($this->repository->getSetting('default_resume_prompt') === null) {
            $this->repository->setSetting('default_resume_prompt', $this->config->string('DEFAULT_RESUME_PROMPT'));
        }

        try {
            $probe = $this->runner->probe();
            $this->repository->setSetting('codex_version', $probe['version']);
            $this->repository->setSetting('codex_executable_status', $probe['available'] ? 'READY' : 'UNAVAILABLE');
        } catch (Throwable $error) {
            $this->repository->setSetting('codex_executable_status', 'UNAVAILABLE');
            $this->repository->log(null, 'CODEX_UNAVAILABLE', Util::truncate($error->getMessage(), 350));
        }

        $this->repository->log(null, 'WORKER_STARTED', $this->dryRun ? 'Worker started in dry-run mode.' : 'Worker started.');
        do {
            $this->repository->heartbeat(getmypid() ?: 0, $this->dryRun);
            $this->scanCodexState();
            $this->pauseStaleWaitingSessions();
            $this->recoverStaleResumes();
            if (!$this->dryRun) {
                $this->resumeOneDueSession();
            }

            if (!$once) {
                sleep($this->config->int('WORKER_POLL_SECONDS', 1));
            }
        } while (!$once);
    }

    private function scanCodexState(): void
    {
        if (!$this->localStore->isAvailable()) {
            $this->repository->setSetting('codex_storage_status', 'UNAVAILABLE');
            return;
        }

        $this->repository->setSetting('codex_storage_status', 'READY');
        $lookbackMs = $this->config->int('SCAN_LOOKBACK_HOURS', 1) * 3600 * 1000;
        $threads = $this->localStore->listRecentThreads(
            (int) floor(microtime(true) * 1000) - $lookbackMs,
            $this->repository->managedSessionIds(),
        );
        $latestUsage = null;

        foreach ($threads as $thread) {
            $this->repository->updateMetadata($thread);
            $rolloutPath = $thread['rollout_path'];
            if ($rolloutPath === null || !is_file($rolloutPath)) {
                continue;
            }

            try {
                $state = $this->repository->scanState($thread['session_id']);
                $offset = $state !== null && $state['rollout_path'] === $rolloutPath
                    ? (int) $state['byte_offset']
                    : null;
                $threadUsage = null;
                if ($offset !== null && is_string($state['last_usage_json'] ?? null)) {
                    $decodedUsage = json_decode($state['last_usage_json'], true);
                    $threadUsage = is_array($decodedUsage) ? $decodedUsage : null;
                }
                $scan = $this->scanner->scan(
                    $rolloutPath,
                    $offset,
                    $this->config->int('INITIAL_SCAN_BYTES', 4096),
                    $threadUsage,
                );

                $threadLatestUsage = null;
                $queuedFromRollout = false;
                foreach ($scan['events'] as $event) {
                    if ($event['kind'] === 'turn_started' || $event['kind'] === 'user_activity') {
                        $this->repository->recordActivity(
                            $thread['session_id'],
                            $event['timestamp'],
                            $event['turn_id'],
                        );
                        continue;
                    }

                    if ($event['kind'] === 'usage') {
                        $threadLatestUsage = $event;
                        $threadUsage = $event['usage'];
                        if ($this->isNewerUsage($event, $latestUsage)) {
                            $latestUsage = $event;
                        }
                        if ($event['limit_reached'] && $this->recoveryPolicy->shouldQueueLimitEvent($event)) {
                            $this->repository->queueRateLimit($thread, $event);
                            $queuedFromRollout = true;
                        } elseif ($event['limit_reached']) {
                            $this->repository->log($thread['session_id'], 'STALE_LIMIT_IGNORED', 'Old limit event ignored; manual review is required before any resume.');
                        }
                        continue;
                    }

                    if ($event['kind'] === 'rate_limit_error') {
                        if ($this->recoveryPolicy->shouldQueueLimitEvent($event)) {
                            $this->repository->queueRateLimit($thread, $event);
                            $queuedFromRollout = true;
                        } else {
                            $this->repository->log($thread['session_id'], 'STALE_LIMIT_IGNORED', 'Old limit failure ignored; manual review is required before any resume.');
                        }
                    }
                }

                $latestTurn = $this->localStore->latestTurn($thread['session_id']);
                if (!$queuedFromRollout && $this->isUsageLimitTurn($latestTurn)) {
                    if ($threadLatestUsage === null) {
                        $tail = $this->scanner->scan(
                            $rolloutPath,
                            null,
                            $this->config->int('INITIAL_SCAN_BYTES', 4096),
                        );
                        foreach ($tail['events'] as $tailEvent) {
                            if ($tailEvent['kind'] === 'usage') {
                                $threadLatestUsage = $tailEvent;
                            }
                        }
                    }
                    $resetAt = ($threadLatestUsage['usage'] ?? $threadUsage)['primary']['resets_at'] ?? null;
                    $historyEvent = [
                        'timestamp' => gmdate('c', (int) ($latestTurn['completed_at'] ?: $latestTurn['started_at'])),
                        'turn_id' => $latestTurn['turn_id'],
                        'ordinal' => $latestTurn['rollout_ordinal'],
                        'reset_at' => is_numeric($resetAt) ? (int) $resetAt : null,
                    ];
                    if ($this->recoveryPolicy->shouldQueueLimitEvent($historyEvent)) {
                        $this->repository->queueRateLimit($thread, $historyEvent);
                    }
                }

                $this->repository->saveScanState(
                    $thread['session_id'],
                    $rolloutPath,
                    $scan['byte_offset'],
                    $scan['last_event_timestamp'],
                    $threadLatestUsage['usage'] ?? $threadUsage,
                );
            } catch (Throwable $error) {
                if ($this->repository->findBySessionId($thread['session_id']) !== null) {
                    $this->repository->log(
                        $thread['session_id'],
                        'SCAN_ERROR',
                        'Unable to read the local rollout: ' . Util::truncate($error->getMessage(), 350),
                    );
                }
            }
        }

        if ($latestUsage !== null && is_array($latestUsage['usage'])) {
            $this->repository->recordUsage($latestUsage['usage'], $latestUsage['timestamp']);
        }
        $this->repository->setSetting('last_codex_scan_at', Util::dbTime(Util::utcNow()) ?? '');
    }

    private function recoverStaleResumes(): void
    {
        foreach ($this->repository->staleResumingSessions() as $session) {
            $newActivity = $this->hasNewerTurn($session);
            $this->repository->releaseStaleResume((int) $session['id'], $newActivity);
            $this->repository->log(
                $session['session_id'],
                'STALE_LOCK_RECOVERED',
                $newActivity
                    ? 'Interrupted resume reconciled from newer local Codex activity.'
                    : 'Interrupted resume lock released; cooldown retry scheduled.',
            );
        }
    }

    private function pauseStaleWaitingSessions(): void
    {
        foreach ($this->repository->waitingSessions() as $session) {
            if (!$this->recoveryPolicy->isWaitingStale($session)) {
                continue;
            }

            $this->repository->pauseForManualReview((int) $session['id']);
            $this->repository->log($session['session_id'], 'MANUAL_REVIEW_REQUIRED', 'Expired recovery evidence blocked an automatic resume.');
        }
    }

    private function resumeOneDueSession(): void
    {
        $session = $this->repository->findDueSession();
        if ($session === null) {
            return;
        }

        if ($this->hasNewerTurn($session)) {
            $thread = $this->localStore->findThread($session['session_id']);
            $timestamp = $thread !== null && $thread['updated_at_ms'] > 0
                ? gmdate('c', (int) floor($thread['updated_at_ms'] / 1000))
                : gmdate('c');
            $this->repository->recordActivity($session['session_id'], $timestamp, null);
            return;
        }

        $lock = Util::uuidV4();
        if (!$this->repository->acquireResumeLock((int) $session['id'], $lock)) {
            return;
        }

        if ($this->localStore->hasActiveWriter($session['session_id'])) {
            $this->repository->activeWriterBlocked((int) $session['id'], $lock);
            $this->repository->log($session['session_id'], 'ACTIVE_WRITER', 'Local Codex metadata shows an in-progress writer; retry paused locally.');
            return;
        }

        $prompt = trim((string) $session['resume_prompt']);
        if ($prompt === '') {
            $prompt = $this->repository->getSetting('default_resume_prompt')
                ?: $this->config->string('DEFAULT_RESUME_PROMPT');
        }

        $this->repository->log($session['session_id'], 'RESUME_STARTED', 'Eligible session resume started.');
        $outcome = $this->runner->resume(
            $session['session_id'],
            $prompt,
            $session['project_path'],
            function (): void {
                try {
                    $this->repository->heartbeat(getmypid() ?: 0, false);
                } catch (Throwable) {
                }
            },
        );

        if ($outcome['kind'] === 'success') {
            $this->repository->completeResume((int) $session['id'], $lock);
            $this->repository->log($session['session_id'], 'RESUME_COMPLETED', 'Codex resume completed successfully.');
            return;
        }

        if ($outcome['kind'] === 'active_writer') {
            $this->repository->activeWriterBlocked((int) $session['id'], $lock);
            $this->repository->log($session['session_id'], 'ACTIVE_WRITER', 'Active writer detected; retry paused locally.');
            return;
        }

        if ($outcome['kind'] === 'rate_limited') {
            $this->repository->rateLimitRetry((int) $session['id'], $lock, $outcome['reset_at']);
            $this->repository->log($session['session_id'], 'RATE_LIMIT_RETRY', 'Codex remains limited; local backoff scheduled.');
            return;
        }

        if ($outcome['kind'] === 'missing_session') {
            $this->repository->stopWithError((int) $session['id'], $lock, $outcome['reason']);
            $this->repository->log($session['session_id'], 'SESSION_MISSING', 'Codex no longer exposes this session; auto resume disabled.');
            return;
        }

        $this->repository->genericRetry((int) $session['id'], $lock, $outcome['reason']);
        $this->repository->log($session['session_id'], 'RESUME_FAILED', $outcome['reason'] . ' Safe backoff scheduled.');
    }

    private function hasNewerTurn(array $session): bool
    {
        $rolloutPath = $session['rollout_path'] ?? null;
        $limitDetectedAt = $session['limit_detected_at'] ?? null;
        if (!is_string($rolloutPath) || !is_file($rolloutPath) || !is_string($limitDetectedAt)) {
            return false;
        }

        try {
            $scan = $this->scanner->scan(
                $rolloutPath,
                null,
                $this->config->int('INITIAL_SCAN_BYTES', 4096),
            );
        } catch (Throwable) {
            return true;
        }

        $limitTime = new DateTimeImmutable($limitDetectedAt, new DateTimeZone('UTC'));
        foreach ($scan['events'] as $event) {
            if (($event['kind'] !== 'turn_started' && $event['kind'] !== 'user_activity') || $event['timestamp'] === null) {
                continue;
            }

            $activityTime = new DateTimeImmutable($event['timestamp']);
            $newTurn = $event['turn_id'] === null
                || $session['limit_turn_id'] === null
                || $event['turn_id'] !== $session['limit_turn_id'];
            if ($newTurn && $activityTime > $limitTime) {
                return true;
            }
        }

        return false;
    }

    private function isNewerUsage(array $candidate, ?array $current): bool
    {
        if ($current === null) {
            return true;
        }

        return strtotime((string) $candidate['timestamp']) > strtotime((string) $current['timestamp']);
    }

    private function isUsageLimitTurn(?array $turn): bool
    {
        if ($turn === null || ($turn['status'] ?? null) !== 'failed') {
            return false;
        }

        $errorInfo = strtolower((string) ($turn['error_info'] ?? ''));
        return $errorInfo === 'usagelimitexceeded' || $errorInfo === 'usage_limit_exceeded';
    }

}
