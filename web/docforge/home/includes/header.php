<?php
/**
 * Shared layout partial.
 * @var string $pageTitle
 * @var string $activeNav 'home'|'library'
 * @var string $mainClass
 */
use DocForge\Core\Csrf;
$assetBase = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($pageTitle); ?> — DocForge</title>
<link rel="icon" type="image/png" href="<?php echo $assetBase; ?>images/docforge_favicon.png">
<link rel="apple-touch-icon" href="<?php echo $assetBase; ?>images/docforge_favicon.png">
<link href="<?php echo $assetBase; ?>css/bootstrap.min.css" rel="stylesheet">
<link href="<?php echo $assetBase; ?>css/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?php echo $assetBase; ?>css/docforge.css" rel="stylesheet">
</head>
<body<?php echo !empty($bodyClass) ? ' class="' . htmlspecialchars($bodyClass) . '"' : ''; ?>>
<?php if (empty($hideNav)): ?>
<nav class="df-nav sticky-top" id="nav">
  <div class="container" style="max-width:960px;">
    <div class="d-flex justify-content-between align-items-center">
      <a href="index.php" class="brand">
        <img src="<?php echo $assetBase; ?>images/docforge_favicon.png" alt="">
        DocForge
      </a>
      <a href="library.php" class="nav-link<?php echo ($activeNav === 'library') ? ' active' : ''; ?>">Library</a>
    </div>
  </div>
</nav>
<?php endif; ?>
