<?php

namespace DocForge\Plugins;

class PdfParser extends AbstractParser
{
    /** @var int Page count discovered during extraction (smalot is authoritative). */
    private $pageCount = 0;

    /** @var string Embedded document title from PDF metadata, if any. */
    private $metaTitle = '';

    public function detect($bytes, $mime)
    {
        return strncmp($bytes, '%PDF', 4) === 0;
    }

    public function extract($filePath)
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            throw new \RuntimeException(
                'This PDF has no extractable text layer. Scanned PDF support arrives in Phase 3.'
            );
        }
        $blocks = array();
        $paragraphs = preg_split('/\n\s*\n/', $text);
        foreach ($paragraphs as $i => $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (strlen($para) < 100 && !preg_match('/[.!?]$/', $para)) {
                $blocks[] = $this->block('heading', $para, 2, 'block:' . $i);
            } else {
                $blocks[] = $this->block('paragraph', $para, null, 'block:' . $i);
            }
        }
        // Prefer smalot's authoritative page count; fall back to form-feeds.
        $pageCount = $this->pageCount > 0
            ? $this->pageCount
            : max(1, (int) preg_match_all('/\f/', $text) + 1);
        return array(
            'blocks' => $blocks,
            'full_text' => $text,
            'page_count' => $pageCount,
            'meta_title' => $this->metaTitle,
        );
    }

    public function metadata($filePath)
    {
        return array('pages' => 1);
    }

    private function extractText($filePath)
    {
        // 1. pdftotext (best layout) when shell access is available.
        $escaped = escapeshellarg($filePath);
        if ($this->commandExists('pdftotext')) {
            $out = @shell_exec('pdftotext -layout ' . $escaped . ' - 2>/dev/null');
            if (is_string($out) && $this->isReadable($out)) {
                return $out;
            }
        }

        // 2. smalot/pdfparser — pure PHP, decodes compressed (FlateDecode)
        //    streams that the crude regex fallback cannot handle.
        try {
            if (class_exists('\\Smalot\\PdfParser\\Parser')) {
                // Don't retain decoded image binaries in memory — DocForge does
                // not analyse images in this phase, and image-heavy PDFs are a
                // major memory sink. This leaves headroom for font/CMap parsing.
                $parser = class_exists('\\Smalot\\PdfParser\\Config')
                    ? new \Smalot\PdfParser\Parser(array(), $this->pdfConfig())
                    : new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                if (is_string($text) && $this->isReadable($text)) {
                    $pages = $pdf->getPages();
                    $this->pageCount = is_array($pages) ? count($pages) : 0;
                    $this->metaTitle = $this->readMetaTitle($pdf);
                    return $text;
                }
            }
        } catch (\Throwable $e) {
            // fall through to crude extraction
        }

        // 3. Last resort: crude literal-string scan.
        $out = $this->extractTextPhp($filePath);
        return $this->isReadable($out) ? $out : '';
    }

    /** Memory-conscious smalot config: drop image binaries we never analyse. */
    private function pdfConfig()
    {
        $config = new \Smalot\PdfParser\Config();
        $config->setRetainImageContent(false);
        return $config;
    }

    /** Read the embedded /Title from PDF metadata (often empty). */
    private function readMetaTitle($pdf)
    {
        try {
            $details = $pdf->getDetails();
            if (isset($details['Title'])) {
                $title = is_array($details['Title']) ? implode(' ', $details['Title']) : $details['Title'];
                return trim((string) $title);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
    }

    /** Heuristic: is this extracted text real prose rather than binary garbage? */
    private function isReadable($text)
    {
        if (!is_string($text)) {
            return false;
        }
        $trimmed = trim($text);
        if (strlen($trimmed) < 20) {
            return false;
        }
        // Ratio of printable ASCII + common whitespace to total bytes.
        $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $trimmed);
        return $printable / max(1, strlen($trimmed)) >= 0.7;
    }

    private function extractTextPhp($filePath)
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }
        $parts = array();
        if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)/s', $content, $m)) {
            foreach ($m[1] as $raw) {
                $decoded = stripcslashes($raw);
                if (preg_match('/[^\x00-\x1F\x7F-\xFF]/', $decoded)) {
                    $parts[] = $decoded;
                }
            }
        }
        return implode("\n", $parts);
    }

    private function commandExists($cmd)
    {
        $which = shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return is_string($which) && trim($which) !== '';
    }
}
