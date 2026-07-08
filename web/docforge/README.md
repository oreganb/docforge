# DocForge setup

## Requirements

- PHP **7.4+** (targeting PHP 7 server compatibility — no PHP 8-only syntax)
- MariaDB / MySQL
- Composer
- Optional: `pdftotext` (poppler-utils) for better PDF text extraction

## Install

```bash
cd web/docforge/app
composer install
```

Create the database and import schema:

```bash
mysql -u root -p -e "CREATE DATABASE docforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p docforge < config/schema.sql
```

Set environment variables (or edit `app/config/config.php`):

- `DOCFORGE_DB_HOST`
- `DOCFORGE_DB_PORT`
- `DOCFORGE_DB_NAME`
- `DOCFORGE_DB_USER`
- `DOCFORGE_DB_PASS`

## Web root

Point your vhost document root at:

```
web/docforge/home
```

`app/` and `storage/` are denied by `.htaccess`.

## Cron

Hourly upload sweeper (FR-9):

```
0 * * * * php /path/to/web/docforge/app/bin/sweep-uploads.php
```

## Phase 1 scope

- Upload → fingerprint → job queue → PDF/DOCX/MD/TXT parsing
- PHP-native analysis (TextRank, RAKE, pattern references, statistics)
- Markdown + JSON export → library

Sidecar services (GROBID, spaCy, camelot, OCR) are Phase 2.
