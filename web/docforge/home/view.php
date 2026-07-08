<?php
require_once __DIR__ . '/includes/bootstrap-page.php';

use DocForge\Core\Database;
use League\CommonMark\CommonMarkConverter;

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
$converter = new CommonMarkConverter();
$html = (string) $converter->convert($markdown);

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
