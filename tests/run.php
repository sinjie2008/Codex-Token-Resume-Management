<?php

declare(strict_types=1);

use CodexAutoResume\CodexLocalStore;
use CodexAutoResume\CodexRunner;
use CodexAutoResume\RolloutScanner;
use CodexAutoResume\RecoveryPolicy;
use CodexAutoResume\Util;

$temporaryRoot = sys_get_temp_dir() . '/codex-auto-resume-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot . '/thread-writer-locks', 0777, true);
$rolloutPath = $temporaryRoot . '/rollout.jsonl';
$messageOnlyRolloutPath = $temporaryRoot . '/message-only-rollout.jsonl';
$statePath = $temporaryRoot . '/state_5.sqlite';
$historyPath = $temporaryRoot . '/thread_history_1.sqlite';
$sessionId = '019a03a5-1234-7abc-8def-1234567890ab';

$lines = [
    ['timestamp' => '2026-09-03T00:00:00Z', 'ordinal' => 1, 'type' => 'turn_context', 'payload' => ['turn_id' => 'turn-a']],
    ['timestamp' => '2026-09-03T00:01:00Z', 'ordinal' => 2, 'type' => 'event_msg', 'payload' => [
        'type' => 'token_count',
        'rate_limits' => [
            'limit_id' => 'codex',
            'primary' => ['used_percent' => 100, 'window_minutes' => 300, 'resets_at' => 1788400000],
            'secondary' => ['used_percent' => 75, 'window_minutes' => 10080, 'resets_at' => 1788800000],
            'rate_limit_reached_type' => null,
            'plan_type' => 'plus',
        ],
    ]],
    ['timestamp' => '2026-09-03T00:01:01Z', 'ordinal' => 3, 'type' => 'event_msg', 'payload' => [
        'type' => 'token_count',
        'rate_limits' => [
            'limit_id' => 'premium',
            'primary' => null,
            'secondary' => null,
            'rate_limit_reached_type' => null,
            'plan_type' => 'plus',
        ],
    ]],
    ['timestamp' => '2026-09-03T00:01:02Z', 'ordinal' => 4, 'type' => 'event_msg', 'payload' => [
        'type' => 'task_complete',
        'turn_id' => 'turn-a',
        'error' => [
            'message' => "You've hit your usage limit. You can try again at 1:51 PM.",
            'codex_error_info' => 'usage_limit_exceeded',
        ],
    ]],
    ['timestamp' => '2026-09-03T00:02:00Z', 'ordinal' => 5, 'type' => 'turn_context', 'payload' => ['turn_id' => 'turn-b']],
];
file_put_contents($rolloutPath, implode('', array_map(
    static fn (array $line): string => json_encode($line, JSON_UNESCAPED_SLASHES) . "\n",
    $lines,
)));
file_put_contents($messageOnlyRolloutPath, json_encode([
    'timestamp' => '2026-09-03T03:39:15Z',
    'ordinal' => 1,
    'type' => 'event_msg',
    'payload' => [
        'type' => 'task_complete',
        'turn_id' => 'message-only-turn',
        'error' => [
            'message' => "You've hit your usage limit. You can try again at 1:51 PM.",
            'codex_error_info' => 'usage_limit_exceeded',
        ],
    ],
], JSON_UNESCAPED_SLASHES) . "\n");

$state = new SQLite3($statePath);
$state->exec('CREATE TABLE threads (id TEXT, rollout_path TEXT, cwd TEXT, title TEXT, name TEXT, updated_at_ms INTEGER, created_at_ms INTEGER, archived INTEGER)');
$statement = $state->prepare('INSERT INTO threads VALUES (:id, :rollout, :cwd, :title, :name, :updated, :created, 0)');
$statement->bindValue(':id', $sessionId);
$statement->bindValue(':rollout', $rolloutPath);
$statement->bindValue(':cwd', 'C:\\workspace');
$statement->bindValue(':title', 'Fallback title');
$statement->bindValue(':name', 'Fixture session');
$statement->bindValue(':updated', 1788399999000, SQLITE3_INTEGER);
$statement->bindValue(':created', 1788399000000, SQLITE3_INTEGER);
$statement->execute();
$state->close();

$history = new SQLite3($historyPath);
$history->exec('CREATE TABLE thread_turns (thread_id TEXT, turn_id TEXT, rollout_ordinal INTEGER, status TEXT, error_json TEXT, started_at INTEGER, completed_at INTEGER, rollout_byte_offset INTEGER, rollout_end_byte_offset INTEGER)');
$statement = $history->prepare('INSERT INTO thread_turns VALUES (:thread, :turn, 5, "inProgress", NULL, 1788399900, NULL, 0, NULL)');
$statement->bindValue(':thread', $sessionId);
$statement->bindValue(':turn', 'turn-b');
$statement->execute();
$history->close();
touch($temporaryRoot . '/thread-writer-locks/' . $sessionId . '.lock');

putenv('CODEX_HOME=' . $temporaryRoot);
putenv('CODEX_STATE_DB=' . $statePath);
$config = require dirname(__DIR__) . '/src/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

