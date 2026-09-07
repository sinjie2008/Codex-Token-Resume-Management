<?php

declare(strict_types=1);

namespace CodexAutoResume;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

final class Util
{
    public static function isSessionId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    public static function title(?string $name, ?string $title): string
    {
        $candidate = trim((string) ($name ?: $title));
        if ($candidate === '') {
            return 'Unknown / Untitled';
        }

        $candidate = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $candidate) ?? '';
        $candidate = trim((string) preg_split('/\R/u', $candidate, 2)[0]);

        return self::truncate($candidate !== '' ? $candidate : 'Unknown / Untitled', 255);
    }

    public static function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }

    public static function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function dbTime(DateTimeInterface|int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $date = (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'));
        } elseif ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        } else {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        return $date->format('Y-m-d H:i:s.u');
    }

    public static function isoFromDb(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    public static function retryAtFromMessage(string $message, ?string $referenceTimestamp = null): ?int
    {
        if (preg_match('/try again at\s+(\d{1,2}):(\d{2})\s*([AP]M)\b/i', $message, $match) !== 1) {
            return null;
        }

        try {
            $timezone = new DateTimeZone(date_default_timezone_get());
            $reference = $referenceTimestamp !== null
                ? new DateTimeImmutable($referenceTimestamp)
                : new DateTimeImmutable('now', $timezone);
            $reference = $reference->setTimezone($timezone);
            $hour = ((int) $match[1]) % 12 + (strtoupper($match[3]) === 'PM' ? 12 : 0);
            $candidate = $reference->setTime($hour, (int) $match[2], 0);
            if ($candidate->getTimestamp() + 300 < $reference->getTimestamp()) {
                $candidate = $candidate->modify('+1 day');
            }

            return $candidate->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    public static function normalizeWindowsPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return str_starts_with($path, '\\\\?\\') ? substr($path, 4) : $path;
    }

    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
