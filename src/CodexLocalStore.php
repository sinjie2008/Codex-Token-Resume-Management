<?php

declare(strict_types=1);

namespace CodexAutoResume;

use RuntimeException;
use SQLite3;

final class CodexLocalStore
{
    private readonly string $stateDbPath;
    private readonly string $historyDbPath;
    private readonly string $writerLockDirectory;

    public function __construct(private readonly Config $config)
    {
        $home = rtrim($config->string('CODEX_HOME'), '\\/');
        $this->historyDbPath = is_file($home . '/thread_history_1.sqlite')
            ? $home . '/thread_history_1.sqlite'
            : $home . '/sqlite/thread_history_1.sqlite';
        $this->writerLockDirectory = $home . '/thread-writer-locks';
        $configured = trim($config->string('CODEX_STATE_DB'));
        if ($configured !== '') {
            $this->stateDbPath = $configured;
            return;
        }

        $candidates = [$home . '/state_5.sqlite', $home . '/sqlite/state_5.sqlite'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $this->stateDbPath = $candidate;
                return;
            }
        }

        $this->stateDbPath = $candidates[0];
    }

    public function stateDbPath(): string
    {
        return $this->stateDbPath;
    }

    public function isAvailable(): bool
    {
        return is_file($this->stateDbPath) && is_readable($this->stateDbPath);
    }

    public function findThread(string $sessionId): ?array
    {
        if (!Util::isSessionId($sessionId)) {
            return null;
        }

        $db = $this->open();
        $statement = $db->prepare(
            'SELECT id, rollout_path, cwd, substr(title, 1, 1000) AS title, name, updated_at_ms, created_at_ms,
                    archived, source, thread_source, agent_nickname, agent_role, agent_path
             FROM threads WHERE id = :id LIMIT 1'
        );
        $statement->bindValue(':id', strtolower($sessionId), SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();

        return is_array($row) && $this->classifyRow($row) === 'USER'
            ? $this->normalizeThread($row)
            : null;
    }

    public function classifyThread(string $sessionId): string
    {
        if (!Util::isSessionId($sessionId)) {
            return 'MISSING';
        }

        $db = $this->open();
        $statement = $db->prepare(
            'SELECT archived, source, thread_source, agent_nickname, agent_role, agent_path
             FROM threads WHERE id = :id LIMIT 1'
        );
        $statement->bindValue(':id', strtolower($sessionId), SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();

        return is_array($row) ? $this->classifyRow($row) : 'MISSING';
    }

    public function listRecentThreads(int $updatedSinceMs, array $includeSessionIds = []): array
    {
        $includeSessionIds = array_values(array_filter(
            array_unique(array_map('strtolower', $includeSessionIds)),
            static fn (string $id): bool => Util::isSessionId($id),
        ));

        $where = 'archived = 0 AND thread_source = "user" AND (updated_at_ms >= :updated_since';
        foreach ($includeSessionIds as $index => $_) {
            $where .= ' OR id = :include_' . $index;
        }
        $where .= ')';

        $db = $this->open();
        $statement = $db->prepare(
            'SELECT id, rollout_path, cwd, substr(title, 1, 1000) AS title, name, updated_at_ms, created_at_ms,
                    archived, source, thread_source, agent_nickname, agent_role, agent_path
             FROM threads WHERE ' . $where . ' ORDER BY updated_at_ms DESC LIMIT 1000'
        );
        $statement->bindValue(':updated_since', $updatedSinceMs, SQLITE3_INTEGER);
        foreach ($includeSessionIds as $index => $sessionId) {
            $statement->bindValue(':include_' . $index, $sessionId, SQLITE3_TEXT);
        }

        $rows = [];
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $this->normalizeThread($row);
        }
        $db->close();

        return $rows;
    }

    public function latestTurn(string $sessionId): ?array
    {
        if (!Util::isSessionId($sessionId) || !is_file($this->historyDbPath)) {
            return null;
        }

        $db = new SQLite3($this->historyDbPath, SQLITE3_OPEN_READONLY);
        $statement = $db->prepare(
            'SELECT thread_id, turn_id, rollout_ordinal, status, error_json, started_at, completed_at,
                    rollout_byte_offset, rollout_end_byte_offset
             FROM thread_turns WHERE thread_id = :thread_id ORDER BY started_at DESC LIMIT 1'
        );
        $statement->bindValue(':thread_id', strtolower($sessionId), SQLITE3_TEXT);
        $row = $statement->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        if (!is_array($row)) {
            return null;
        }

        $error = is_string($row['error_json'] ?? null) ? json_decode($row['error_json'], true) : null;
        $row['error_info'] = is_array($error)
            ? ($error['codexErrorInfo'] ?? $error['codex_error_info'] ?? null)
            : null;
        unset($row['error_json']);

        return $row;
    }

    public function hasActiveWriter(string $sessionId): bool
    {
        $turn = $this->latestTurn($sessionId);
        if (($turn['status'] ?? null) !== 'inProgress') {
            return false;
        }

        return is_file($this->writerLockDirectory . '/' . strtolower($sessionId) . '.lock');
    }

    private function open(): SQLite3
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Codex state database is unavailable: ' . $this->stateDbPath);
        }

        return new SQLite3($this->stateDbPath, SQLITE3_OPEN_READONLY);
    }

    private function normalizeThread(array $row): array
    {
        return [
            'session_id' => strtolower((string) $row['id']),
            'codex_title' => Util::title($row['name'] ?? null, $row['title'] ?? null),
            'project_path' => Util::normalizeWindowsPath($row['cwd'] ?? null),
            'rollout_path' => Util::normalizeWindowsPath($row['rollout_path'] ?? null),
            'updated_at_ms' => (int) ($row['updated_at_ms'] ?? 0),
            'created_at_ms' => (int) ($row['created_at_ms'] ?? 0),
            'archived' => (bool) ($row['archived'] ?? false),
        ];
    }

    private function classifyRow(array $row): string
    {
        if ((bool) ($row['archived'] ?? false)) {
            return 'ARCHIVED';
        }

        if (($row['thread_source'] ?? null) === 'user') {
            return 'USER';
        }

        if (($row['thread_source'] ?? null) === 'subagent'
            || ($row['agent_path'] ?? null) !== null
            || str_contains((string) ($row['source'] ?? ''), '"subagent"')) {
            return 'INTERNAL';
        }

        return 'UNKNOWN';
    }
}