try {
    $scan = (new RolloutScanner())->scan($rolloutPath, null, 1048576);
    $usageEvents = array_values(array_filter($scan['events'], static fn (array $event): bool => $event['kind'] === 'usage'));
    $usage = $usageEvents[0] ?? null;
    $limitError = array_values(array_filter($scan['events'], static fn (array $event): bool => $event['kind'] === 'rate_limit_error'))[0] ?? null;
    $assert($usage !== null && $usage['limit_reached'] === true, '100% primary usage must be detected.');
    $assert($usage['reset_at'] === 1788400000, 'Structured primary reset must be retained.');
    $assert(count($usageEvents) === 1, 'A null premium snapshot must not replace the dashboard usage event.');
    $assert($limitError !== null && $limitError['turn_id'] === 'turn-a' && $limitError['reset_at'] === 1788400000, 'Structured usage-limit failure must retain its reset time.');
    $assert(array_filter($scan['events'], static fn (array $event): bool => ($event['turn_id'] ?? null) === 'turn-b') !== [], 'Later turn must remain observable.');

    $messageOnlyScan = (new RolloutScanner())->scan($messageOnlyRolloutPath, null, 1048576);
    $messageOnlyError = array_values(array_filter($messageOnlyScan['events'], static fn (array $event): bool => $event['kind'] === 'rate_limit_error'))[0] ?? null;
    $expectedMessageReset = (new DateTimeImmutable('2026-09-03 13:51:00', new DateTimeZone('Asia/Singapore')))->getTimestamp();
    $assert($messageOnlyError !== null && $messageOnlyError['reset_at'] === $expectedMessageReset, 'The displayed retry time must be converted into a verified local reset timestamp.');

    $incrementalScan = (new RolloutScanner())->scan($messageOnlyRolloutPath, null, 1048576, $usage['usage']);
    $incrementalError = array_values(array_filter($incrementalScan['events'], static fn (array $event): bool => $event['kind'] === 'rate_limit_error'))[0] ?? null;
    $assert($incrementalError !== null && $incrementalError['reset_at'] === 1788400000, 'An incremental failure must use only its own session usage context.');

    $store = new CodexLocalStore($config);
    $thread = $store->findThread($sessionId);
    $assert($thread !== null && $thread['codex_title'] === 'Fixture session', 'Codex title must prefer the stored name.');
    $assert($store->hasActiveWriter($sessionId), 'In-progress turn plus writer lock must block resume.');

    $active = CodexRunner::classify(1, '', 'thread already has an active or pending turn');
    $limited = CodexRunner::classify(1, '', "You've hit your usage limit; try again later.");
    $missing = CodexRunner::classify(1, '', 'no rollout found for thread id 00000000-0000-4000-8000-000000000001');
    $generic = CodexRunner::classify(1, '', 'Not inside a trusted directory and --skip-git-repo-check was not specified.');
    $success = CodexRunner::classify(0, '{"type":"done"}', '');
    $assert($active['kind'] === 'active_writer', 'Active writer output must be classified.');
    $assert($limited['kind'] === 'rate_limited', 'Usage-limit output must be classified.');
    $assert($missing['kind'] === 'missing_session', 'Missing rollout output must be classified as a missing session.');
    $assert(str_contains($generic['reason'], 'Not inside a trusted directory'), 'Unexpected CLI stderr must remain visible in the failure reason.');
    $assert($success['kind'] === 'success', 'Zero exit code must be classified as success.');
    $assert(Util::isSessionId(Util::uuidV4()), 'Generated lock token must be a UUID.');

    $policy = new RecoveryPolicy($config);
    $now = 1788400000;
    $assert(!$policy->shouldQueueLimitEvent(['timestamp' => gmdate('c', $now - 21601), 'reset_at' => null], $now), 'Old reset-free events must not enter the queue.');
    $assert($policy->shouldQueueLimitEvent(['timestamp' => gmdate('c', $now - 30000), 'reset_at' => $now + 300], $now), 'A verified future reset remains eligible.');
    $assert(!$policy->shouldQueueLimitEvent(['timestamp' => gmdate('c', $now - 1000), 'reset_at' => $now - 601], $now), 'Expired reset grace must require manual review.');
    $assert($policy->isWaitingStale([
        'limit_detected_at' => gmdate('Y-m-d H:i:s', $now - 10000),
        'reset_at' => gmdate('Y-m-d H:i:s', $now - 601),
        'last_resume_attempt_at' => null,
    ], $now), 'Old waiting rows must be paused before resume.');

    $runner = new CodexRunner($config);
    $command = $runner->buildResumeCommand($sessionId);
    $assert(array_slice($command, -6) === ['exec', 'resume', '--json', '--skip-git-repo-check', $sessionId, '-'], 'Resume command must allow a non-Git project while preserving the exact UUID and stdin prompt.');
    echo "PASS: $checks checks\n";
} finally {
    $delete = static function (string $path) use (&$delete): void {
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $delete($path . DIRECTORY_SEPARATOR . $entry);
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    };
    $delete($temporaryRoot);
}
