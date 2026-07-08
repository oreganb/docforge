<?php

namespace DocForge\Analysis;

/**
 * Forge Data — stage-one dataset profiler.
 *
 * Computes measured facts only (nothing modelled): per-column type inference,
 * null counts, cardinality, descriptive statistics, IQR outliers, pairwise
 * Pearson correlations, duplicate rows, and a privacy (PII) audit. Confidence
 * derives from data quality itself — completeness and type-inference agreement
 * — which feeds the Knowledge Score (FR-15). Stage-two modelling (clustering /
 * regression) is deliberately out of scope and would carry a "modelled, not
 * measured" provenance label.
 */
class DataProfileModule
{
    /** Values (lowercased) treated as null/blank. */
    private static $nullTokens = array('', 'na', 'n/a', 'null', 'nan', 'none', '-', '#n/a');

    /**
     * @param array<string,mixed> $ir
     * @return array<string,mixed>
     */
    public function analyse(array $ir)
    {
        $columns = isset($ir['columns']) ? $ir['columns'] : array();
        $rows = isset($ir['rows']) ? $ir['rows'] : array();
        $rowCount = isset($ir['row_count']) ? (int) $ir['row_count'] : count($rows);
        $scanned = count($rows);

        $profiles = array();
        foreach ($columns as $idx => $name) {
            $values = array();
            foreach ($rows as $r) {
                $values[] = isset($r[$idx]) ? $r[$idx] : '';
            }
            $profiles[] = $this->profileColumn($name, $values);
        }

        $correlations = $this->correlations($profiles, $columns, $rows);
        $duplicates = $this->duplicateRows($rows);
        $findings = $this->findings($profiles, $correlations, $duplicates, $scanned, $rowCount);
        $quality = $this->quality($profiles, $duplicates, $scanned, count($columns));

        $profile = array(
            'format' => isset($ir['dataset']['format']) ? $ir['dataset']['format'] : 'CSV',
            'row_count' => $rowCount,
            'rows_scanned' => $scanned,
            'column_count' => count($columns),
            'duplicate_rows' => $duplicates,
            'truncated' => !empty($ir['truncated']),
            'columns' => $profiles,
            'correlations' => $correlations,
        );

        $overview = $rowCount . ' rows × ' . count($columns) . ' columns ('
            . $profile['format'] . '); ' . count($findings) . ' measured finding(s).';

        return array(
            'profile' => $profile,
            'summaries' => array(
                'short' => $overview,
                'strategy' => 'dataset-profile',
                'key_findings' => $findings,
            ),
            'statistics' => array(
                'word_count' => 0,
                'reading_time_minutes' => 0,
                'flesch_reading_ease' => 'n/a',
            ),
            'dataset_quality' => $quality,
        );
    }

    /**
     * @param array<int,string> $values
     * @return array<string,mixed>
     */
    private function profileColumn($name, array $values)
    {
        $nonNull = array();
        $nulls = 0;
        foreach ($values as $v) {
            if (in_array(mb_strtolower(trim((string) $v)), self::$nullTokens, true)) {
                $nulls++;
            } else {
                $nonNull[] = (string) $v;
            }
        }
        $count = count($nonNull);
        $total = count($values);

        $distinctMap = array();
        foreach ($nonNull as $v) {
            $key = mb_strtolower($v);
            $distinctMap[$key] = isset($distinctMap[$key]) ? $distinctMap[$key] + 1 : 1;
        }
        $distinct = count($distinctMap);

        list($type, $agreement) = $this->inferType($nonNull);

        $col = array(
            'name' => $name,
            'type' => $type,
            'type_agreement' => $agreement,
            'count' => $count,
            'nulls' => $nulls,
            'null_rate' => $total > 0 ? round($nulls / $total, 4) : 0.0,
            'distinct' => $distinct,
            'cardinality_ratio' => $count > 0 ? round($distinct / $count, 4) : 0.0,
            'constant' => $distinct === 1,
        );

        if ($type === 'integer' || $type === 'float') {
            $nums = array();
            foreach ($nonNull as $v) {
                if (is_numeric(str_replace(',', '', trim($v)))) {
                    $nums[] = (float) str_replace(',', '', trim($v));
                }
            }
            $col = array_merge($col, $this->numericStats($nums));
        } elseif ($type === 'date') {
            $stamps = array();
            foreach ($nonNull as $v) {
                $t = strtotime($v);
                if ($t !== false) {
                    $stamps[] = $t;
                }
            }
            if (!empty($stamps)) {
                $col['date_min'] = gmdate('Y-m-d', min($stamps));
                $col['date_max'] = gmdate('Y-m-d', max($stamps));
            }
        } else {
            $lens = array_map('mb_strlen', $nonNull);
            $col['min_len'] = $lens ? min($lens) : 0;
            $col['max_len'] = $lens ? max($lens) : 0;
            $col['avg_len'] = $lens ? round(array_sum($lens) / count($lens), 1) : 0;
            arsort($distinctMap);
            $top = array();
            foreach (array_slice($distinctMap, 0, 3, true) as $val => $c) {
                $top[] = array('value' => $val, 'count' => $c);
            }
            $col['top'] = $top;
        }

        $pii = $this->detectPii($name, $nonNull);
        $col['pii'] = $pii['is_pii'];
        $col['pii_reason'] = $pii['reason'];

        return $col;
    }

