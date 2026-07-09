(function () {
  'use strict';

  var csrf = document.getElementById('csrfToken');
  if (!csrf) return;

  var docInput = document.getElementById('docInput');
  var dropDoc = document.getElementById('dropDoc');
  var runBtn = document.getElementById('redactRunBtn');
  var retainMap = document.getElementById('retainMap');
  var retainWrap = document.getElementById('retainMapWrap');
  var docFile = null;
  var lastMarkdown = '';
  var lastMap = null;

  var idle = document.getElementById('redact-idle');
  var processing = document.getElementById('redact-processing');
  var results = document.getElementById('redact-results');
  var failed = document.getElementById('redact-failed');

  function show(id) {
    idle.classList.toggle('d-none', id !== 'idle');
    processing.classList.toggle('d-none', id !== 'processing');
    results.classList.toggle('d-none', id !== 'results');
    failed.classList.toggle('d-none', id !== 'failed');
  }

  function setStatus(msg) {
    var el = document.getElementById('redactStatus');
    if (el) el.textContent = msg;
  }

  function mode() {
    var el = document.querySelector('input[name="redactMode"]:checked');
    return el ? el.value : 'token';
  }

  function bindDrop() {
    dropDoc.addEventListener('click', function (e) {
      if (e.target.closest('.clear')) return;
      docInput.click();
    });
    dropDoc.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        docInput.click();
      }
    });
    ['dragover', 'dragenter'].forEach(function (ev) {
      dropDoc.addEventListener(ev, function (e) {
        e.preventDefault();
        dropDoc.classList.add('dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dropDoc.addEventListener(ev, function (e) {
        e.preventDefault();
        dropDoc.classList.remove('dragover');
      });
    });
    dropDoc.addEventListener('drop', function (e) {
      if (e.dataTransfer.files.length) setDoc(e.dataTransfer.files[0]);
    });
    docInput.addEventListener('change', function () {
      if (docInput.files.length) setDoc(docInput.files[0]);
    });
    document.getElementById('clearDoc').addEventListener('click', function (e) {
      e.stopPropagation();
      docFile = null;
      docInput.value = '';
      document.getElementById('docChip').classList.add('d-none');
      document.getElementById('docText').classList.remove('d-none');
      runBtn.disabled = true;
    });
  }

  function setDoc(f) {
    docFile = f;
    document.getElementById('docName').textContent = f.name;
    document.getElementById('docChip').classList.remove('d-none');
    document.getElementById('docText').classList.add('d-none');
    runBtn.disabled = false;
  }

  document.querySelectorAll('input[name="redactMode"]').forEach(function (r) {
    r.addEventListener('change', function () {
      if (retainWrap) {
        retainWrap.classList.toggle('d-none', mode() === 'mask');
      }
    });
  });

  function resetForm() {
    docFile = null;
    docInput.value = '';
    document.getElementById('docChip').classList.add('d-none');
    document.getElementById('docText').classList.remove('d-none');
    runBtn.disabled = true;
    lastMarkdown = '';
    lastMap = null;
    show('idle');
  }

  function buildFormBody(prepared) {
    var body = new FormData();
    body.append('csrf_token', csrf.value);
    body.append('mode', mode());
    body.append('retain_map', retainMap && retainMap.checked && mode() === 'token' ? '1' : '0');
    if (prepared && prepared.useClient) {
      body.append('client_text', prepared.text);
      body.append('source_name', docFile.name);
      body.append('client_ocr', '1');
      body.append('ocr_pages', String(prepared.ocr.pages_ocrd || 0));
      if (prepared.ocr.truncated) {
        body.append('ocr_truncated', '1');
      }
    } else {
      body.append('document', docFile);
    }
    return body;
  }

  function submitRedaction(prepared) {
    setStatus('Detecting and redacting identifying information…');
    return fetch('api/redact_run.php', { method: 'POST', body: buildFormBody(prepared) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.body || !res.body.ok) {
          throw new Error((res.body && res.body.error) || 'Redaction failed.');
        }
        return res.body;
      });
  }

  function showResults(b) {
    lastMarkdown = b.markdown || '';
    lastMap = b.redaction_map || null;
    document.getElementById('redactResultTitle').textContent = 'Redacted — ' + (b.title || b.source_name);
    var total = b.stats && b.stats.total !== undefined ? b.stats.total : 0;
    document.getElementById('redactResultMeta').textContent =
      b.source_name + ' · ' + b.mode + ' mode · ' + total + ' item(s) redacted';
    document.getElementById('redactReportHtml').innerHTML = b.html || '';
    var mapBtn = document.getElementById('redactDownloadMap');
    if (mapBtn) {
      var hasMap = lastMap && Object.keys(lastMap).length > 0;
      mapBtn.classList.toggle('d-none', !hasMap);
    }
    show('results');
    document.getElementById('redact-results').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function isScannedPdfError(msg) {
    return /no selectable text|scan|image-only|OCR installed/i.test(msg || '');
  }

  function prepareUpload() {
    if (!window.DocForgeRedactOcr || !DocForgeRedactOcr.isPdf(docFile)) {
      return Promise.resolve({ useClient: false });
    }
    return DocForgeRedactOcr.preparePdfText(docFile, setStatus);
  }

  runBtn.addEventListener('click', function () {
    if (!docFile) return;
    show('processing');
    setStatus('Preparing document…');

    var prepared = { useClient: false };
    prepareUpload()
      .then(function (p) {
        prepared = p || prepared;
        return submitRedaction(prepared);
      })
      .then(showResults)
      .catch(function (err) {
        var msg = err.message || 'Redaction failed.';
        if (!prepared.useClient && window.DocForgeRedactOcr && DocForgeRedactOcr.isPdf(docFile) && isScannedPdfError(msg)) {
          setStatus('Server could not read this scan — trying browser OCR…');
          return DocForgeRedactOcr.ocrPdf(docFile, setStatus).then(function (ocr) {
            if (!DocForgeRedactOcr.isReadable(ocr.text)) {
              throw new Error('OCR could not read any text from this scan.');
            }
            prepared = {
              useClient: true,
              text: ocr.text,
              ocr: { pages_ocrd: ocr.pages_ocrd, truncated: false, client: true }
            };
            return submitRedaction(prepared);
          }).then(showResults);
        }
        throw err;
      })
      .catch(function (err) {
        document.getElementById('redactFailMsg').textContent = err.message || 'Redaction failed.';
        show('failed');
      });
  });

  document.getElementById('redactDownloadMd').addEventListener('click', function () {
    if (!lastMarkdown) return;
    downloadBlob(lastMarkdown, 'text/markdown;charset=utf-8', 'docforge-redact.md');
  });

  document.getElementById('redactDownloadMap').addEventListener('click', function () {
    if (!lastMap) return;
    downloadBlob(JSON.stringify(lastMap, null, 2), 'application/json', 'docforge-redact-map.json');
  });

  function downloadBlob(content, type, name) {
    var blob = new Blob([content], { type: type });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 500);
  }

  document.getElementById('redactReset').addEventListener('click', resetForm);
  document.getElementById('redactRetry').addEventListener('click', resetForm);

  bindDrop();
})();
