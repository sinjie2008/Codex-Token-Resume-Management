<?php

declare(strict_types=1);

use CodexAutoResume\Database;
use CodexAutoResume\SessionRepository;
use CodexAutoResume\Util;

$config = require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = Database::connect($config);
$repository = new SessionRepository($pdo, $config);
$repository->assertSchema();
$sessionId = Util::uuidV4();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

try {
    $session = $repository->upsertManual([
        'session_id' => $sessionId,
        'codex_title' => 'Integration fixture',
        'project_path' => 'C:\\fixture',
        'rollout_path' => 'C:\\fixture\\rollout.jsonl',
        'updated_at_ms' => (int) floor(microtime(true) * 1000),
    ], 'Fixture', 'Fixture prompt');
    $assert($session['status'] === 'WATCHING', 'Manual session must start in WATCHING.');

    $repository->queueRateLimit([
        'session_id' => $sessionId,
        'codex_title' => 'Integration fixture',
        'project_path' => 'C:\\fixture',
        'rollout_path' => 'C:\\fixture\\rollout.jsonl',
    ], [
        'timestamp' => gmdate('c', time() - 120),
        'turn_id' => 'fixture-turn',
        'ordinal' => 10,
        'reset_at' => time() - 60,
    ]);
    $session = $repository->findBySessionId($sessionId);
    $assert($session['status'] === 'WAITING_FOR_RESET', 'Rate limit must queue the session.');

    $lock = Util::uuidV4();
    $assert($repository->acquireResumeLock((int) $session['id'], $lock), 'Eligible session must acquire one atomic resume lock.');
    $repository->activeWriterBlocked((int) $session['id'], $lock);
    $session = $repository->findBySessionId($sessionId);
    $assert($session['status'] === 'ACTIVE_ELSEWHERE' && $session['resume_lock'] === null, 'Active writer must release lock into local backoff.');
    $repository->recordActivity($sessionId, gmdate('c', time() + 1), 'external-turn');
    $session = $repository->findBySessionId($sessionId);
    $assert($session['status'] === 'RESUMED_EXTERNALLY' && $session['reset_at'] === null && $session['next_retry_at'] === null && $session['last_error'] === null, 'External activity must clear obsolete retry timing and errors.');
    $repository->queueRateLimit([
        'session_id' => $sessionId,
        'codex_title' => 'Integration fixture',
        'project_path' => 'C:\\fixture',
        'rollout_path' => 'C:\\fixture\\rollout.jsonl',
    ], [
        'timestamp' => gmdate('c', time() + 2),
        'turn_id' => 'fixture-turn-again',
        'ordinal' => 10,
        'reset_at' => time() + 30,
    ]);
    $session = $repository->findBySessionId($sessionId);
    $assert($repository->cancel((int) $session['id']), 'Cancellation must disable this session.');
    $session = $repository->findBySessionId($sessionId);
    $assert($session['status'] === 'CANCELLED' && (int) $session['auto_resume'] === 0, 'Cancelled session must not remain eligible.');
    $repository->saveScanState($sessionId, 'C:\\fixture\\rollout.jsonl', 321, gmdate('c'), [
        'limit_id' => 'codex',
        'primary' => ['used_percent' => 100.0, 'window_minutes' => 300, 'resets_at' => time() + 60],
        'secondary' => null,
        'rate_limit_reached_type' => null,
        'plan_type' => 'plus',
    ]);
    $removedId = (int) $session['id'];
    $assert($repository->removeSession($removedId) === $sessionId, 'Delete must remove the current managed queue record.');
    $session = $repository->findBySessionId($sessionId);
    $assert($session === null, 'Deleted session must not leave a tombstone that blocks future automatic detection.');
    $scanState = $repository->scanState($sessionId);
    $storedUsage = is_array($scanState) ? json_decode((string) $scanState['last_usage_json'], true) : null;
    $assert($scanState !== null && (float) ($storedUsage['primary']['used_percent'] ?? 0) === 100.0, 'Delete must retain the rollout cursor and per-session usage context so the old limit event is not replayed.');
    $assert(!in_array($sessionId, array_column($repository->listSessions(), 'session_id'), true), 'Deleted session must be hidden from the dashboard.');
    $assert(!in_array($sessionId, $repository->managedSessionIds(), true), 'Deleted session must not stay in the managed scan set.');
    $assert(!$repository->queueResumeNow($removedId), 'A stale Resume Now action must not requeue a deleted session.');
    $assert(!$repository->enable($removedId), 'A stale Enable action must not restore a deleted session.');

    $repository->queueRateLimit([
        'session_id' => $sessionId,
        'codex_title' => 'Integration fixture',
        'project_path' => 'C:\\fixture',
        'rollout_path' => 'C:\\fixture\\rollout.jsonl',
    ], [
        'timestamp' => gmdate('c'),
        'turn_id' => 'fixture-turn-after-delete',
        'ordinal' => 11,
        'reset_at' => time() + 60,
    ]);
    $session = $repository->findBySessionId($sessionId);
    $assert($session === null, 'A storage scan limit event must not enroll an unmanaged session.');

    $session = $repository->upsertManual([
        'session_id' => $sessionId,
        'codex_title' => 'Internal fixture',
        'project_path' => 'C:\\fixture',
        'rollout_path' => 'C:\\fixture\\rollout.jsonl',
        'updated_at_ms' => (int) floor(microtime(true) * 1000),
    ], null, null);
    $repository->filterInternalSession($sessionId);
    $session = $repository->findBySessionId($sessionId);
    $assert($session['status'] === 'FILTERED_INTERNAL' && (int) $session['auto_resume'] === 0, 'A positively classified internal session must be quarantined.');
    $assert(!in_array($sessionId, array_column($repository->listSessions(), 'session_id'), true), 'Filtered internal sessions must stay out of the dashboard queue.');
    echo "PASS: $checks MySQL checks\n";
} finally {
    $statement = $pdo->prepare('DELETE FROM activity_logs WHERE session_id = :session_id');
    $statement->execute(['session_id' => $sessionId]);
    $statement = $pdo->prepare('DELETE FROM codex_scan_state WHERE session_id = :session_id');
    $statement->execute(['session_id' => $sessionId]);
    $statement = $pdo->prepare('DELETE FROM codex_sessions WHERE session_id = :session_id');
    $statement->execute(['session_id' => $sessionId]);
}
