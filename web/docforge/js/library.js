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

  // ---- Forge Merge / Forge Cite: multi-select actions ----------------------
  var mergeBtn = document.getElementById('mergeBtn');
  var mergeCount = document.getElementById('mergeCount');
  var mergeCompact = document.getElementById('mergeCompact');
  var citeBtn = document.getElementById('citeBtn');
  var citeWorking = document.getElementById('citeWorking');

  function selectedIds() {
    var ids = [];
    var boxes = list.querySelectorAll('.df-select-cb:checked');
    for (var i = 0; i < boxes.length; i++) {
      ids.push(boxes[i].value);
    }
    return ids;
  }

  function selectedRows() {
    var rows = [];
    var boxes = list.querySelectorAll('.df-select-cb:checked');
    for (var i = 0; i < boxes.length; i++) {
      var row = boxes[i].closest('.df-row');
      var titleEl = row ? row.querySelector('.title') : null;
      rows.push({ id: boxes[i].value, title: titleEl ? titleEl.textContent.trim() : boxes[i].value });
    }
    return rows;
  }

  function refreshBar() {
    var rows = selectedRows();
    var enough = rows.length >= 2;
    if (mergeBtn) mergeBtn.disabled = !enough;
    if (citeBtn) citeBtn.disabled = !enough;
    if (mergeCount) {
      mergeCount.textContent = enough
        ? rows.length + ' reports selected'
        : 'Select 2+ reports to merge or cite';
    }
    if (citeWorking) {
      citeWorking.disabled = !enough;
      var prev = citeWorking.value;
      citeWorking.innerHTML = '';
      if (!enough) {
        citeWorking.appendChild(new Option('Select 2+ reports…', ''));
      } else {
        for (var i = 0; i < rows.length; i++) {
          citeWorking.appendChild(new Option(rows[i].title, rows[i].id));
        }
        // Preserve the prior choice if it is still selected, else default first.
        var stillThere = rows.some(function (r) { return r.id === prev; });
        citeWorking.value = stillThere ? prev : rows[0].id;
      }
    }
  }

  list.addEventListener('change', function (e) {
    if (e.target && e.target.classList.contains('df-select-cb')) {
      var row = e.target.closest('.df-row');
      if (row) row.classList.toggle('is-selected', e.target.checked);
      refreshBar();
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

  if (citeBtn) {
    citeBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (ids.length < 2) return;
      var workingId = citeWorking ? citeWorking.value : '';
      if (!workingId) {
        window.alert('Choose which selected report is your working document.');
        return;
      }
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'api/cite.php';
      form.style.display = 'none';
      form.appendChild(hidden('csrf_token', tokenEl.value));
      form.appendChild(hidden('working_id', workingId));
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
