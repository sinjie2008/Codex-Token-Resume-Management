<?php

declare(strict_types=1);

namespace CodexAutoResume;

use RuntimeException;

final class Http
{
    public static function requireLocal(Config $config): void
    {
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remoteAddress, $config->csv('APP_ALLOWED_IPS'), true)) {
            http_response_code(403);
            exit('Local access only.');
        }
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('codex_auto_resume');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Strict',
            'path' => '/',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function requireCsrf(): void
    {
        $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($provided === '' || !hash_equals(self::csrfToken(), $provided)) {
            throw new RuntimeException('Invalid request token. Reload the dashboard and try again.');
        }
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function input(): array
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new RuntimeException('Request body must be valid JSON.');
        }

        return $input;
    }

    public static function sessionForJson(array $session): array
    {
        foreach ([
            'limit_detected_at', 'reset_at', 'next_retry_at', 'last_codex_activity_at', 'last_resume_attempt_at',
            'last_resume_success_at', 'resume_lock_at', 'active_writer_detected_at', 'active_writer_retry_at',
            'created_at', 'updated_at',
        ] as $key) {
            $session[$key] = Util::isoFromDb($session[$key] ?? null);
        }
        $session['id'] = (int) $session['id'];
        $session['auto_resume'] = (bool) $session['auto_resume'];
        $session['retry_count'] = (int) $session['retry_count'];
        $session['resume_count'] = (int) $session['resume_count'];

        return $session;
    }
}

