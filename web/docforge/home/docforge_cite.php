<?php
require_once __DIR__ . '/includes/bootstrap-page.php';

$pageTitle = 'Forge Cite — Reference Suitability';
$activeNav = 'cite';
$mainClass = 'df-home df-home-claude df-cite-page';
$csrfToken = \DocForge\Core\Csrf::token();
$hideNav = true;
$bodyClass = 'df-body-home';

require __DIR__ . '/includes/header.php';
?>

<main class="<?php echo $mainClass; ?>" id="view-cite">
  <div class="df-center">
    <div class="df-hero">
      <img class="df-logo" src="<?php echo $assetBase; ?>images/docforge_logo.png" alt="DocForge">
    </div>

    <p class="df-cite-lead">Score how suitable a candidate reference is for <em>your</em> document — passage by passage, with evidence terms shown. Nothing is generated; uploads are not saved to the Library.</p>

    <div id="cite-idle">
      <div class="df-cite-uploads">
        <section class="df-cite-zone" aria-labelledby="citeWorkingLabel">
          <h2 class="df-cite-zone-title" id="citeWorkingLabel">Your document</h2>
          <p class="df-cite-zone-hint">Report, proposal, or paper you are writing</p>
          <div class="df-drop df-drop-sm" id="dropWorking" tabindex="0" role="button"
               aria-label="Upload your working document">
            <i class="bi bi-file-earmark-text df-drop-icon" aria-hidden="true"></i>
            <p class="df-drop-label" id="workingText">Drag &amp; drop or click to browse</p>
            <div class="df-file d-none" id="workingChip">
              <i class="bi bi-file-earmark-text"></i>
              <span id="workingName"></span>
              <button type="button" class="clear" id="clearWorking" aria-label="Remove">&times;</button>
            </div>
            <input type="file" id="workingInput" class="d-none"
                   accept=".pdf,.docx,.md,.txt,.markdown">
          </div>
        </section>

        <section class="df-cite-zone" aria-labelledby="citeRefLabel">
          <h2 class="df-cite-zone-title" id="citeRefLabel">Candidate reference(s)</h2>
          <p class="df-cite-zone-hint">Paper or report you want to cite — add one or more</p>
          <div class="df-drop df-drop-sm" id="dropRefs" tabindex="0" role="button"
               aria-label="Upload candidate references">
            <i class="bi bi-journal-text df-drop-icon" aria-hidden="true"></i>
            <p class="df-drop-label" id="refsText">Drag &amp; drop or click to browse</p>
            <ul class="df-cite-ref-list d-none" id="refsList"></ul>
            <input type="file" id="refsInput" class="d-none" multiple
                   accept=".pdf,.docx,.md,.txt,.markdown">
          </div>
        </section>
      </div>

      <button type="button" class="btn btn-forge df-cite-run" id="citeRunBtn" disabled>Run Forge Cite</button>

      <div class="df-quick-actions">
        <a href="index.php" class="df-pill">
          <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i>
          Forge Convert
        </a>
        <a href="library.php" class="df-pill">
          <i class="bi bi-collection" aria-hidden="true"></i>
          Library
        </a>
      </div>
    </div>

    <div id="cite-processing" class="d-none" aria-live="polite">
      <div class="df-progress"><div class="bar" id="citeProgBar" style="width:40%"></div></div>
      <p class="df-status">Analysing passages and scoring references…</p>
    </div>

    <div id="cite-results" class="d-none">
      <div class="df-cite-results-head">
        <h2 class="df-cite-results-title" id="citeResultTitle"></h2>
        <p class="df-cite-results-meta" id="citeResultMeta"></p>
        <div class="df-cite-legend" aria-label="Score colour key">
          <span class="df-cite-tier df-cite-tier-great">≥ 0.85 Great</span>
          <span class="df-cite-tier df-cite-tier-okay">0.75–0.84 Okay</span>
          <span class="df-cite-tier df-cite-tier-poor">0.66–0.74 Poor</span>
          <span class="df-cite-tier-none">≤ 0.65 No highlight</span>
          <span class="df-cite-legend-note">Passage text is shaded to match its best reference affinity.</span>
        </div>
        <div class="df-cite-results-actions">
          <button type="button" class="btn btn-outline-ink btn-sm" id="citeDownloadMd">Download .md</button>
          <button type="button" class="btn btn-link btn-sm" id="citeReset">Analyse another</button>
        </div>
      </div>
      <article class="report-body df-cite-report" id="citeReportHtml"></article>
    </div>

    <div id="cite-failed" class="d-none">
      <div class="df-done">
        <p class="df-fail-msg mb-2" id="citeFailMsg"></p>
        <button type="button" class="btn btn-outline-ink" id="citeRetry">Try again</button>
      </div>
    </div>
  </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
<?php
$pageScript = 'cite.js';
require __DIR__ . '/includes/footer.php';
