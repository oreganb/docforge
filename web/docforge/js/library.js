(function () {
  'use strict';
  var search = document.getElementById('libSearch');
  if (!search) return;
  var deb;
  search.addEventListener('input', function () {
    clearTimeout(deb);
    deb = setTimeout(function () {
      var q = search.value.trim();
      var url = 'library.php' + (q ? '?q=' + encodeURIComponent(q) : '');
      window.location.href = url;
    }, 300);
  });
})();
