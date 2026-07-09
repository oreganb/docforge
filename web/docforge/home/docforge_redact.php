<?php
require_once __DIR__ . '/includes/bootstrap-page.php';

$pageTitle = 'Forge Redact — PII Redaction';
$activeNav = 'redact';
$mainClass = 'df-home df-home-claude df-redact-page';
$csrfToken = \DocForge\Core\Csrf::token();
$hideNav = true;
$bodyClass = 'df-body-home';

require __DIR__ . '/includes/header.php';
?>

<main class="<?php echo $mainClass; ?>" id="view-redact">
  <div class="df-center">
    <div class="df-hero">
      <img class="df-logo" src="<?php echo $assetBase; ?>images/docforge_logo.png" alt="DocForge">
    </div>

    <p class="df-redact-lead">Remove names, emails, PPSNs, IBANs, phones, Eircodes, and more before a document leaves your machine. Deterministic tiers validate checksums; nothing is stored in the Library.</p>

    <div id="redact-idle">
      <div class="df-drop" id="dropDoc" tabindex="0" role="button" aria-label="Upload document to redact">
        <i class="bi bi-shield-lock df-drop-icon" aria-hidden="true"></i>
        <p class="df-drop-label" id="docText">Drag &amp; drop a document, or click to browse<br><span class="df-drop-formats">PDF, DOCX, Markdown, or plain text</span></p>
        <div class="df-file d-none" id="docChip">
          <i class="bi bi-file-earmark-text"></i>
          <span id="docName"></span>
          <button type="button" class="clear" id="clearDoc" aria-label="Remove">&times;</button>
        </div>
        <input type="file" id="docInput" class="d-none" accept=".pdf,.docx,.md,.txt,.markdown">
      </div>

      <fieldset class="df-redact-options">
        <legend class="df-redact-options-title">Redaction mode</legend>
        <label class="df-redact-mode">
          <input type="radio" name="redactMode" value="token" checked>
          <span><strong>Token</strong> — <code>[PERSON-1]</code>, <code>[EMAIL-1]</code> (default for LLMs; preserves structure)</span>
        </label>
        <label class="df-redact-mode">
          <input type="radio" name="redactMode" value="mask">
          <span><strong>Mask</strong> — replace with <code>XXXXXXXXX</code> (human disclosure copy)</span>
        </label>
        <label class="df-redact-retain" id="retainMapWrap">
          <input type="checkbox" id="retainMap" checked>
          Include re-identification map in the report (token mode only)
        </label>
      </fieldset>

      <button type="button" class="btn btn-forge df-redact-run" id="redactRunBtn" disabled>Run Forge Redact</button>

      <div class="df-quick-actions">
        <a href="index.php" class="df-pill">
          <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i>
          Forge Convert
        </a>
        <a href="docforge_cite.php" class="df-pill">
          <i class="bi bi-journal-check" aria-hidden="true"></i>
          Forge Cite
        </a>
        <a href="library.php" class="df-pill">
          <i class="bi bi-collection" aria-hidden="true"></i>
          Library
        </a>
      </div>
    </div>

    <div id="redact-processing" class="d-none" aria-live="polite">
      <div class="df-progress"><div class="bar" style="width:45%"></div></div>
      <p class="df-status">Detecting and redacting identifying information…</p>
    </div>

    <div id="redact-results" class="d-none">
      <div class="df-redact-results-head">
        <h2 class="df-redact-results-title" id="redactResultTitle"></h2>
        <p class="df-redact-results-meta" id="redactResultMeta"></p>
        <div class="df-redact-results-actions">
          <button type="button" class="btn btn-outline-ink btn-sm" id="redactDownloadMd">Download .md</button>
          <button type="button" class="btn btn-outline-ink btn-sm d-none" id="redactDownloadMap">Download map (.json)</button>
          <button type="button" class="btn btn-link btn-sm" id="redactReset">Redact another</button>
        </div>
      </div>
      <article class="report-body df-redact-report" id="redactReportHtml"></article>
    </div>

    <div id="redact-failed" class="d-none">
      <div class="df-done">
        <p class="df-fail-msg mb-2" id="redactFailMsg"></p>
        <button type="button" class="btn btn-outline-ink" id="redactRetry">Try again</button>
      </div>
    </div>
  </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
<?php
$pageScript = 'redact.js';
require __DIR__ . '/includes/footer.php';
