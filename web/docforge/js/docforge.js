(function () {
  'use strict';

  var drop = document.getElementById('drop');
  var input = document.getElementById('fileInput');
  var chip = document.getElementById('fileChip');
  var runBtn = document.getElementById('runBtn');
  var dropText = document.getElementById('dropText');
  var csrf = document.getElementById('csrfToken').value;
  var picked = null;
  var pollTimer = null;
  var lastPhase = '';
  var pollCount = 0;
  var dispatchToken = '';

  // Kick the background worker directly from the browser. Shared hosts often
  // block server-to-server (loopback) HTTP, so the client triggers processing.
  function kickWorker(jobId, token) {
    if (!token) return;
    var url = 'api/process.php?job_id=' + encodeURIComponent(jobId) + '&token=' + encodeURIComponent(token);
    try {
      fetch(url, { keepalive: true }).catch(function () {});
    } catch (e) { /* ignore */ }
  }

  function formatSize(bytes) {
    if (bytes > 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    return Math.round(bytes / 1024) + ' KB';
  }

  function setFile(f) {
    picked = f;
    document.getElementById('fileName').textContent = f.name;
    document.getElementById('fileSize').textContent = '· ' + formatSize(f.size);
    chip.classList.remove('d-none');
    dropText.classList.add('d-none');
    runBtn.disabled = false;
  }

  function clearFile() {
    picked = null;
    input.value = '';
    chip.classList.add('d-none');
    dropText.classList.remove('d-none');
    runBtn.disabled = true;
  }

  drop.addEventListener('click', function (e) {
    // The Run button and the clear (×) live inside the drop box but must not
    // re-open the file picker.
    if (e.target.closest('#runBtn') || e.target.closest('#clearFile')) return;
    input.click();
  });
  drop.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      input.click();
    }
  });
  ['dragover', 'dragenter'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) {
      e.preventDefault();
      drop.classList.add('dragover');
    });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) {
      e.preventDefault();
      drop.classList.remove('dragover');
    });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]);
  });
  input.addEventListener('change', function () {
    if (input.files.length) setFile(input.files[0]);
  });
  document.getElementById('clearFile').addEventListener('click', function (e) {
    e.stopPropagation();
    clearFile();
  });

  function showState(name) {
    ['state-idle', 'state-processing', 'state-complete', 'state-failed'].forEach(function (id) {
      document.getElementById(id).classList.toggle('d-none', id !== 'state-' + name);
    });
  }

  function scoreColor(score) {
    if (score >= 85) return 'var(--df-ok)';
    if (score >= 70) return 'var(--df-forge)';
    if (score >= 50) return 'var(--df-warn)';
    return 'var(--df-fail)';
  }

  function pollJob(jobId) {
    fetch('api/job.php?id=' + encodeURIComponent(jobId))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Job poll failed');
        pollCount++;
        // Safety net: if still queued after a couple of polls, the initial
        // kick may have been dropped — fire it again.
        if (data.state === 'queued' && (pollCount === 2 || pollCount === 5)) {
          kickWorker(jobId, dispatchToken);
        }
        var pct = data.percent || 0;
        document.getElementById('progBar').style.width = pct + '%';
        var phase = data.phase || 'Forge';
        var stage = data.stage || 'working';
        var tool = data.tool ? ' (' + data.tool + ')' : '';
        document.getElementById('progLine').innerHTML =
          '<span class="phase">' + phase + '</span> · ' + pct + '% — ' + stage + tool;
        if (phase !== lastPhase) {
          lastPhase = phase;
          document.getElementById('state-processing').setAttribute('aria-label', phase + ' ' + pct + ' percent');
        }
        if (data.state === 'complete' && data.report_id) {
          clearInterval(pollTimer);
          loadReport(data.report_id);
        } else if (data.state === 'failed') {
          clearInterval(pollTimer);
          document.getElementById('failMsg').textContent = data.error || 'Processing failed.';
          showState('failed');
        }
      })
      .catch(function (err) {
        clearInterval(pollTimer);
        document.getElementById('failMsg').textContent = err.message;
        showState('failed');
      });
  }

  function loadReport(reportId) {
    fetch('api/report.php?id=' + reportId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Could not load report');
        var rep = data.report;
        document.getElementById('doneTitle').textContent = rep.title;
        document.getElementById('scoreNum').textContent = rep.knowledge_score + '%';
        document.getElementById('scoreStars').textContent = rep.stars;
        document.getElementById('scoreFill').style.width = rep.knowledge_score + '%';
        document.getElementById('scoreFill').style.background = scoreColor(rep.knowledge_score);
        var meta = 'Knowledge Score';
        if (rep.sub_scores) {
          var parts = [];
          Object.keys(rep.sub_scores).forEach(function (k) {
            parts.push(k + ' ' + rep.sub_scores[k]);
          });
          if (parts.length) meta += ' · ' + parts.join(' · ');
        }
        document.getElementById('scoreMeta').textContent = meta;
        document.getElementById('doneSummary').textContent = rep.excerpt || '';
        document.getElementById('dlMd').href = 'api/download.php?id=' + reportId + '&fmt=md';
        document.getElementById('dlJson').href = 'api/download.php?id=' + reportId + '&fmt=json';
        showState('complete');
      })
      .catch(function (err) {
        document.getElementById('failMsg').textContent = err.message;
        showState('failed');
      });
  }

  runBtn.addEventListener('click', function () {
    if (!picked) return;
    var fd = new FormData();
    fd.append('file', picked);
    fd.append('csrf_token', csrf);
    runBtn.disabled = true;
    fetch('api/upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Upload failed');
        showState('processing');
        lastPhase = '';
        pollCount = 0;
        dispatchToken = data.dispatch_token || '';
        document.getElementById('progBar').style.width = '0%';
        kickWorker(data.job_id, dispatchToken);
        pollTimer = setInterval(function () { pollJob(data.job_id); }, 1500);
        pollJob(data.job_id);
      })
      .catch(function (err) {
        runBtn.disabled = false;
        document.getElementById('failMsg').textContent = err.message;
        showState('failed');
      });
  });

  function resetHome() {
    if (pollTimer) clearInterval(pollTimer);
    showState('idle');
    document.getElementById('progBar').style.width = '0%';
    clearFile();
  }
  document.getElementById('resetBtn').addEventListener('click', resetHome);
  document.getElementById('resetFailBtn').addEventListener('click', resetHome);
})();
