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
