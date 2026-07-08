<footer class="df-footer">
  <div class="df-footer-inner">
    <span class="df-copy">&copy; 2026 Brian O&rsquo;Regan</span>
    <nav class="df-footer-links" aria-label="Footer">
      <a href="about.php">About</a>
      <a href="docforge_cite.php">Forge Cite</a>
      <a href="library.php">Library</a>
    </nav>
  </div>
</footer>
<script src="<?php echo isset($assetBase) ? $assetBase : '../'; ?>js/bootstrap.bundle.min.js"></script>
<?php if (!empty($pageScript)): ?>
<script src="<?php echo $assetBase; ?>js/<?php echo $pageScript; ?>"></script>
<?php endif; ?>
<script>
window.addEventListener('scroll', function () {
  var nav = document.getElementById('nav');
  if (nav) nav.classList.toggle('scrolled', window.scrollY > 8);
});
</script>
</body>
</html>
