<?php

declare(strict_types=1);

define('CODEX_AUTO_RESUME_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'CodexAutoResume\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = CODEX_AUTO_RESUME_ROOT . '/src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

return \CodexAutoResume\Config::load(CODEX_AUTO_RESUME_ROOT);

