<?php

declare(strict_types=1);

namespace CodexAutoResume;

use RuntimeException;

final class RolloutScanner
{
    public function scan(string $path, ?int $storedOffset, int $initialBytes, ?array $initialUsage = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Rollout file is unavailable: ' . $path);
        }

        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Unable to read rollout file size: ' . $path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open rollout file: ' . $path);
        }

        $offset = $storedOffset;
        if ($offset === null || $offset > $size) {
            $offset = max(0, $size - $initialBytes);
            if ($offset > 0) {
                fseek($handle, $offset);
                fgets($handle);
                $offset = (int) ftell($handle);
            }
        } else {
            fseek($handle, $offset);
        }

        $events = [];
        $currentTurnId = null;
        $latestUsage = $initialUsage;
        $lastTimestamp = null;

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }

            $timestamp = isset($row['timestamp']) ? (string) $row['timestamp'] : null;
            $lastTimestamp = $timestamp ?: $lastTimestamp;
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $type = (string) ($row['type'] ?? '');
            $payloadType = (string) ($payload['type'] ?? '');
            $ordinal = isset($row['ordinal']) ? (int) $row['ordinal'] : null;

            if ($type === 'turn_context') {
                $currentTurnId = $this->stringOrNull($payload['turn_id'] ?? null);
                $events[] = $this->activityEvent('turn_started', $timestamp, $currentTurnId, $ordinal);
                continue;
            }

            if ($type === 'event_msg' && $payloadType === 'task_started') {
                $currentTurnId = $this->stringOrNull($payload['turn_id'] ?? null) ?: $currentTurnId;
                $events[] = $this->activityEvent('turn_started', $timestamp, $currentTurnId, $ordinal);
                continue;
            }

            if ($type === 'response_item' && $payloadType === 'message' && ($payload['role'] ?? null) === 'user') {
                $events[] = $this->activityEvent('user_activity', $timestamp, $currentTurnId, $ordinal);
                continue;
            }

            if ($type === 'event_msg' && $payloadType === 'token_count' && is_array($payload['rate_limits'] ?? null)) {
                $currentUsage = $this->normalizeRateLimits($payload['rate_limits']);
                $latestUsage = $this->mergeUsage($latestUsage, $currentUsage);
                if ($currentUsage['primary'] === null
                    && $currentUsage['secondary'] === null
                    && $currentUsage['rate_limit_reached_type'] === null) {
                    continue;
                }
                $events[] = [
                    'kind' => 'usage',
                    'timestamp' => $timestamp,
                    'turn_id' => $currentTurnId,
                    'ordinal' => $ordinal,
                    'usage' => $latestUsage,
                    'limit_reached' => $this->limitReached($latestUsage),
                    'reset_at' => $this->resetTimestamp($latestUsage),
                ];
                continue;
            }

            if (($type === 'event_msg' && $payloadType === 'error') || $type === 'error') {
                $message = $this->errorMessage($payload);
                if ($message !== null && self::isRateLimitMessage($message)) {
                    $resetAt = $latestUsage !== null ? $this->resetTimestampForFailure($latestUsage) : null;
                    $events[] = [
                        'kind' => 'rate_limit_error',
                        'timestamp' => $timestamp,
                        'turn_id' => $currentTurnId,
                        'ordinal' => $ordinal,
                        'usage' => $latestUsage,
                        'limit_reached' => true,
                        'reset_at' => $resetAt ?? Util::retryAtFromMessage($message, $timestamp),
                    ];
                }
            }

            if ($type === 'event_msg' && $payloadType === 'task_complete' && $this->isUsageLimitError($payload)) {
                $message = $this->errorMessage($payload);
                $resetAt = $latestUsage !== null ? $this->resetTimestampForFailure($latestUsage) : null;
                $events[] = [
                    'kind' => 'rate_limit_error',
                    'timestamp' => $timestamp,
                    'turn_id' => $this->stringOrNull($payload['turn_id'] ?? null) ?: $currentTurnId,
                    'ordinal' => $ordinal,
                    'usage' => $latestUsage,
                    'limit_reached' => true,
                    'reset_at' => $resetAt ?? Util::retryAtFromMessage((string) $message, $timestamp),
                ];
            }
        }

        $newOffset = (int) ftell($handle);
        fclose($handle);

        return [
            'events' => $events,
            'byte_offset' => $newOffset,
            'last_event_timestamp' => $lastTimestamp,
        ];
    }

    public static function isRateLimitMessage(string $message): bool
    {
        return preg_match('/(?:rate|usage)\s+limit|too\s+many\s+requests|quota\s+(?:exceeded|reached)|limit\s+reached/i', $message) === 1;
    }

    private function activityEvent(string $kind, ?string $timestamp, ?string $turnId, ?int $ordinal): array
    {
        return [
            'kind' => $kind,
            'timestamp' => $timestamp,
            'turn_id' => $turnId,
            'ordinal' => $ordinal,
        ];
    }

    private function normalizeRateLimits(array $source): array
    {
        return [
            'limit_id' => $this->stringOrNull($source['limit_id'] ?? null),
            'limit_name' => $this->stringOrNull($source['limit_name'] ?? null),
            'primary' => $this->normalizeWindow($source['primary'] ?? null),
            'secondary' => $this->normalizeWindow($source['secondary'] ?? null),
            'rate_limit_reached_type' => $this->stringOrNull($source['rate_limit_reached_type'] ?? null),
            'plan_type' => $this->stringOrNull($source['plan_type'] ?? null),
        ];
    }

    private function normalizeWindow(mixed $window): ?array
    {
        if (!is_array($window)) {
            return null;
        }

        return [
            'used_percent' => isset($window['used_percent']) ? (float) $window['used_percent'] : null,
            'window_minutes' => isset($window['window_minutes']) ? (int) $window['window_minutes'] : null,
            'resets_at' => isset($window['resets_at']) ? (int) $window['resets_at'] : null,
        ];
    }

    private function mergeUsage(?array $previous, array $current): array
    {
        if ($previous === null) {
            return $current;
        }

        $hasCurrentWindow = $current['primary'] !== null || $current['secondary'] !== null;
        foreach (['primary', 'secondary', 'limit_name', 'plan_type'] as $key) {
            if ($current[$key] === null && array_key_exists($key, $previous)) {
                $current[$key] = $previous[$key];
            }
        }
        if (!$hasCurrentWindow
            && (($previous['primary'] ?? null) !== null || ($previous['secondary'] ?? null) !== null)) {
            $current['limit_id'] = $previous['limit_id'] ?? $current['limit_id'];
            $current['rate_limit_reached_type'] = $previous['rate_limit_reached_type']
                ?? $current['rate_limit_reached_type'];
        }

        return $current;
    }

    private function limitReached(array $usage): bool
    {
        if ($usage['rate_limit_reached_type'] !== null) {
            return true;
        }

        foreach (['primary', 'secondary'] as $key) {
            if (($usage[$key]['used_percent'] ?? null) !== null && $usage[$key]['used_percent'] >= 100) {
                return true;
            }
        }

        return false;
    }

    private function resetTimestamp(array $usage): ?int
    {
        $reachedType = strtolower((string) ($usage['rate_limit_reached_type'] ?? ''));
        foreach (['primary', 'secondary'] as $key) {
            if ($reachedType !== '' && str_contains($reachedType, $key) && isset($usage[$key]['resets_at'])) {
                return (int) $usage[$key]['resets_at'];
            }
        }

        $limitedResets = [];
        foreach (['primary', 'secondary'] as $key) {
            if (($usage[$key]['used_percent'] ?? 0) >= 100 && isset($usage[$key]['resets_at'])) {
                $limitedResets[] = (int) $usage[$key]['resets_at'];
            }
        }

        return $limitedResets !== [] ? min($limitedResets) : null;
    }

    private function errorMessage(array $payload): ?string
    {
        if (isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }
        if (isset($payload['error']['message']) && is_string($payload['error']['message'])) {
            return $payload['error']['message'];
        }
        if (isset($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
        }

        return null;
    }

    private function isUsageLimitError(array $payload): bool
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $info = strtolower((string) ($error['codex_error_info'] ?? $error['codexErrorInfo'] ?? ''));

        return $info === 'usage_limit_exceeded' || $info === 'usagelimitexceeded';
    }

    private function resetTimestampForFailure(array $usage): ?int
    {
        $selected = $this->resetTimestamp($usage);
        if ($selected !== null) {
            return $selected;
        }

        return isset($usage['primary']['resets_at']) ? (int) $usage['primary']['resets_at'] : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
