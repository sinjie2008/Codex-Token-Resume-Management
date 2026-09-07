CREATE TABLE IF NOT EXISTS codex_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    codex_title VARCHAR(255) NOT NULL DEFAULT 'Unknown / Untitled',
    display_name VARCHAR(255) NULL,
    project_path TEXT NULL,
    rollout_path TEXT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'WATCHING',
    resume_prompt TEXT NULL,
    auto_resume TINYINT(1) NOT NULL DEFAULT 1,
    limit_detected_at DATETIME(6) NULL,
    limit_turn_id VARCHAR(64) NULL,
    limit_event_ordinal BIGINT NULL,
    reset_at DATETIME(6) NULL,
    next_retry_at DATETIME(6) NULL,
    last_codex_activity_at DATETIME(6) NULL,
    last_codex_turn_id VARCHAR(64) NULL,
    last_resume_attempt_at DATETIME(6) NULL,
    last_resume_success_at DATETIME(6) NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    resume_count INT UNSIGNED NOT NULL DEFAULT 0,
    resume_lock CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    resume_lock_at DATETIME(6) NULL,
    active_writer_detected_at DATETIME(6) NULL,
    active_writer_retry_at DATETIME(6) NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_codex_sessions_session_id (session_id),
    KEY idx_codex_sessions_due (auto_resume, status, next_retry_at, reset_at),
    KEY idx_codex_sessions_lock (resume_lock, resume_lock_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    setting_value LONGTEXT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(50) NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_activity_logs_created (created_at),
    KEY idx_activity_logs_session (session_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS codex_scan_state (
    session_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rollout_path TEXT NOT NULL,
    byte_offset BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_event_timestamp DATETIME(6) NULL,
    last_usage_json LONGTEXT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
