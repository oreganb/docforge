<?php
require_once __DIR__ . '/includes/bootstrap-page.php';

use DocForge\Core\Database;
use DocForge\Core\Csrf;

$pageTitle = 'Library';
$activeNav = 'library';
$csrfToken = Csrf::token();
$pdo = Database::connect($config);

$page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$perPage = (int) $config['limits']['library_per_page'];
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = array();
if ($q !== '') {
    $where .= ' AND MATCH(title, excerpt) AGAINST (? IN BOOLEAN MODE)';
    $params[] = $q . '*';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM df_reports WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($total / $perPage));

$listParams = array_merge($params, array($perPage, $offset));
$stmt = $pdo->prepare(
    "SELECT id, title, excerpt, source_type, size_bytes, created_at, knowledge_score
     FROM df_reports WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
foreach ($listParams as $i => $val) {
    $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$items = $stmt->fetchAll();

function df_format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

function df_score_color($score) {
    if ($score >= 85) return 'var(--df-ok)';
    if ($score >= 70) return 'var(--df-forge)';
    if ($score >= 50) return 'var(--df-warn)';
    return 'var(--df-fail)';
}

require __DIR__ . '/includes/header.php';
?>

<input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
<main class="df-lib" id="view-library">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h3 class="mb-0">Library</h3>
    <div class="df-search flex-grow-1 d-flex justify-content-end">
      <input type="search" class="form-control" placeholder="Search reports…" id="libSearch"
             value="<?php echo htmlspecialchars($q); ?>" aria-label="Search reports">
    </div>
  </div>

  <?php if (!empty($items)): ?>
  <div class="df-merge-bar" id="mergeBar" role="region" aria-label="Report actions">
    <span class="df-merge-count" id="mergeCount">Select 2+ reports to merge or cite</span>
    <label class="df-merge-compact">
      <input type="checkbox" id="mergeCompact"> Compact (context profile)
    </label>
    <button type="button" class="btn btn-forge" id="mergeBtn" disabled>Forge Merge</button>
    <span class="df-cite-group">
      <label class="df-cite-working" for="citeWorking">Document
        <select id="citeWorking" class="form-select form-select-sm" disabled>
          <option value="">Select 2+ reports…</option>
        </select>
      </label>
      <button type="button" class="btn btn-forge" id="citeBtn" disabled title="Score the other selected reports as references for the chosen document">Forge Cite</button>
    </span>
  </div>
  <?php endif; ?>

  <div class="df-list" id="libList">
    <?php if (empty($items)): ?>
      <div class="df-empty">
        <img src="<?php echo $assetBase; ?>images/docforge_favicon.png" alt="" style="opacity:.15;height:48px;margin-bottom:1rem;">
        <p>No reports yet. <a href="index.php">Forge your first one.</a></p>
      </div>
    <?php else: ?>
      <?php foreach ($items as $row): ?>
        <?php
          $score = (int) $row['knowledge_score'];
          $color = df_score_color($score);
          $date = date('j M Y', strtotime($row['created_at']));
        ?>
        <div class="df-row" data-report-id="<?php echo (int) $row['id']; ?>">
          <label class="df-select" title="Select for merge">
            <input type="checkbox" class="df-select-cb" value="<?php echo (int) $row['id']; ?>"
                   aria-label="Select &ldquo;<?php echo htmlspecialchars($row['title'], ENT_QUOTES); ?>&rdquo; for merge">
          </label>
          <div class="flex-grow-1">
            <span class="title"><?php echo htmlspecialchars($row['title']); ?></span>
            <span class="df-badge"><?php echo htmlspecialchars($row['source_type']); ?></span>
            <p class="df-desc"><?php echo htmlspecialchars($row['excerpt']); ?></p>
            <div class="df-meta">
              <?php echo $date; ?> · <?php echo df_format_bytes($row['size_bytes']); ?> ·
              <span class="mini"><i style="width:<?php echo $score; ?>%;background:<?php echo $color; ?>;"></i></span>
              <?php echo $score; ?>%
            </div>
          </div>
          <div class="df-actions">
            <a class="btn btn-outline-ink" href="view.php?id=<?php echo (int) $row['id']; ?>">View</a>
            <div class="btn-group">
              <a class="btn btn-outline-forge" href="api/download.php?id=<?php echo (int) $row['id']; ?>&amp;fmt=md">Download</a>
              <button type="button" class="btn btn-outline-forge dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">More formats</span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="api/download.php?id=<?php echo (int) $row['id']; ?>&amp;fmt=json">.json</a></li>
              </ul>
            </div>
            <button type="button" class="btn df-del" data-id="<?php echo (int) $row['id']; ?>"
                    data-title="<?php echo htmlspecialchars($row['title'], ENT_QUOTES); ?>"
                    aria-label="Delete report" title="Delete">
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <nav class="mt-4" aria-label="Library pages" id="libPagination">
    <ul class="pagination justify-content-center mb-0">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item<?php echo ($p === $page) ? ' active' : ''; ?>">
          <a class="page-link" href="?page=<?php echo $p; ?><?php echo $q !== '' ? '&amp;q=' . urlencode($q) : ''; ?>"><?php echo $p; ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
  <?php endif; ?>
</main>

<?php
$pageScript = 'library.js';
require __DIR__ . '/includes/footer.php';
