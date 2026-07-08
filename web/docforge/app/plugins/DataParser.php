<?php

namespace DocForge\Plugins;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

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
     * Read a spreadsheet with the read-only, data-only, row-capped reader.
     *
     * @return array{columns:array<int,string>,rows:array<int,array<int,string>>,row_count:int,truncated:bool}
     */
    private function readSpreadsheet($filePath)
    {
        $reader = IOFactory::createReaderForFile($filePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        // Cap rows read at the source so a huge sheet never fully materialises.
        $cap = self::ROW_CAP;
        if (method_exists($reader, 'setReadFilter')) {
            $reader->setReadFilter(new class($cap + 1) implements IReadFilter {
                private $max;
                public function __construct($max)
                {
                    $this->max = $max;
                }
                public function readCell($column, $row, $worksheetName = '')
                {
                    return $row <= $this->max;
                }
            });
        }
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $columns = array();
        $rows = array();
        $rowCount = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $cells = array();
            $cellIt = $row->getCellIterator();
            $cellIt->setIterateOnlyExistingCells(false);
            $col = 0;
            foreach ($cellIt as $cell) {
                if ($col >= self::COL_CAP) {
                    break;
                }
                $cells[] = (string) $cell->getValue();
                $col++;
            }
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
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        return array(
            'columns' => $columns,
            'rows' => $rows,
            'row_count' => $rowCount,
            'truncated' => $rowCount > count($rows),
        );
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
