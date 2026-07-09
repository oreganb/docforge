/**
 * Browser-side PDF text probe + Tesseract OCR for Forge Redact.
 * Used when the server has no Poppler/Tesseract (typical shared hosting).
 */
(function (global) {
  'use strict';

  var MAX_PAGES = 25;
  var MIN_READABLE = 20;

  function isReadable(text) {
    var trimmed = (text || '').trim();
    if (trimmed.length < MIN_READABLE) {
      return false;
    }
    var printable = (trimmed.match(/[\t\n\r\x20-\x7E]/g) || []).length;
    return printable / trimmed.length >= 0.7;
  }

  function isPdf(file) {
    return /\.pdf$/i.test(file.name) || file.type === 'application/pdf';
  }

  function readArrayBuffer(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(new Error('Could not read the file.')); };
      reader.readAsArrayBuffer(file);
    });
  }

  function loadPdf(arrayBuffer) {
    if (!global.pdfjsLib) {
      return Promise.reject(new Error('PDF library failed to load.'));
    }
    return global.pdfjsLib.getDocument({ data: arrayBuffer }).promise;
  }

  function extractPdfText(file, maxPages) {
    maxPages = maxPages || MAX_PAGES;
    return readArrayBuffer(file).then(function (buf) {
      return loadPdf(buf).then(function (pdf) {
        var limit = Math.min(pdf.numPages, maxPages);
        var parts = [];
        var chain = Promise.resolve();
        for (var p = 1; p <= limit; p++) {
          (function (pageNum) {
            chain = chain.then(function () {
              return pdf.getPage(pageNum).then(function (page) {
                return page.getTextContent().then(function (content) {
                  var line = content.items.map(function (it) { return it.str; }).join(' ');
                  if (line.trim()) {
                    parts.push(line.trim());
                  }
                });
              });
            });
          })(p);
        }
        return chain.then(function () {
          return {
            text: parts.join('\n\n'),
            pageCount: pdf.numPages,
            pagesRead: limit,
            truncated: pdf.numPages > maxPages
          };
        });
      });
    });
  }

  function renderPage(canvas, page) {
    var viewport = page.getViewport({ scale: 2 });
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    return page.render({
      canvasContext: canvas.getContext('2d'),
      viewport: viewport
    }).promise;
  }

  function ocrPdf(file, onStatus, maxPages) {
    maxPages = maxPages || MAX_PAGES;
    if (!global.Tesseract) {
      return Promise.reject(new Error('OCR library failed to load.'));
    }
    return readArrayBuffer(file).then(function (buf) {
      return loadPdf(buf).then(function (pdf) {
        if (pdf.numPages > maxPages) {
          throw new Error(
            'This PDF has ' + pdf.numPages + ' pages; browser OCR supports up to '
            + maxPages + ' per run.'
          );
        }
        return global.Tesseract.createWorker('eng').then(function (worker) {
          var parts = [];
          var canvas = document.createElement('canvas');
          var chain = Promise.resolve();
          for (var p = 1; p <= pdf.numPages; p++) {
            (function (pageNum, total) {
              chain = chain.then(function () {
                if (onStatus) {
                  onStatus('OCR in your browser — page ' + pageNum + ' of ' + total + '…');
                }
                return pdf.getPage(pageNum).then(function (page) {
                  return renderPage(canvas, page).then(function () {
                    return worker.recognize(canvas).then(function (result) {
                      var chunk = (result.data && result.data.text) ? result.data.text.trim() : '';
                      if (chunk) {
                        parts.push(chunk);
                      }
                    });
                  });
                });
              });
            })(p, pdf.numPages);
          }
          return chain.then(function () {
            return worker.terminate().then(function () {
              return {
                text: parts.join('\n\n'),
                pages_ocrd: pdf.numPages,
                pageCount: pdf.numPages,
                truncated: false
              };
            });
          });
        });
      });
    });
  }

  /**
   * For scanned PDFs, OCR in-browser and return text for server redaction.
   * Text-based PDFs are left for normal server extraction.
   */
  function preparePdfText(file, onStatus) {
    if (!isPdf(file)) {
      return Promise.resolve({ useClient: false });
    }
    if (onStatus) {
      onStatus('Checking PDF text layer…');
    }
    return extractPdfText(file).then(function (probe) {
      if (isReadable(probe.text)) {
        return { useClient: false };
      }
      if (probe.pageCount > MAX_PAGES) {
        throw new Error(
          'This PDF has ' + probe.pageCount + ' pages; browser OCR supports up to '
          + MAX_PAGES + ' per run.'
        );
      }
      if (onStatus) {
        onStatus('Scanned PDF detected — starting browser OCR…');
      }
      return ocrPdf(file, onStatus).then(function (ocr) {
        if (!isReadable(ocr.text)) {
          throw new Error(
            'OCR could not read any text from this scan. The image may be too faint, skewed, or handwritten.'
          );
        }
        return {
          useClient: true,
          text: ocr.text,
          ocr: {
            pages_ocrd: ocr.pages_ocrd,
            truncated: probe.truncated,
            client: true
          }
        };
      });
    });
  }

  global.DocForgeRedactOcr = {
    isPdf: isPdf,
    MAX_PAGES: MAX_PAGES,
    isReadable: isReadable,
    preparePdfText: preparePdfText,
    ocrPdf: ocrPdf
  };
})(window);
