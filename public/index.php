<?php

declare(strict_types=1);

use CodexAutoResume\Http;

$config = require dirname(__DIR__) . '/src/bootstrap.php';
Http::requireLocal($config);
Http::startSession();
$csrfToken = Http::csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Codex Auto Resume</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="shell">
    <header class="hero">
        <div>
            <p class="eyebrow">LOCAL WINDOWS UTILITY</p>
            <h1>Codex Auto Resume</h1>
            <p class="lede">Wait locally. Resume the exact session only when it is safe.</p>
        </div>
        <div id="worker-pill" class="pill muted">Checking worker</div>
    </header>

    <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>

    <section class="status-grid" aria-label="Codex status">
        <article class="metric">
            <span>Codex storage</span>
            <strong id="storage-status">Checking</strong>
            <small id="last-scan">No scan yet</small>
        </article>
        <article class="metric">
            <span>5-hour usage</span>
            <strong id="primary-usage">Unavailable</strong>
            <small id="primary-reset">No verified reset</small>
        </article>
        <article class="metric">
            <span>Weekly usage</span>
            <strong id="secondary-usage">Unavailable</strong>
            <small id="secondary-reset">No verified reset</small>
        </article>
        <article class="metric">
            <span>Rate-limit state</span>
            <strong id="limit-state">Unknown</strong>
            <small id="usage-updated">No local usage event</small>
        </article>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">MANAGED SESSIONS</p>
                <h2>Waiting queue</h2>
            </div>
            <button type="button" class="button secondary" id="toggle-add">+ Add Session</button>
        </div>

        <form id="add-form" class="stack-form" hidden>
            <label>
                Codex Session ID
                <input name="session_id" autocomplete="off" required placeholder="019a03a5-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
            </label>
            <label>
                Custom display name
                <input name="display_name" maxlength="255" placeholder="Optional">
            </label>
            <label class="wide">
                Resume prompt
                <textarea name="resume_prompt" maxlength="8000" rows="3" placeholder="Optional; the global default is used when blank"></textarea>
            </label>
            <div class="form-actions wide">
                <button class="button primary" type="submit">Add to monitoring</button>
                <button class="button ghost" type="button" id="close-add">Cancel</button>
            </div>
        </form>

        <div id="sessions" class="session-list" aria-live="polite">
            <p class="empty">Loading managed sessions...</p>
        </div>
    </section>

    <section class="two-column">
        <article class="panel">
            <div class="panel-heading compact">
                <div>
                    <p class="eyebrow">ACTIVITY</p>
                    <h2>Recent events</h2>
                </div>
                <button id="clear-logs" class="button danger" type="button" aria-controls="logs">Clear all</button>
            </div>
            <ol id="logs" class="log-list">
                <li class="empty">No events yet.</li>
            </ol>
        </article>

        <article class="panel">
            <div class="panel-heading compact">
                <div>
                    <p class="eyebrow">SETTINGS</p>
                    <h2>Resume behavior</h2>
                </div>
            </div>
            <form id="settings-form" class="settings-form">
                <label>
                    Global default resume prompt
                    <textarea name="default_resume_prompt" maxlength="8000" rows="6"></textarea>
                </label>
                <button class="button secondary" type="submit">Save default prompt</button>
            </form>
            <p class="safety-note">Active-writer conflicts pause locally. This tool never kills Codex Desktop, creates a fork, or polls the model while waiting.</p>
        </article>
    </section>
</main>
<script src="app.js" defer></script>
</body>
</html>
