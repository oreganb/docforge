<?php
require_once __DIR__ . '/includes/bootstrap-page.php';
// On the deployed host, home/ is the site root and app/ is its sibling, so the
// library resolves under __DIR__ (matching the storage path convention below).
require_once __DIR__ . '/app/lib/Parsedown.php';

use DocForge\Core\Database;

$pdo = Database::connect($config);

$id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Report not found');
}

$stmt = $pdo->prepare('SELECT * FROM df_reports WHERE id = ?');
$stmt->execute(array($id));
$report = $stmt->fetch();
if (!$report) {
    http_response_code(404);
    exit('Report not found');
}

$mdPath = __DIR__ . '/storage/' . $report['md_path'];
if (!is_file($mdPath)) {
    http_response_code(404);
    exit('Report file missing');
}

$markdown = file_get_contents($mdPath);

// Pull the YAML frontmatter out of the body and parse it into a metrics panel —
// these are the machine-useful fields (score, class, flags, fingerprint …) that
// let a reader (or an LLM) triage the report before reading a word of it.
$meta = array();
if (preg_match('/\A---\R(.*?)\R---\R?/s', $markdown, $fm)) {
    foreach (preg_split('/\R/', $fm[1]) as $line) {
        if (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $kv)) {
            $val = trim($kv[2]);
            if (strlen($val) >= 2 && $val[0] === '"' && substr($val, -1) === '"') {
                $val = substr($val, 1, -1);
            }
            $meta[$kv[1]] = $val;
        }
    }
    $markdown = substr($markdown, strlen($fm[0]));
}

// The document title is already shown in the page header, so drop the leading
// H1 from the body to avoid a duplicate title.
$markdown = preg_replace('/\A\s*#\s+[^\n]*\R+/', '', $markdown);

$score = (isset($meta['knowledge_score']) && $meta['knowledge_score'] !== '')
    ? (int) $meta['knowledge_score'] : null;

$flags = array();
if (isset($meta['verdict_flags'])) {
    $fv = trim($meta['verdict_flags'], "[] \t");
    if ($fv !== '') {
        $flags = array_map('trim', explode(',', $fv));
    }
}

$dup = (isset($meta['duplicate_of']) && $meta['duplicate_of'] !== 'null' && $meta['duplicate_of'] !== '')
    ? (int) $meta['duplicate_of'] : 0;

$extracted = '';
if (!empty($meta['extracted_at'])) {
    $ts = strtotime($meta['extracted_at']);
    if ($ts) {
        $extracted = gmdate('j M Y, H:i', $ts) . ' UTC';
    }
}

function df_view_score_color($s)
{
    if ($s >= 85) return 'var(--df-ok)';
    if ($s >= 70) return 'var(--df-forge)';
    if ($s >= 50) return 'var(--df-warn)';
    return 'var(--df-fail)';
}

// Parsedown is a single-file, dependency-free renderer that runs on the host's
// PHP 7.3 (CommonMark v2 requires PHP >= 7.4). Safe mode escapes any raw HTML.
$parsedown = new Parsedown();
$parsedown->setSafeMode(true);
$html = $parsedown->text($markdown);

$pageTitle = $report['title'];
$activeNav = 'library';
require __DIR__ . '/includes/header.php';
?>

<main class="df-view-content">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <h1 class="df-report-title mb-0"><?php echo htmlspecialchars($report['title']); ?></h1>
    <div class="df-actions">
      <a class="btn btn-outline-forge btn-sm" href="api/download.php?id=<?php echo $id; ?>&amp;fmt=md">Download .md</a>
      <a class="btn btn-outline-ink btn-sm" href="library.php">Back to Library</a>
    </div>
  </div>

  <?php if (!empty($meta)): ?>
  <section class="df-metrics" aria-label="Report metrics">
    <?php if ($score !== null): ?>
    <div class="df-metric-score">
      <span class="df-metric-label">Knowledge Score</span>
      <span class="df-metric-score-num"><?php echo $score; ?><small>%</small></span>
      <div class="df-score-bar">
        <div class="fill" style="width:<?php echo $score; ?>%;background:<?php echo df_view_score_color($score); ?>;"></div>
      </div>
    </div>
    <?php endif; ?>
    <dl class="df-metric-list">
      <?php if (!empty($meta['doc_class'])): ?>
      <div><dt>Class</dt><dd><?php echo htmlspecialchars($meta['doc_class']); ?></dd></div>
      <?php endif; ?>
      <?php if (!empty($meta['type'])): ?>
      <div><dt>Type</dt><dd><?php echo htmlspecialchars($meta['type']); ?></dd></div>
      <?php endif; ?>
      <?php if (isset($meta['pages']) && $meta['pages'] !== ''): ?>
      <div><dt>Pages</dt><dd><?php echo htmlspecialchars($meta['pages']); ?></dd></div>
      <?php endif; ?>
      <?php if (!empty($meta['format'])): ?>
      <div><dt>Format</dt><dd><?php echo htmlspecialchars($meta['format']); ?></dd></div>
      <?php endif; ?>
      <?php if (isset($meta['rows']) && $meta['rows'] !== ''): ?>
      <div><dt>Rows</dt><dd><?php echo htmlspecialchars($meta['rows']); ?></dd></div>
      <?php endif; ?>
      <?php if (isset($meta['columns']) && $meta['columns'] !== ''): ?>
      <div><dt>Columns</dt><dd><?php echo htmlspecialchars($meta['columns']); ?></dd></div>
      <?php endif; ?>
      <?php if (!empty($meta['language'])): ?>
      <div><dt>Language</dt><dd><?php echo htmlspecialchars($meta['language']); ?></dd></div>
      <?php endif; ?>
      <div>
        <dt>Flags</dt>
        <dd>
          <?php if (empty($flags)): ?>
            <span class="df-flag df-flag-ok">None</span>
          <?php else: foreach ($flags as $flag): ?>
            <span class="df-flag df-flag-warn"><?php echo htmlspecialchars($flag); ?></span>
          <?php endforeach; endif; ?>
        </dd>
      </div>
      <?php if ($dup > 0): ?>
      <div><dt>Duplicate of</dt><dd><a href="view.php?id=<?php echo $dup; ?>">report #<?php echo $dup; ?></a></dd></div>
      <?php endif; ?>
      <?php if (!empty($meta['fingerprint'])): ?>
      <div><dt>Fingerprint</dt><dd class="df-mono" title="<?php echo htmlspecialchars($meta['fingerprint']); ?>"><?php echo htmlspecialchars(substr($meta['fingerprint'], 0, 16)); ?>&hellip;</dd></div>
      <?php endif; ?>
      <?php if ($extracted !== ''): ?>
      <div><dt>Extracted</dt><dd><?php echo htmlspecialchars($extracted); ?></dd></div>
      <?php endif; ?>
    </dl>
  </section>
  <?php endif; ?>

  <article class="report-body">
    <?php echo $html; ?>
  </article>
</main>

<?php require __DIR__ . '/includes/footer.php';
