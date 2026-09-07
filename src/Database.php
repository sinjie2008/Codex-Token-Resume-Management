<?php

declare(strict_types=1);

namespace CodexAutoResume;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $database = $config->string('DB_NAME');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('DB_NAME may contain only letters, numbers, and underscores.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->string('DB_HOST'),
            $config->int('DB_PORT', 1),
            $database,
        );

        $pdo = new PDO($dsn, $config->string('DB_USER'), $config->string('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}

