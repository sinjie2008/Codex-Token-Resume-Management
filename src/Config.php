<?php

declare(strict_types=1);

namespace CodexAutoResume;

use RuntimeException;

final class Config
{
    private function __construct(
        private readonly string $root,
        private readonly array $values,
    ) {
    }

    public static function load(string $root): self
    {
        $userProfile = (string) (getenv('USERPROFILE') ?: '');
        $defaults = [
            'APP_TIMEZONE' => 'Asia/Singapore',
            'APP_ALLOWED_IPS' => '127.0.0.1,::1',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_NAME' => 'codex_auto_resume',
            'DB_USER' => 'root',
            'DB_PASSWORD' => '',
            'DB_RETRY_SECONDS' => '5',
            'CODEX_HOME' => $userProfile !== '' ? $userProfile . '\\.codex' : '',
            'CODEX_STATE_DB' => '',
            'CODEX_EXECUTABLE' => '',
            'DEFAULT_RESUME_PROMPT' => 'Continue the previous task from where you stopped. Review the existing session context first, do not redo completed work, and continue until the task is completed.',
            'WORKER_POLL_SECONDS' => '5',
            'USAGE_STALE_SECONDS' => '120',
            'WORKER_STALE_SECONDS' => '30',
            'SCAN_LOOKBACK_HOURS' => '168',
            'INITIAL_SCAN_BYTES' => '1048576',
            'RESUME_COOLDOWN_SECONDS' => '300',
            'ACTIVE_WRITER_RETRY_SECONDS' => '300',
            'RATE_LIMIT_RETRY_SECONDS' => '300',
            'UNKNOWN_RESET_BACKOFF_SECONDS' => '1800',
            'STALE_RESUME_LOCK_SECONDS' => '21600',
            'RECOVERY_MAX_LIMIT_AGE_SECONDS' => '21600',
            'POST_RESET_GRACE_SECONDS' => '600',
            'LOG_RETENTION' => '500',
        ];

        $envPath = $root . DIRECTORY_SEPARATOR . '.env';
        $fileValues = [];
        if (is_file($envPath)) {
            $parsed = parse_ini_file($envPath, false, INI_SCANNER_RAW);
            if ($parsed === false) {
                throw new RuntimeException('Unable to parse ' . $envPath);
            }
            foreach ($parsed as $key => $value) {
                $fileValues[(string) $key] = (string) $value;
            }
        }

        $values = [];
        foreach ($defaults as $key => $default) {
            $environmentValue = getenv($key);
            $values[$key] = $environmentValue !== false
                ? (string) $environmentValue
                : ($fileValues[$key] ?? $default);
        }

        date_default_timezone_set($values['APP_TIMEZONE']);

        return new self($root, $values);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function string(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw new RuntimeException('Unknown configuration key: ' . $key);
        }

        return $this->values[$key];
    }

    public function int(string $key, int $minimum = 0): int
    {
        $value = filter_var($this->string($key), FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum) {
            throw new RuntimeException($key . ' must be an integer greater than or equal to ' . $minimum);
        }

        return $value;
    }

    public function csv(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->string($key)))));
    }
}
