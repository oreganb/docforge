(function () {
  'use strict';

  var csrf = document.getElementById('csrfToken');
  if (!csrf) return;

  var workingInput = document.getElementById('workingInput');
  var refsInput = document.getElementById('refsInput');
  var dropWorking = document.getElementById('dropWorking');
  var dropRefs = document.getElementById('dropRefs');
  var runBtn = document.getElementById('citeRunBtn');
  var workingFile = null;
  var refFiles = [];
  var lastMarkdown = '';

  var idle = document.getElementById('cite-idle');
  var processing = document.getElementById('cite-processing');
  var results = document.getElementById('cite-results');
  var failed = document.getElementById('cite-failed');

  function show(id) {
    idle.classList.toggle('d-none', id !== 'idle');
    processing.classList.toggle('d-none', id !== 'processing');
    results.classList.toggle('d-none', id !== 'results');
    failed.classList.toggle('d-none', id !== 'failed');
  }

  function refreshRun() {
    runBtn.disabled = !(workingFile && refFiles.length > 0);
  }

  function bindDrop(zone, input, onFiles) {
    zone.addEventListener('click', function (e) {
      if (e.target.closest('.clear')) return;
      input.click();
    });
    zone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        input.click();
      }
    });
    ['dragover', 'dragenter'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        zone.classList.add('dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        zone.classList.remove('dragover');
      });
    });
    zone.addEventListener('drop', function (e) {
      if (e.dataTransfer.files.length) onFiles(e.dataTransfer.files);
    });
    input.addEventListener('change', function () {
      if (input.files.length) onFiles(input.files);
    });
  }

  function setWorking(f) {
    workingFile = f;
    document.getElementById('workingName').textContent = f.name;
    document.getElementById('workingChip').classList.remove('d-none');
    document.getElementById('workingText').classList.add('d-none');
    refreshRun();
  }

  function clearWorking() {
    workingFile = null;
    workingInput.value = '';
    document.getElementById('workingChip').classList.add('d-none');
    document.getElementById('workingText').classList.remove('d-none');
    refreshRun();
  }

  function renderRefs() {
    var list = document.getElementById('refsList');
    var text = document.getElementById('refsText');
    list.innerHTML = '';
    if (!refFiles.length) {
      list.classList.add('d-none');
      text.classList.remove('d-none');
      return;
    }
    text.classList.add('d-none');
    list.classList.remove('d-none');
    for (var i = 0; i < refFiles.length; i++) {
      var li = document.createElement('li');
      li.innerHTML = '<span>' + escapeHtml(refFiles[i].name) + '</span>'
        + '<button type="button" class="clear" data-idx="' + i + '" aria-label="Remove">&times;</button>';
      list.appendChild(li);
    }
    list.querySelectorAll('.clear').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var idx = parseInt(btn.getAttribute('data-idx'), 10);
        refFiles.splice(idx, 1);
        renderRefs();
        refreshRun();
      });
    });
    refreshRun();
  }

  function addRefs(fileList) {
    for (var i = 0; i < fileList.length; i++) {
      var f = fileList[i];
      var dup = refFiles.some(function (r) { return r.name === f.name && r.size === f.size; });
      if (!dup) refFiles.push(f);
    }
    renderRefs();
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  bindDrop(dropWorking, workingInput, function (files) {
    if (files[0]) setWorking(files[0]);
  });
  bindDrop(dropRefs, refsInput, addRefs);

  document.getElementById('clearWorking').addEventListener('click', function (e) {
    e.stopPropagation();
    clearWorking();
  });

  function resetForm() {
    clearWorking();
    refFiles = [];
    refsInput.value = '';
    renderRefs();
    lastMarkdown = '';
    show('idle');
  }

  runBtn.addEventListener('click', function () {
    if (!workingFile || !refFiles.length) return;
    show('processing');

    var body = new FormData();
    body.append('csrf_token', csrf.value);
    body.append('working', workingFile);
    for (var i = 0; i < refFiles.length; i++) {
      body.append('references[]', refFiles[i]);
    }

    fetch('api/cite_run.php', { method: 'POST', body: body })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.body || !res.body.ok) {
          throw new Error((res.body && res.body.error) || 'Analysis failed.');
        }
        lastMarkdown = res.body.markdown || '';
        document.getElementById('citeResultTitle').textContent =
          'Reference suitability — ' + (res.body.working_title || res.body.working_name);
        document.getElementById('citeResultMeta').textContent =
          res.body.working_name + ' · ' + res.body.reference_count + ' reference(s): '
          + (res.body.reference_names || []).join(', ');
        document.getElementById('citeReportHtml').innerHTML = res.body.html || '';
        show('results');
        document.getElementById('cite-results').scrollIntoView({ behavior: 'smooth', block: 'start' });
      })
      .catch(function (err) {
        document.getElementById('citeFailMsg').textContent = err.message || 'Analysis failed.';
        show('failed');
      });
  });

  document.getElementById('citeDownloadMd').addEventListener('click', function () {
    if (!lastMarkdown) return;
    var blob = new Blob([lastMarkdown], { type: 'text/markdown;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'docforge-cite-' + new Date().toISOString().slice(0, 10) + '.md';
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 500);
  });

  document.getElementById('citeReset').addEventListener('click', resetForm);
  document.getElementById('citeRetry').addEventListener('click', resetForm);
})();
