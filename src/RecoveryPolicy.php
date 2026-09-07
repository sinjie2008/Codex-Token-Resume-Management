<?php

declare(strict_types=1);

namespace CodexAutoResume;

final class RecoveryPolicy
{
    public function __construct(private readonly Config $config)
    {
    }

    public function shouldQueueLimitEvent(array $event, ?int $now = null): bool
    {
        $eventTime = strtotime((string) ($event['timestamp'] ?? ''));
        if ($eventTime === false) {
            return false;
        }

        $now ??= time();
        $resetAt = isset($event['reset_at']) && is_numeric($event['reset_at']) ? (int) $event['reset_at'] : null;
        if ($resetAt !== null && $resetAt > $now) {
            return true;
        }
        if ($resetAt !== null) {
            return $now - $resetAt <= $this->config->int('POST_RESET_GRACE_SECONDS', 0);
        }

        return $now - $eventTime <= $this->config->int('RECOVERY_MAX_LIMIT_AGE_SECONDS', 60);
    }

    public function isWaitingStale(array $session, ?int $now = null): bool
    {
        $now ??= time();
        $maxAge = $this->config->int('RECOVERY_MAX_LIMIT_AGE_SECONDS', 60);
        $postResetGrace = $this->config->int('POST_RESET_GRACE_SECONDS', 0);
        $limitAt = strtotime((string) ($session['limit_detected_at'] ?? '') . ' UTC');
        $resetAt = isset($session['reset_at']) && $session['reset_at'] !== null
            ? strtotime($session['reset_at'] . ' UTC')
            : false;
        $attemptAt = isset($session['last_resume_attempt_at']) && $session['last_resume_attempt_at'] !== null
            ? strtotime($session['last_resume_attempt_at'] . ' UTC')
            : false;

        if ($attemptAt !== false) {
            return $now - $attemptAt > $maxAge;
        }
        if ($resetAt !== false) {
            return $now - $resetAt > $postResetGrace;
        }

        return $limitAt !== false && $now - $limitAt > $maxAge;
    }
}

