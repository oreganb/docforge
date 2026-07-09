<?php

namespace DocForge\Plugins;

/**
 * Interim scanned-PDF text extraction (Phase 3 preview).
 * Rasterises pages with pdftoppm and runs Tesseract when no text layer exists.
 */
class PdfOcrExtractor
{
    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config = array())
    {
        $defaults = array(
            'enabled' => true,
            'max_pages' => 25,
            'dpi' => 150,
            'lang' => 'eng',
        );
        $this->config = array_merge($defaults, $config);
    }

    public function isAvailable()
    {
        return !empty($this->config['enabled'])
            && $this->commandExists('tesseract')
            && $this->commandExists('pdftoppm');
    }

    public function maxPages()
    {
        return (int) $this->config['max_pages'];
    }

    /**
     * @param int $totalPages 0 = unknown (extract up to max_pages)
     * @return array{text:string,page_count:int,pages_ocrd:int,truncated:bool}
     */
    public function extract($filePath, $totalPages = 0)
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('OCR tools are not available on this server.');
        }
        $max = $this->maxPages();
        if ($max < 1) {
            throw new \RuntimeException('OCR is disabled.');
        }
        if ($totalPages > $max) {
            throw new \RuntimeException(
                'This scanned PDF has ' . number_format($totalPages) . ' pages; Forge can OCR at most '
                . number_format($max) . ' pages per run. Split the file or use a PDF with selectable text.'
            );
        }

        $pagesToRun = $totalPages > 0 ? $totalPages : $max;
        $pagesToRun = min($pagesToRun, $max);

        $tmpdir = sys_get_temp_dir() . '/df-ocr-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpdir, 0700, true) && !is_dir($tmpdir)) {
            throw new \RuntimeException('Could not create a temporary directory for OCR.');
        }

        $prefix = $tmpdir . '/page';
        $escaped = escapeshellarg($filePath);
        $cmd = 'pdftoppm -png -r ' . (int) $this->config['dpi']
            . ' -f 1 -l ' . $pagesToRun . ' ' . $escaped . ' ' . escapeshellarg($prefix)
            . ' 2>/dev/null';
        @shell_exec($cmd);

        $images = glob($prefix . '-*.png');
        if (empty($images)) {
            $this->removeDir($tmpdir);
            throw new \RuntimeException('Could not rasterise this PDF for OCR.');
        }
        natsort($images);

        $parts = array();
        foreach ($images as $img) {
            $ocr = @shell_exec(
                'tesseract ' . escapeshellarg($img) . ' stdout -l '
                . escapeshellarg((string) $this->config['lang']) . ' --psm 1 2>/dev/null'
            );
            if (is_string($ocr)) {
                $chunk = trim($ocr);
                if ($chunk !== '') {
                    $parts[] = $chunk;
                }
            }
        }
        $this->removeDir($tmpdir);

        $text = implode("\n\n", $parts);
        if (trim($text) === '') {
            throw new \RuntimeException(
                'OCR ran but could not read any text from this scan. The image may be too faint, skewed, or handwritten.'
            );
        }

        $ocrd = count($images);
        $pageCount = $totalPages > 0 ? $totalPages : $ocrd;
        return array(
            'text' => $text,
            'page_count' => $pageCount,
            'pages_ocrd' => $ocrd,
            'truncated' => $totalPages === 0 && $ocrd >= $max,
        );
    }

    private function commandExists($cmd)
    {
        $which = @shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
        return is_string($which) && trim($which) !== '';
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