    /** @param array<int,float> $nums @return array<string,mixed> */
    private function numericStats(array $nums)
    {
        if (empty($nums)) {
            return array();
        }
        sort($nums);
        $n = count($nums);
        $mean = array_sum($nums) / $n;
        $var = 0.0;
        foreach ($nums as $x) {
            $var += ($x - $mean) * ($x - $mean);
        }
        $std = $n > 1 ? sqrt($var / ($n - 1)) : 0.0;
        $q1 = $this->percentile($nums, 25);
        $q3 = $this->percentile($nums, 75);
        $iqr = $q3 - $q1;
        $lo = $q1 - 1.5 * $iqr;
        $hi = $q3 + 1.5 * $iqr;
        $outliers = 0;
        foreach ($nums as $x) {
            if ($x < $lo || $x > $hi) {
                $outliers++;
            }
        }
        return array(
            'min' => $this->num($nums[0]),
            'max' => $this->num($nums[$n - 1]),
            'mean' => $this->num($mean),
            'std' => $this->num($std),
            'median' => $this->num($this->percentile($nums, 50)),
            'q1' => $this->num($q1),
            'q3' => $this->num($q3),
            'outliers' => $outliers,
        );
    }

    /** @param array<int,float> $sorted (ascending) */
    private function percentile(array $sorted, $p)
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return $sorted[$low];
        }
        $frac = $rank - $low;
        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * $frac;
    }

    private function num($v)
    {
        $r = round((float) $v, 4);
        // Trim trailing zeros for readability while staying numeric.
        return $r == (int) $r ? (int) $r : $r;
    }

    /**
     * Infer a column's type and the fraction of non-null values matching it.
     * @param array<int,string> $vals
     * @return array{0:string,1:float}
     */
    private function inferType(array $vals)
    {
        if (empty($vals)) {
            return array('empty', 0.0);
        }
        $sample = count($vals) > 1000 ? array_slice($vals, 0, 1000) : $vals;
        $n = count($sample);
        $int = $float = $bool = $date = 0;
        foreach ($sample as $v) {
            $t = trim($v);
            $lower = mb_strtolower($t);
            if (in_array($lower, array('true', 'false', 'yes', 'no'), true)) {
                $bool++;
            }
            $numeric = str_replace(',', '', $t);
            if (preg_match('/^-?\d+$/', $numeric)) {
                $int++;
                $float++; // an integer is also a valid float
            } elseif (is_numeric($numeric)) {
                $float++;
            }
            if ($this->looksLikeDate($t)) {
                $date++;
            }
        }
        // Choose the most specific type that (nearly) all values satisfy.
        if ($bool / $n >= 0.95) {
            return array('boolean', round($bool / $n, 3));
        }
        if ($int / $n >= 0.95) {
            return array('integer', round($int / $n, 3));
        }
        if ($float / $n >= 0.95) {
            return array('float', round($float / $n, 3));
        }
        if ($date / $n >= 0.90) {
            return array('date', round($date / $n, 3));
        }
        return array('string', 1.0);
    }

    private function looksLikeDate($v)
    {
        if (!preg_match('/^\d{4}-\d{1,2}-\d{1,2}([ T]\d{1,2}:\d{2}(:\d{2})?)?$/', $v)
            && !preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $v)
            && !preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{2,4}$/', $v)) {
            return false;
        }
        return strtotime($v) !== false;
    }

    /**
     * Pairwise Pearson correlation across numeric columns.
     * @param array<int,array<string,mixed>> $profiles
     * @param array<int,string> $columns
     * @param array<int,array<int,string>> $rows
     * @return array<string,mixed>
     */
    private function correlations(array $profiles, array $columns, array $rows)
    {
        $numericIdx = array();
        foreach ($profiles as $i => $p) {
            if ($p['type'] === 'integer' || $p['type'] === 'float') {
                $numericIdx[] = $i;
            }
        }
        if (count($numericIdx) < 2) {
            return array('columns' => array(), 'matrix' => array(), 'strong' => array());
        }
        // Materialise numeric column vectors once.
        $vectors = array();
        foreach ($numericIdx as $i) {
            $vec = array();
            foreach ($rows as $ri => $r) {
                $raw = isset($r[$i]) ? str_replace(',', '', trim($r[$i])) : '';
                $vec[$ri] = is_numeric($raw) ? (float) $raw : null;
            }
            $vectors[$i] = $vec;
        }
        $names = array();
        foreach ($numericIdx as $i) {
            $names[] = $columns[$i];
        }
        $matrix = array();
        $strong = array();
        foreach ($numericIdx as $a) {
            $rowVals = array();
            foreach ($numericIdx as $b) {
                if ($a === $b) {
                    $rowVals[] = 1.0;
                    continue;
                }
                $r = $this->pearson($vectors[$a], $vectors[$b]);
                $rowVals[] = $r === null ? null : round($r, 2);
                if ($a < $b && $r !== null && abs($r) >= 0.5) {
                    $strong[] = array('a' => $columns[$a], 'b' => $columns[$b], 'r' => round($r, 2));
                }
            }
            $matrix[] = $rowVals;
        }
        usort($strong, function ($x, $y) {
            return abs($y['r']) <=> abs($x['r']);
        });
        return array('columns' => $names, 'matrix' => $matrix, 'strong' => $strong);
    }

    /** @param array<int,?float> $x @param array<int,?float> $y */
    private function pearson(array $x, array $y)
    {
        $sx = $sy = $sxx = $syy = $sxy = 0.0;
        $n = 0;
        foreach ($x as $i => $xv) {
            $yv = isset($y[$i]) ? $y[$i] : null;
            if ($xv === null || $yv === null) {
                continue;
            }
            $sx += $xv;
            $sy += $yv;
            $sxx += $xv * $xv;
            $syy += $yv * $yv;
            $sxy += $xv * $yv;
            $n++;
        }
        if ($n < 3) {
            return null;
        }
        $cov = $sxy - ($sx * $sy) / $n;
        $vx = $sxx - ($sx * $sx) / $n;
        $vy = $syy - ($sy * $sy) / $n;
        if ($vx <= 0 || $vy <= 0) {
            return null;
        }
        return $cov / sqrt($vx * $vy);
    }

    /** @param array<int,array<int,string>> $rows */
    private function duplicateRows(array $rows)
    {
        $seen = array();
        $dupes = 0;
        foreach ($rows as $r) {
            $key = md5(implode("\x1f", $r));
            if (isset($seen[$key])) {
                $dupes++;
            } else {
                $seen[$key] = true;
            }
        }
        return $dupes;
    }

    /**
     * @param array<int,string> $vals
     * @return array{is_pii:bool,reason:string}
     */
    private function detectPii($name, array $vals)
    {
        $nameHint = preg_match('/\b(name|first_?name|last_?name|surname|email|e-?mail|phone|mobile|tel|address|eircode|postcode|zip|ppsn|dob|birth|passport|iban)\b/i', $name);
        $sample = count($vals) > 200 ? array_slice($vals, 0, 200) : $vals;
        $n = max(1, count($sample));
        $email = $eircode = $phone = 0;
        foreach ($sample as $v) {
            $t = trim($v);
            if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $t)) {
                $email++;
            }
            if (preg_match('/^[AC-FHKNPRTV-Y][0-9]{2}\s?[0-9AC-FHKNPRTV-Y]{4}$/i', $t)) {
                $eircode++;
            }
            if (preg_match('/^\+?[0-9][0-9 ().-]{6,}$/', $t) && preg_match_all('/\d/', $t) >= 7) {
                $phone++;
            }
        }
        if ($email / $n >= 0.5) {
            return array('is_pii' => true, 'reason' => 'email addresses');
        }
        if ($eircode / $n >= 0.5) {
            return array('is_pii' => true, 'reason' => 'Eircodes');
        }
        if ($phone / $n >= 0.5) {
            return array('is_pii' => true, 'reason' => 'phone numbers');
        }
        if ($nameHint) {
            return array('is_pii' => true, 'reason' => 'column name suggests personal data');
        }
        return array('is_pii' => false, 'reason' => '');
    }

    /**
     * Measured facts, each located to its column(s).
     * @return array<int,string>
     */
    private function findings(array $profiles, array $correlations, $duplicates, $scanned, $rowCount)
    {
        $out = array();
        foreach ($correlations['strong'] as $c) {
            $out[] = '`' . $c['a'] . '` correlates ' . number_format($c['r'], 2)
                . ' with `' . $c['b'] . '` (n=' . number_format($scanned) . ' rows scanned).';
            if (count($out) >= 5) {
                break;
            }
        }
        // High-null columns.
        foreach ($profiles as $p) {
            if ($p['null_rate'] >= 0.05) {
                $out[] = number_format($p['null_rate'] * 100, 1) . '% nulls in `' . $p['name']
                    . '` (' . number_format($p['nulls']) . ' of ' . number_format($p['nulls'] + $p['count']) . ').';
            }
        }
        // Outliers.
        foreach ($profiles as $p) {
            if (!empty($p['outliers'])) {
                $out[] = $p['outliers'] . ' outlier row(s) by IQR in `' . $p['name'] . '`.';
            }
        }
        // Unique / identifier columns.
        foreach ($profiles as $p) {
            if ($p['count'] > 0 && $p['cardinality_ratio'] >= 0.99 && $p['count'] === $p['distinct']) {
                $out[] = '`' . $p['name'] . '` is unique across all ' . number_format($p['count'])
                    . ' non-null rows (candidate identifier).';
            }
        }
        // Constant columns.
        foreach ($profiles as $p) {
            if (!empty($p['constant'])) {
                $out[] = '`' . $p['name'] . '` is constant (a single value in every row).';
            }
        }
        if ($duplicates > 0) {
            $out[] = number_format($duplicates) . ' fully-duplicate row(s) detected'
                . ' (of ' . number_format($scanned) . ' scanned).';
        }
        return $out;
    }

    /**
     * Knowledge Score from data quality: completeness (non-null), type-inference
     * agreement (consistency), and integrity (duplicate-free).
     * @return array<string,mixed>
     */
    private function quality(array $profiles, $duplicates, $scanned, $columnCount)
    {
        $issues = array();
        $notes = array();

        $nullRates = array();
        $agreements = array();
        $piiCols = array();
        foreach ($profiles as $p) {
            $nullRates[] = $p['null_rate'];
            if ($p['type'] !== 'empty' && $p['type'] !== 'string') {
                $agreements[] = $p['type_agreement'];
            }
            if (!empty($p['pii'])) {
                $piiCols[] = $p['name'] . ' (' . $p['pii_reason'] . ')';
            }
            // A typed column with < 95% agreement is mixed-type.
            if (in_array($p['type'], array('integer', 'float', 'boolean', 'date'), true)
                && $p['type_agreement'] < 0.95) {
                $issues[] = 'Column `' . $p['name'] . '` is mixed-type (only '
                    . round($p['type_agreement'] * 100) . '% of values match the inferred '
                    . $p['type'] . ' type).';
            }
        }

        $completeness = empty($nullRates) ? 1.0 : (1 - array_sum($nullRates) / count($nullRates));
        $consistency = empty($agreements) ? 1.0 : array_sum($agreements) / count($agreements);
        $integrity = $scanned > 0 ? (1 - $duplicates / $scanned) : 1.0;

        $score = (int) round(100 * (0.5 * $completeness + 0.4 * $consistency + 0.1 * $integrity));
        $score = max(0, min(100, $score));

        if (!empty($piiCols)) {
            $issues[] = 'Privacy: probable personal data in ' . count($piiCols) . ' column(s): '
                . implode(', ', $piiCols) . '. Handle under data-protection obligations.';
        }
        if ($duplicates > 0) {
            $notes[] = number_format($duplicates) . ' duplicate row(s) counted toward the integrity sub-score.';
        }
        $notes[] = 'All figures are measured directly from the data; nothing is modelled. '
            . 'Stage-two modelling (clustering / regression) would be labelled "modelled, not measured".';

        if (!empty($issues)) {
            $verdict = 'Dataset profiled with ' . count($issues) . ' quality/privacy issue(s) — see below.';
        } else {
            $verdict = 'Dataset profiled cleanly; no mixed-type or privacy issues detected.';
        }

        return array(
            'knowledge_score' => $score,
            'sub_scores' => array(
                'completeness' => (int) round($completeness * 100),
                'consistency' => (int) round($consistency * 100),
                'integrity' => (int) round($integrity * 100),
            ),
            'verdict' => $verdict,
            'issues' => $issues,
            'notes' => $notes,
        );
    }
}
