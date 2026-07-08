<?php

namespace DocForge\Plugins;

/**
 * Forge Data — dataset parser (CSV / TSV / XLSX / JSON records).
 *
 * Emits a tabular IR variant ('kind' => 'dataset') rather than prose blocks:
 * column names plus a bounded row matrix and the true row count. Reading is
 * streamed and row-capped so a large file never loads wholesale into memory.
 * All statistics are computed downstream by DataProfileModule.
 */
class DataParser extends AbstractParser
{
    /** Hard cap on rows retained for profiling (bounds memory on shared hosting). */
    const ROW_CAP = 50000;
    /** Hard cap on columns (guards against pathologically wide files). */
    const COL_CAP = 256;
    /** Per-cell length cap (a single runaway cell must not blow memory). */
    const CELL_CAP = 2000;

    /** @var string */
    private $mime = '';
    /** @var string one of CSV|TSV|XLSX|JSON */
    private $format = 'CSV';

    public function detect($bytes, $mime)
    {
        $this->mime = (string) $mime;
        if (strpos($mime, 'spreadsheet') !== false || strpos($mime, 'ms-excel') !== false) {
            $this->format = 'XLSX';
            return true;
        }
        if ($mime === 'application/json') {
            $this->format = 'JSON';
            return true;
        }
        if ($mime === 'text/tab-separated-values') {
            $this->format = 'TSV';
            return true;
        }
        if ($mime === 'text/csv') {
            $this->format = 'CSV';
            return true;
        }
        return false;
    }

    public function extract($filePath)
    {
        switch ($this->format) {
            case 'XLSX':
                $table = $this->readSpreadsheet($filePath);
                break;
            case 'JSON':
                $table = $this->readJson($filePath);
                break;
            case 'TSV':
                $table = $this->readDelimited($filePath, "\t");
                break;
            default:
                $table = $this->readDelimited($filePath, $this->sniffDelimiter($filePath));
        }

        if (empty($table['columns'])) {
            throw new \RuntimeException('No columns could be read from this dataset.');
        }

        return array(
            'kind' => 'dataset',
            'dataset' => array('format' => $this->format),
            'columns' => $table['columns'],
            'rows' => $table['rows'],
            'row_count' => $table['row_count'],
            'rows_scanned' => count($table['rows']),
            'truncated' => $table['truncated'],
            // Keep the text-oriented fields present-but-empty so shared code paths
            // (sanitiser, exporters) never trip on a missing key.
            'full_text' => '',
            'blocks' => array(),
            'page_count' => 1,
        );
    }

    public function metadata($filePath)
    {
        return array('pages' => 1);
    }

    /** Sniff the most likely delimiter from the first line of a CSV. */
    private function sniffDelimiter($filePath)
    {
        $fh = @fopen($filePath, 'r');
        if (!$fh) {
            return ',';
        }
        $line = fgets($fh);
        fclose($fh);
        if ($line === false) {
            return ',';
        }
        $best = ',';
        $bestCount = 0;
        foreach (array(',', ';', "\t", '|') as $delim) {
            $c = substr_count($line, $delim);
            if ($c > $bestCount) {
                $bestCount = $c;
                $best = $delim;
            }
        }
        return $best;
    }

