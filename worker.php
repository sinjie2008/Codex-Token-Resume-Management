<?php

declare(strict_types=1);

use CodexAutoResume\CodexLocalStore;
use CodexAutoResume\CodexRunner;
use CodexAutoResume\Database;
use CodexAutoResume\RolloutScanner;
use CodexAutoResume\SessionRepository;
use CodexAutoResume\Worker;

$config = require __DIR__ . '/src/bootstrap.php';
$once = in_array('--once', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

do {
    try {
        $pdo = Database::connect($config);
        $repository = new SessionRepository($pdo, $config);
        $repository->assertSchema();
        if (!$repository->acquireWorkerLock()) {
            fwrite(STDOUT, "Another worker already holds the queue lock.\n");
            exit(0);
        }

        try {
            $worker = new Worker(
                $config,
                $repository,
                new CodexLocalStore($config),
                new RolloutScanner(),
                new CodexRunner($config),
                $dryRun,
            );
            $worker->run($once);
        } finally {
            $repository->releaseWorkerLock();
        }
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, '[' . date('c') . '] Worker unavailable: ' . $error->getMessage() . PHP_EOL);
        if ($once) {
            exit(1);
        }
        sleep($config->int('DB_RETRY_SECONDS', 1));
    }
} while (true);
