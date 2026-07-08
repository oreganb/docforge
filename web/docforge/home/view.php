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

// Strip the YAML frontmatter block — it is machine-triage metadata, shown here
// natively in the Document Metadata section, so it would only clutter the view.
$markdown = preg_replace('/\A---\R.*?\R---\R+/s', '', $markdown);

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
    <h1 class="h3 mb-0"><?php echo htmlspecialchars($report['title']); ?></h1>
    <div class="df-actions">
      <a class="btn btn-outline-forge btn-sm" href="api/download.php?id=<?php echo $id; ?>&amp;fmt=md">Download .md</a>
      <a class="btn btn-outline-ink btn-sm" href="library.php">Back to Library</a>
    </div>
  </div>
  <article class="report-body">
    <?php echo $html; ?>
  </article>
</main>

<?php require __DIR__ . '/includes/footer.php';
