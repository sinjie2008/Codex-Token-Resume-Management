<?php

declare(strict_types=1);

namespace CodexAutoResume;

use Closure;
use RuntimeException;

final class CodexRunner
{
    private ?string $resolvedExecutable = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function executable(): string
    {
        if ($this->resolvedExecutable !== null) {
            return $this->resolvedExecutable;
        }

        $configured = trim($this->config->string('CODEX_EXECUTABLE'));
        if ($configured !== '' && is_file($configured)) {
            return $this->resolvedExecutable = $configured;
        }

        $probe = $this->runProcess(['where.exe', 'codex'], null, $this->config->root(), null);
        $paths = preg_split('/\R/u', trim($probe['stdout'])) ?: [];
        foreach ($paths as $path) {
            $path = trim($path);
            if (str_ends_with(strtolower($path), '.exe') && is_file($path)) {
                return $this->resolvedExecutable = $path;
            }
        }

        throw new RuntimeException('Codex executable was not found. Set CODEX_EXECUTABLE in .env.');
    }

    public function probe(): array
    {
        $result = $this->runProcess([$this->executable(), '--version'], null, $this->config->root(), null);
        return [
            'available' => $result['exit_code'] === 0,
            'version' => trim($result['stdout']),
            'executable' => $this->executable(),
        ];
    }

    public function buildResumeCommand(string $sessionId): array
    {
        if (!Util::isSessionId($sessionId)) {
            throw new RuntimeException('Invalid Codex session ID.');
        }

        return [
            $this->executable(),
            'exec',
            'resume',
            '--json',
            '--skip-git-repo-check',
            strtolower($sessionId),
            '-',
        ];
    }

    public function resume(string $sessionId, string $prompt, ?string $projectPath, ?Closure $heartbeat = null): array
    {
        $workingDirectory = $projectPath !== null && is_dir($projectPath) ? $projectPath : $this->config->root();
        $result = $this->runProcess($this->buildResumeCommand($sessionId), $prompt, $workingDirectory, $heartbeat);
        return self::classify($result['exit_code'], $result['stdout'], $result['stderr']);
    }

    public static function classify(int $exitCode, string $stdout, string $stderr): array
    {
        if ($exitCode === 0) {
            return ['kind' => 'success', 'exit_code' => 0, 'reset_at' => null, 'reason' => null];
        }

        $combined = trim($stderr . "\n" . $stdout);
        if (preg_match('/active\s+writer|active\s+or\s+pending\s+turn|active\s+response\s+in\s+progress|thread.*already.*open|active\s+elsewhere|thread\s+writer(?:\s+coordination)?\s+lock/i', $combined) === 1) {
            return [
                'kind' => 'active_writer',
                'exit_code' => $exitCode,
                'reset_at' => null,
                'reason' => 'Codex reported that the session has an active writer.',
            ];
        }

        if (RolloutScanner::isRateLimitMessage($combined)) {
            return [
                'kind' => 'rate_limited',
                'exit_code' => $exitCode,
                'reset_at' => self::extractResetTimestamp($combined) ?? Util::retryAtFromMessage($combined),
                'reason' => 'Codex is still rate limited.',
            ];
        }

        if (preg_match('/session.*(?:not\s+found|does\s+not\s+exist)|unknown\s+(?:session|thread)|no\s+rollout\s+found\s+for\s+thread/i', $combined) === 1) {
            return [
                'kind' => 'missing_session',
                'exit_code' => $exitCode,
                'reset_at' => null,
                'reason' => 'Codex could not find the requested session.',
            ];
        }

        $detail = trim((string) preg_replace('/\s+/u', ' ', $combined));
        return [
            'kind' => 'error',
            'exit_code' => $exitCode,
            'reset_at' => null,
            'reason' => 'Codex resume exited with code ' . $exitCode . '.'
                . ($detail !== '' ? ' ' . Util::truncate($detail, 350) : ''),
        ];
    }

    private function runProcess(array $command, ?string $stdin, string $workingDirectory, ?Closure $heartbeat): array
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, null, [
            'bypass_shell' => true,
            'suppress_errors' => true,
            'create_new_console' => false,
        ]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . $command[0]);
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $lastHeartbeat = microtime(true);
        $exitCode = -1;

        while (true) {
            $stdout = $this->appendTail($stdout, (string) stream_get_contents($pipes[1]), 262144);
            $stderr = $this->appendTail($stderr, (string) stream_get_contents($pipes[2]), 65536);
            $status = proc_get_status($process);

            if ($heartbeat !== null && microtime(true) - $lastHeartbeat >= 5) {
                $heartbeat();
                $lastHeartbeat = microtime(true);
            }

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            usleep(100000);
        }

        $stdout = $this->appendTail($stdout, (string) stream_get_contents($pipes[1]), 262144);
        $stderr = $this->appendTail($stderr, (string) stream_get_contents($pipes[2]), 65536);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode < 0) {
            $exitCode = $closeCode;
        }

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function appendTail(string $existing, string $chunk, int $limit): string
    {
        if ($chunk === '') {
            return $existing;
        }

        $combined = $existing . $chunk;
        return strlen($combined) > $limit ? substr($combined, -$limit) : $combined;
    }

    private static function extractResetTimestamp(string $output): ?int
    {
        $lines = preg_split('/\R/u', $output) ?: [];
        $timestamp = null;
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }

            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : $row;
            $limits = is_array($payload['rate_limits'] ?? null) ? $payload['rate_limits'] : null;
            if ($limits === null) {
                continue;
            }

            $reached = strtolower((string) ($limits['rate_limit_reached_type'] ?? ''));
            foreach (['primary', 'secondary'] as $key) {
                $window = is_array($limits[$key] ?? null) ? $limits[$key] : null;
                if ($window === null || !isset($window['resets_at'])) {
                    continue;
                }
                if (($window['used_percent'] ?? 0) >= 100 || ($reached !== '' && str_contains($reached, $key))) {
                    $candidate = (int) $window['resets_at'];
                    $timestamp = $timestamp === null ? $candidate : min($timestamp, $candidate);
                }
            }
        }

        return $timestamp;
    }
}
