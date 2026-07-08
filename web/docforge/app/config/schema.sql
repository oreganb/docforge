-- DocForge Phase 1 schema (SPECS §7) — df_ table prefix

CREATE TABLE IF NOT EXISTS df_jobs (
  id            CHAR(26) PRIMARY KEY,
  state         ENUM('queued','running','complete','failed') NOT NULL DEFAULT 'queued',
  phase         VARCHAR(32) NULL,
  stage         VARCHAR(64) NULL,
  tool          VARCHAR(64) NULL,
  percent       TINYINT UNSIGNED DEFAULT 0,
  error         TEXT NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS df_job_uploads (
  job_id        CHAR(26) PRIMARY KEY,
  file_path     VARCHAR(512) NOT NULL,
  fingerprint   CHAR(64) NOT NULL,
  source_name   VARCHAR(255) NOT NULL,
  source_type   VARCHAR(16) NOT NULL,
  size_bytes    BIGINT UNSIGNED NOT NULL,
  mime          VARCHAR(128) NOT NULL,
  created_at    DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS df_reports (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  job_id        CHAR(26) NOT NULL,
  fingerprint   CHAR(64) NOT NULL,
  title         VARCHAR(255) NOT NULL,
  source_name   VARCHAR(255) NOT NULL,
  source_type   VARCHAR(16) NOT NULL,
  size_bytes    BIGINT UNSIGNED NOT NULL,
  excerpt       TEXT,
  knowledge_score TINYINT UNSIGNED,
  md_path       VARCHAR(255) NOT NULL,
  json_path     VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL,
  INDEX idx_fingerprint (fingerprint),
  FULLTEXT idx_search (title, excerpt)
);

CREATE TABLE IF NOT EXISTS df_report_keyphrases (
  report_id INT NOT NULL,
  phrase VARCHAR(128) NOT NULL,
  score FLOAT NOT NULL,
  INDEX idx_report (report_id),
  INDEX idx_phrase (phrase)
);

CREATE TABLE IF NOT EXISTS df_report_entities (
  report_id INT NOT NULL,
  type VARCHAR(24) NOT NULL,
  surface VARCHAR(255) NOT NULL,
  count INT NOT NULL DEFAULT 1,
  INDEX idx_report (report_id),
  INDEX idx_surface (surface)
);

CREATE TABLE IF NOT EXISTS df_report_references (
  report_id INT NOT NULL,
  raw TEXT NOT NULL,
  doi VARCHAR(128) NULL,
  url TEXT NULL,
  INDEX idx_report (report_id)
);