    /**
     * Stream a delimited file. Header = first non-empty row; the true row count
     * is tallied while only ROW_CAP rows are retained.
     *
     * @return array{columns:array<int,string>,rows:array<int,array<int,string>>,row_count:int,truncated:bool}
     */
    private function readDelimited($filePath, $delimiter)
    {
        $fh = @fopen($filePath, 'r');
        if (!$fh) {
            throw new \RuntimeException('Could not read the dataset file.');
        }
        $columns = array();
        $rows = array();
        $rowCount = 0;
        while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
            if ($cells === array(null) || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue; // blank line
            }
            if (empty($columns)) {
                $columns = $this->normaliseHeader($cells);
                continue;
            }
            $rowCount++;
            if (count($rows) < self::ROW_CAP) {
                $rows[] = $this->normaliseRow($cells, count($columns));
            }
        }
        fclose($fh);
        return array(
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => $rowCount,
            'truncated' => $rowCount > count($rows),
        );
    }

    /**
     * Read an .xlsx natively via ZipArchive + XMLReader.
     *
     * XLSX is a zip of XML parts; reading it directly keeps DocForge on plain
     * PHP 7.3 (PhpSpreadsheet requires 7.4+) with no extra dependency, and the
     * worksheet is streamed so a large sheet never loads wholesale.
     *
     * @return array{columns:array<int,string>,rows:array<int,array<int,string>>,row_count:int,truncated:bool}
     */
    private function readSpreadsheet($filePath)
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('Reading .xlsx requires the PHP zip extension.');
        }
        $shared = $this->readSharedStrings($filePath);
        $sheetPath = $this->firstSheetPath($filePath);

        $reader = new \XMLReader();
        if (!@$reader->open('zip://' . $filePath . '#' . $sheetPath)) {
            throw new \RuntimeException('Could not read the spreadsheet worksheet.');
        }
        $columns = array();
        $rows = array();
        $rowCount = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'row') {
                continue;
            }
            $cells = $this->parseSheetRow($reader->readOuterXml(), $shared);
            if ($this->rowIsEmpty($cells)) {
                continue;
            }
            if (empty($columns)) {
                $columns = $this->normaliseHeader($cells);
                continue;
            }
            $rowCount++;
            if (count($rows) < self::ROW_CAP) {
                $rows[] = $this->normaliseRow($cells, count($columns));
            }
        }
        $reader->close();
        return array(
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => $rowCount,
            'truncated' => $rowCount > count($rows),
        );
    }

    /**
     * Load the shared-strings table (index → text). Rich-text runs are flattened.
     * @return array<int,string>
     */
    private function readSharedStrings($filePath)
    {
        $strings = array();
        $reader = new \XMLReader();
        if (!@$reader->open('zip://' . $filePath . '#xl/sharedStrings.xml')) {
            return $strings; // a sheet may store all values inline
        }
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'si') {
                $strings[] = $this->allText($reader->readOuterXml());
            }
        }
        $reader->close();
        return $strings;
    }

    /** Locate the lowest-numbered worksheet part inside the zip. */
    private function firstSheetPath($filePath)
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Could not open the spreadsheet.');
        }
        $best = null;
        $bestNum = PHP_INT_MAX;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m) && (int) $m[1] < $bestNum) {
                $bestNum = (int) $m[1];
                $best = $name;
            }
        }
        $zip->close();
        return $best !== null ? $best : 'xl/worksheets/sheet1.xml';
    }

    /**
     * Parse one <row> fragment into a positional cell array (gaps filled).
     * @param array<int,string> $shared
     * @return array<int,string>
     */
    private function parseSheetRow($rowXml, array $shared)
    {
        $sx = @simplexml_load_string($this->stripNs($rowXml));
        if ($sx === false) {
            return array();
        }
        $cells = array();
        foreach ($sx->c as $c) {
            $ref = (string) $c['r'];
            $type = (string) $c['t'];
            $col = $ref !== '' ? $this->colIndex($ref) : count($cells);
            if ($type === 's') {
                $idx = (int) $c->v;
                $val = isset($shared[$idx]) ? $shared[$idx] : '';
            } elseif ($type === 'inlineStr') {
                $val = $this->allText($c->asXML());
            } else {
                $val = isset($c->v) ? (string) $c->v : '';
            }
            if ($col < self::COL_CAP) {
                $cells[$col] = $val;
            }
        }
        if (empty($cells)) {
            return array();
        }
        $max = max(array_keys($cells));
        $out = array();
        for ($i = 0; $i <= $max; $i++) {
            $out[] = isset($cells[$i]) ? $cells[$i] : '';
        }
        return $out;
    }

    /** Concatenate every <t> text node inside an XML fragment. */
    private function allText($xml)
    {
        $sx = @simplexml_load_string($this->stripNs($xml));
        if ($sx === false) {
            return '';
        }
        $text = '';
        foreach ($sx->xpath('//t') as $t) {
            $text .= (string) $t;
        }
        return $text;
    }

    /** Strip namespace declarations/prefixes so local names parse cleanly. */
    private function stripNs($xml)
    {
        $xml = preg_replace('/xmlns(:\w+)?\s*=\s*"[^"]*"/', '', (string) $xml);
        return preg_replace('/<(\/?)[A-Za-z0-9]+:/', '<$1', $xml);
    }

    /** Spreadsheet cell reference ("AB12") → 0-based column index. */
    private function colIndex($ref)
    {
        if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
            return 0;
        }
        $letters = strtoupper($m[1]);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    }

    /**
     * Read a JSON array of flat record objects. Columns = union of record keys.
     *
     * @return array{columns:array<int,string>,rows:array<int,array<int,string>>,row_count:int,truncated:bool}
     */
    private function readJson($filePath)
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException('Could not read the dataset file.');
        }
        $data = json_decode($raw, true);
        // Accept {"data": [...]} or {"records": [...]} wrappers too.
        if (is_array($data) && !isset($data[0])) {
            foreach (array('data', 'records', 'rows', 'items') as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    $data = $data[$key];
                    break;
                }
            }
        }
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            throw new \RuntimeException('This JSON is not a tabular array of records.');
        }
        // Column order: first-seen keys across records (bounded).
        $columns = array();
        foreach ($data as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            foreach ($rec as $k => $_) {
                if (!in_array((string) $k, $columns, true) && count($columns) < self::COL_CAP) {
                    $columns[] = (string) $k;
                }
            }
        }
        $columns = $this->normaliseHeader($columns);
        $rows = array();
        $rowCount = 0;
        foreach ($data as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $rowCount++;
            if (count($rows) >= self::ROW_CAP) {
                continue;
            }
            $cells = array();
            foreach ($columns as $col) {
                $v = isset($rec[$col]) ? $rec[$col] : '';
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (is_bool($v)) {
                    $v = $v ? 'true' : 'false';
                } elseif ($v === null) {
                    $v = '';
                }
                $cells[] = $this->clean((string) $v);
            }
            $rows[] = $cells;
        }
        return array(
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => $rowCount,
            'truncated' => $rowCount > count($rows),
        );
    }

    /** @param array<int,mixed> $cells */
    private function rowIsEmpty(array $cells)
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }

    /** Clean, de-duplicate and fill blank header names. @param array<int,mixed> $cells */
    private function normaliseHeader(array $cells)
    {
        $names = array();
        $seen = array();
        $i = 0;
        foreach ($cells as $c) {
            if ($i >= self::COL_CAP) {
                break;
            }
            $name = $this->clean(trim((string) $c));
            if ($name === '') {
                $name = 'column_' . ($i + 1);
            }
            $base = $name;
            $n = 2;
            while (isset($seen[$name])) {
                $name = $base . '_' . $n;
                $n++;
            }
            $seen[$name] = true;
            $names[] = $name;
            $i++;
        }
        return $names;
    }

    /**
     * Pad/trim a data row to the header width and clean each cell.
     * @param array<int,mixed> $cells
     * @return array<int,string>
     */
    private function normaliseRow(array $cells, $width)
    {
        $out = array();
        for ($i = 0; $i < $width; $i++) {
            $out[] = isset($cells[$i]) ? $this->clean((string) $cells[$i]) : '';
        }
        return $out;
    }

    /** UTF-8-safe, control-stripped, length-capped scalar. */
    private function clean($s)
    {
        if ($s === '') {
            return '';
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s);
        if ($s !== null && mb_strlen($s) > self::CELL_CAP) {
            $s = mb_substr($s, 0, self::CELL_CAP);
        }
        return $s === null ? '' : $s;
    }
}
