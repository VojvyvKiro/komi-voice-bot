<?php
/** Additive migration. Run with web stopped. */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../db.php';
$p = db();
$p->exec("ALTER TABLE phrases ADD COLUMN IF NOT EXISTS import_hash BINARY(32)
    GENERATED ALWAYS AS (UNHEX(SHA2(text, 256))) PERSISTENT");
$p->exec('CREATE INDEX IF NOT EXISTS idx_phrases_import_hash ON phrases(import_hash)');
$p->exec("CREATE TABLE IF NOT EXISTS phrase_import_jobs (
    id CHAR(32) PRIMARY KEY, owner VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL, source VARCHAR(100) NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'uploading',
    total_bytes BIGINT NOT NULL, uploaded_bytes BIGINT NOT NULL DEFAULT 0,
    scan_offset BIGINT NOT NULL DEFAULT 0, `lines` BIGINT NOT NULL DEFAULT 0,
    blanks BIGINT NOT NULL DEFAULT 0, invalid BIGINT NOT NULL DEFAULT 0,
    duplicates BIGINT NOT NULL DEFAULT 0, existing_count BIGINT NOT NULL DEFAULT 0,
    unique_count BIGINT NOT NULL DEFAULT 0, added BIGINT NOT NULL DEFAULT 0,
    commit_skipped BIGINT NOT NULL DEFAULT 0, cursor_line BIGINT NOT NULL DEFAULT 0,
    issues TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$p->exec("CREATE TABLE IF NOT EXISTS phrase_import_rows (
    job_id CHAR(32) NOT NULL, line_no BIGINT NOT NULL,
    text TEXT NOT NULL, text_hash BINARY(32) NOT NULL, existing_id INT NULL,
    PRIMARY KEY(job_id, line_no), UNIQUE KEY uq_import_row_hash(job_id,text_hash),
    FOREIGN KEY(job_id) REFERENCES phrase_import_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$p->exec("CREATE TABLE IF NOT EXISTS phrase_changes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, phrase_id INT NOT NULL,
    replacement_id INT NULL, action VARCHAR(30) NOT NULL, actor VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Панель фраз: схема готова. Тексты, квоты и записи сохранены.\n";
