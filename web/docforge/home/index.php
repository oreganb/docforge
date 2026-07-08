<?php
require_once __DIR__ . '/includes/bootstrap-page.php';

$pageTitle = 'Understand documents. Don\'t just parse them.';
$activeNav = 'home';
$mainClass = 'df-home';
$csrfToken = \DocForge\Core\Csrf::token();

require __DIR__ . '/includes/header.php';
?>

<main class="<?php echo $mainClass; ?>" id="view-home">
  <div class="df-hero">
    <img src="<?php echo $assetBase; ?>images/logo-full.png" alt="DocForge — anvil forging a document into Markdown">
    <p class="df-tagline">Understand documents. Don't just parse them.</p>
    <p class="df-sub">Drop any document. Get one trusted Markdown report.</p>
  </div>

  <div id="state-idle">
    <div class="df-drop" id="drop" tabindex="0" role="button" aria-label="Drag and drop a file, or press Enter to browse">
      <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i>
      <p id="dropText">Drag &amp; drop a file, or click to browse</p>
      <div class="df-file d-none" id="fileChip">
        <i class="bi bi-file-earmark-text"></i>
        <span id="fileName"></span><span class="size" id="fileSize"></span>
        <button type="button" class="clear" id="clearFile" aria-label="Remove file">&times;</button>
      </div>
      <input type="file" id="fileInput" class="d-none" accept=".pdf,.docx,.md,.txt,.markdown">
    </div>
    <div class="text-center mt-4">
      <button type="button" class="btn btn-forge btn-lg" id="runBtn" disabled>Run</button>
    </div>
  </div>

  <div id="state-processing" class="d-none" aria-live="polite" aria-atomic="true">
    <div class="df-progress"><div class="bar" id="progBar"></div></div>
    <p class="df-status" id="progLine"><span class="phase">Forge Read</span> · 0% — starting</p>
  </div>

  <div id="state-complete" class="d-none">
    <div class="df-done">
      <h4 class="mb-2" id="doneTitle" style="font-weight:650;"></h4>
      <div class="d-flex align-items-center gap-3 mb-1">
        <div class="df-score-bar"><div class="fill" id="scoreFill"></div></div>
        <strong id="scoreNum"></strong>
        <span class="df-stars" id="scoreStars" aria-label="Knowledge Score stars"></span>
      </div>
      <p class="df-meta mb-3" id="scoreMeta" style="font-size:.8rem;color:var(--df-slate-soft);"></p>
      <p class="df-summary mb-3" id="doneSummary"></p>
      <div class="d-flex gap-2 flex-wrap align-items-center" id="doneActions">
        <a class="btn btn-forge" id="dlMd" href="#">Download .md</a>
        <a class="btn btn-outline-ink" id="dlJson" href="#">Download .json</a>
        <a href="library.php" class="ms-1">View in Library</a>
        <button type="button" class="btn btn-link ms-auto" id="resetBtn" style="color:var(--df-slate);font-size:.9rem;">Run another</button>
      </div>
    </div>
  </div>

  <div id="state-failed" class="d-none">
    <div class="df-done">
      <p class="df-fail-msg mb-2" id="failMsg"></p>
      <button type="button" class="btn btn-outline-ink" id="resetFailBtn">Try again</button>
    </div>
  </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
<?php
$pageScript = 'docforge.js';
require __DIR__ . '/includes/footer.php';
