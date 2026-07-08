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

  // ---- Forge Merge: multi-select + combined-download -----------------------
  var mergeBtn = document.getElementById('mergeBtn');
  var mergeCount = document.getElementById('mergeCount');
  var mergeCompact = document.getElementById('mergeCompact');

  function selectedIds() {
    var ids = [];
    var boxes = list.querySelectorAll('.df-select-cb:checked');
    for (var i = 0; i < boxes.length; i++) {
      ids.push(boxes[i].value);
    }
    return ids;
  }

  function refreshMergeBar() {
    if (!mergeBtn) return;
    var ids = selectedIds();
    mergeBtn.disabled = ids.length < 2;
    if (mergeCount) {
      mergeCount.textContent = ids.length < 2
        ? 'Select 2+ reports to merge'
        : ids.length + ' reports selected';
    }
  }

  list.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('df-select-cb')) {
      var row = e.target.closest('.df-row');
      if (row) row.classList.toggle('is-selected', e.target.checked);
      refreshMergeBar();
    }
  });

  if (mergeBtn) {
    mergeBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (ids.length < 2) return;
      // A form POST lets the browser handle the streamed file download.
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'api/merge.php';
      form.style.display = 'none';
      form.appendChild(hidden('csrf_token', tokenEl.value));
      if (mergeCompact && mergeCompact.checked) {
        form.appendChild(hidden('profile', 'context'));
      }
      for (var i = 0; i < ids.length; i++) {
        form.appendChild(hidden('ids[]', ids[i]));
      }
      document.body.appendChild(form);
      form.submit();
      setTimeout(function () { document.body.removeChild(form); }, 1000);
    });
  }

  function hidden(name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    return input;
  }

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
