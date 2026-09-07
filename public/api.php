<?php

declare(strict_types=1);

use CodexAutoResume\CodexLocalStore;
use CodexAutoResume\Database;
use CodexAutoResume\Http;
use CodexAutoResume\SessionRepository;
use CodexAutoResume\Util;

$config = require dirname(__DIR__) . '/src/bootstrap.php';
Http::requireLocal($config);
Http::startSession();

try {
    $pdo = Database::connect($config);
    $repository = new SessionRepository($pdo, $config);
    $repository->assertSchema();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $heartbeat = $repository->getSetting('worker_heartbeat_at');
        $heartbeatUnix = $heartbeat !== null ? strtotime($heartbeat . ' UTC') : false;
        $workerOnline = $heartbeatUnix !== false
            && time() - $heartbeatUnix <= $config->int('WORKER_STALE_SECONDS', 1);
        Http::json([
            'ok' => true,
            'worker' => [
                'online' => $workerOnline,
                'heartbeat_at' => Util::isoFromDb($heartbeat),
                'pid' => $repository->getSetting('worker_pid'),
                'mode' => $repository->getSetting('worker_mode'),
            ],
            'codex' => [
                'storage_status' => $repository->getSetting('codex_storage_status') ?? 'UNKNOWN',
                'last_scan_at' => Util::isoFromDb($repository->getSetting('last_codex_scan_at')),
                'version' => $repository->getSetting('codex_version'),
            ],
            'usage' => $repository->usageSnapshot(),
            'sessions' => array_map([Http::class, 'sessionForJson'], $repository->listSessions()),
            'logs' => array_map(static function (array $log): array {
                $log['id'] = (int) $log['id'];
                $log['created_at'] = Util::isoFromDb($log['created_at']);
                return $log;
            }, $repository->listLogs()),
            'settings' => [
                'default_resume_prompt' => $repository->getSetting('default_resume_prompt')
                    ?? $config->string('DEFAULT_RESUME_PROMPT'),
            ],
        ]);
    }

    if ($method !== 'POST') {
        Http::json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    Http::requireCsrf();
    $input = Http::input();
    $action = (string) ($input['action'] ?? '');

    if ($action === 'clear_logs') {
        $deleted = $repository->clearLogs();
        Http::json([
            'ok' => true,
            'message' => $deleted === 1
                ? '1 activity event cleared.'
                : $deleted . ' activity events cleared.',
        ]);
    }

    if ($action === 'add_session') {
        $sessionId = strtolower(trim((string) ($input['session_id'] ?? '')));
        if (!Util::isSessionId($sessionId)) {
            Http::json(['ok' => false, 'message' => 'Enter a valid Codex session UUID.'], 422);
        }
        $thread = (new CodexLocalStore($config))->findThread($sessionId);
        if ($thread === null) {
            Http::json(['ok' => false, 'message' => 'This session ID was not found in the local Codex state database.'], 404);
        }
        $session = $repository->upsertManual(
            $thread,
            isset($input['display_name']) ? (string) $input['display_name'] : null,
            isset($input['resume_prompt']) ? (string) $input['resume_prompt'] : null,
        );
        Http::json(['ok' => true, 'message' => 'Session added to monitoring.', 'session' => Http::sessionForJson($session)]);
    }

    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (in_array($action, ['update_session', 'resume_now', 'cancel', 'enable', 'remove_session'], true) && ($id === false || $id < 1)) {
        Http::json(['ok' => false, 'message' => 'Invalid managed session.'], 422);
    }

    if ($action === 'update_session') {
        $updated = $repository->updateSession(
            $id,
            isset($input['display_name']) ? (string) $input['display_name'] : null,
            isset($input['resume_prompt']) ? (string) $input['resume_prompt'] : null,
        );
        Http::json(['ok' => $updated, 'message' => $updated ? 'Session settings saved.' : 'Session was not changed.']);
    }

    if ($action === 'resume_now') {
        $queued = $repository->queueResumeNow($id);
        if ($queued) {
            $session = $repository->findById($id);
            $repository->log($session['session_id'] ?? null, 'RESUME_REQUESTED', 'Manual Resume Now request queued for the worker.');
        }
        Http::json(['ok' => $queued, 'message' => $queued
            ? 'Resume requested. The worker will run all safety checks first.'
            : 'The session is already resuming or could not be queued.']);
    }

    if ($action === 'cancel') {
        $cancelled = $repository->cancel($id);
        if ($cancelled) {
            $session = $repository->findById($id);
            $repository->log($session['session_id'] ?? null, 'AUTO_RESUME_CANCELLED', 'Automatic resume disabled for this session.');
        }
        Http::json(['ok' => $cancelled, 'message' => $cancelled
            ? 'Automatic resume cancelled for this session.'
            : 'A session that is already resuming cannot be cancelled here.']);
    }

    if ($action === 'enable') {
        $enabled = $repository->enable($id);
        Http::json(['ok' => $enabled, 'message' => $enabled ? 'Automatic resume enabled.' : 'Session could not be enabled.']);
    }

    if ($action === 'remove_session') {
        $sessionId = $repository->removeSession($id);
        if ($sessionId !== null) {
            $repository->log($sessionId, 'SESSION_REMOVED', 'Session removed from Auto Resume; the original Codex conversation was not deleted.');
        }
        Http::json(['ok' => $sessionId !== null, 'message' => $sessionId !== null
            ? 'Session deleted from the current queue. A future rate-limit event can add it again automatically; the original Codex conversation was not deleted.'
            : 'A session that is already resuming cannot be deleted here.']);
    }

    if ($action === 'save_settings') {
        $prompt = trim((string) ($input['default_resume_prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $config->string('DEFAULT_RESUME_PROMPT');
        }
        $repository->setSetting('default_resume_prompt', Util::truncate($prompt, 8000));
        Http::json(['ok' => true, 'message' => 'Default resume prompt saved.']);
    }

    Http::json(['ok' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $error) {
    error_log('Codex Auto Resume API: ' . $error->getMessage());
    $status = str_contains(strtolower($error->getMessage()), 'request token') ? 403 : 503;
    Http::json([
        'ok' => false,
        'message' => $status === 403
            ? $error->getMessage()
            : 'The local database is unavailable. Run the installer or check .env and MySQL.',
    ], $status);
}
