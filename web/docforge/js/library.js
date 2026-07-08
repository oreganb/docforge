(function () {
  'use strict';
  var search = document.getElementById('libSearch');
  if (search) {
    var deb;
    search.addEventListener('input', function () {
      clearTimeout(deb);
      deb = setTimeout(function () {
        var q = search.value.trim();
        var url = 'library.php' + (q ? '?q=' + encodeURIComponent(q) : '');
        window.location.href = url;
      }, 300);
    });
  }

  var tokenEl = document.getElementById('csrfToken');
  var list = document.getElementById('libList');
  if (!list || !tokenEl) return;

  list.addEventListener('click', function (e) {
    var btn = e.target.closest('.df-del');
    if (!btn) return;

    var id = btn.getAttribute('data-id');
    var title = btn.getAttribute('data-title') || 'this report';
    if (!id) return;
    if (!window.confirm('Delete \u201C' + title + '\u201D? This cannot be undone.')) return;

    var row = btn.closest('.df-row');
    btn.disabled = true;

    var body = new FormData();
    body.append('id', id);
    body.append('csrf_token', tokenEl.value);

    fetch('api/delete.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.body || !res.body.ok) {
          throw new Error((res.body && res.body.error) || 'Delete failed.');
        }
        if (row) {
          row.parentNode.removeChild(row);
        }
        // If nothing remains on this page, reload to show the empty state / prior page.
        if (!list.querySelector('.df-row')) {
          window.location.reload();
        }
      })
      .catch(function (err) {
        btn.disabled = false;
        window.alert(err.message || 'Delete failed.');
      });
  });
})();
