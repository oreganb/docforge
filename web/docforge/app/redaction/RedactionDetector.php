<?php

namespace DocForge\Redaction;

/**
 * Detect PII spans across document text (FORGE_REDACT_SPECS.md §3).
 * Returns non-overlapping spans with category, tier, and surface form.
 */
class RedactionDetector
{
    /** @var array<string,bool> */
    private $config;
    /** @var array<string,bool> */
    private $routingKeys;
    /** @var array<string,bool> */
    private $firstNames;
    /** @var array<string,bool> */
    private $surnames;

    /** @param array<string,mixed> $config redaction config categories + tier3 */
    public function __construct(array $config)
    {
        $this->config = isset($config['categories']) ? $config['categories'] : array();
        $tier3 = isset($config['tier3']) ? $config['tier3'] : array();
        $this->routingKeys = $this->loadRoutingKeys();
        $this->firstNames = array();
        $this->surnames = array();
        if (!empty($tier3['gazetteers'])) {
            list($this->firstNames, $this->surnames) = $this->loadNameGazetteers();
        }
    }

    /**
     * @return array<int,array{start:int,length:int,category:string,tier:int,surface:string}>
     */
    public function detect($text)
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return array();
        }

        $spans = array();
        if ($this->on('ppsn')) {
            $spans = array_merge($spans, $this->detectPpsn($text));
        }
        if ($this->on('iban')) {
            $spans = array_merge($spans, $this->detectIban($text));
        }
        if ($this->on('card')) {
            $spans = array_merge($spans, $this->detectCards($text));
        }
        if ($this->on('vat')) {
            $spans = array_merge($spans, $this->detectVat($text));
        }
        if ($this->on('eircode')) {
            $spans = array_merge($spans, $this->detectEircode($text));
        }
        if ($this->on('email')) {
            $spans = array_merge($spans, $this->detectPattern(
                $text,
                '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
                'email',
                2
            ));
        }
        if ($this->on('phone')) {
            $spans = array_merge($spans, $this->detectPhones($text));
        }
        if ($this->on('dob')) {
            $spans = array_merge($spans, $this->detectDob($text));
        }
        if ($this->on('account')) {
            $spans = array_merge($spans, $this->detectAccounts($text));
        }
        if ($this->on('person')) {
            $spans = array_merge($spans, $this->detectPersons($text));
        }
        if ($this->on('address')) {
            $spans = array_merge($spans, $this->detectAddresses($text));
        }

        return $this->mergeSpans($spans);
    }

    private function on($cat)
    {
        return !empty($this->config[$cat]);
    }

    private function normalize($text)
    {
        if (!is_string($text)) {
            return '';
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return $text;
    }

    private function detectPpsn($text)
    {
        $out = array();
        if (preg_match_all('/\b(\d{7}[A-W][AHWTX]?)\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $surface = $hit[0];
                if (PpsnValidator::isValid($surface)) {
                    $out[] = $this->span($hit[1], strlen($surface), 'ppsn', 1, $surface);
                }
            }
        }
        return $out;
    }

    private function detectIban($text)
    {
        $out = array();
        if (preg_match_all('/\b([A-Z]{2}\d{2}[A-Z0-9 ]{11,34})\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $hit) {
                $surface = trim($hit[0]);
                if (IbanValidator::isValid($surface)) {
                    $out[] = $this->span($hit[1], strlen($hit[0]), 'iban', 1, $surface);
                }
            }
        }
        return $out;
    }

    private function detectCards($text)
    {
        $out = array();
        if (preg_match_all('/\b(?:\d[ -]*?){13,19}\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $surface = $hit[0];
                $digits = preg_replace('/\D/', '', $surface);
                if (LuhnValidator::isValid($digits)) {
                    $out[] = $this->span($hit[1], strlen($surface), 'card', 1, $surface);
                }
            }
        }
        return $out;
    }

    private function detectVat($text)
    {
        return $this->detectPattern(
            $text,
            '/\bIE\d{7}[A-Z]{1,2}\b/i',
            'vat',
            1
        );
    }

    private function detectEircode($text)
    {
        $out = array();
        if (preg_match_all(
            '/\b([AC-FHKNPRTV-Y]\d{2}[A-Z0-9]?\s?[A-Z0-9]{4})\b/i',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $hit) {
                $surface = strtoupper(preg_replace('/\s+/', '', $hit[0]));
                $routing = substr($surface, 0, 3);
                if (isset($this->routingKeys[$routing])) {
                    $out[] = $this->span($hit[1], strlen($hit[0]), 'eircode', 2, $hit[0]);
                }
            }
        }
        return $out;
    }

    private function detectPhones($text)
    {
        $out = array();
        $patterns = array(
            '/\+353[\s\-]?\d{2}[\s\-]?\d{3}[\s\-]?\d{4}\b/',
            '/\b0[1-9]\d{1,2}[\s\-]?\d{3,4}[\s\-]?\d{3,4}\b/',
        );
        foreach ($patterns as $pat) {
            if (preg_match_all($pat, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $digits = preg_replace('/\D/', '', $hit[0]);
                    if (strlen($digits) >= 9 && strlen($digits) <= 13) {
                        $out[] = $this->span($hit[1], strlen($hit[0]), 'phone', 2, $hit[0]);
                    }
                }
            }
        }
        return $out;
    }

    private function detectDob($text)
    {
        $out = array();
        $datePat = '(?:\d{4}[-\/]\d{1,2}[-\/]\d{1,2}|\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})';
        if (preg_match_all(
            '/(?:DOB|D\.O\.B|date of birth|born)\s*[:\-]?\s*(' . $datePat . ')/iu',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $hit) {
                $out[] = $this->span($hit[1], strlen($hit[0]), 'dob', 2, $hit[0]);
            }
        }
        return $out;
    }

    private function detectAccounts($text)
    {
        $out = array();
        if (preg_match_all(
            '/(?:account|acc(?:ount)?\s*no|sort\s*code|BIC).{0,40}?(\d{2}-\d{2}-\d{2}|\b\d{8}\b)/iu',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $hit) {
                $out[] = $this->span($hit[1], strlen($hit[0]), 'account', 2, $hit[0]);
            }
        }
        return $out;
    }

    private function detectPersons($text)
    {
        $out = array();
        $patterns = array(
            '/\b(?:Mr|Mrs|Ms|Dr|Prof)\.?\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/',
            '/\bDear\s+([A-Z][a-z]+)\b/',
            '/\b(?:From|To|Cc):\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/',
        );
        foreach ($patterns as $pat) {
            if (preg_match_all($pat, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $hit) {
                    $out[] = $this->span($hit[1], strlen($hit[0]), 'person', 3, $hit[0]);
                }
            }
        }
        if (!empty($this->firstNames) || !empty($this->surnames)) {
            // Two-word capitalised names only — never bare placenames like "Cork"
            // used as a topic (FORGE_REDACT_SPECS corpus #010 non-PII control).
            if (preg_match_all(
                '/\b([A-Z][a-z]{2,})\s+([A-Z][\'’][a-z]{2,}|[A-Z][a-z]{2,})\b/u',
                $text,
                $m,
                PREG_OFFSET_CAPTURE
            )) {
                foreach ($m[0] as $i => $hit) {
                    $w1 = $m[1][$i][0];
                    $w2 = $m[2][$i][0];
                    $l1 = mb_strtolower($w1);
                    $l2 = mb_strtolower(str_replace(array('’', "'"), '', $w2));
                    $nameHit = isset($this->firstNames[$l1]) && isset($this->surnames[$l2]);
                    if ($nameHit) {
                        $out[] = $this->span($hit[1], strlen($hit[0]), 'person', 3, $hit[0]);
                    }
                }
            }
        }
        return $out;
    }

    private function detectAddresses($text)
    {
        $out = array();
        $sfx = $this->streetSuffixPattern();
        $word = '[A-Z][A-Za-z\'’\-]+';

        $patterns = array(
            // Multi-line: house/estate line + road/town line (e.g. 69 Belgard Downs / Rochestown Road Douglas Cork)
            '/\b(\d{1,4}[A-Z]?\s+(?:' . $word . '(?:\s+' . $word . '){0,5})(?:\s+' . $sfx . ')?)'
            . '\s*\n\s*((?:' . $word . '(?:\s+' . $word . '){0,5}\s+)?' . $sfx . '(?:\s+' . $word . '){0,4})\b/u',
            // Single line: number + estate/street suffix (e.g. 69 Belgard Downs)
            '/\b\d{1,4}[A-Z]?\s+(?:' . $word . '(?:\s+' . $word . '){0,5})\s+' . $sfx . '\b/u',
            // Street line with optional area and town (e.g. Rochestown Road Douglas Cork)
            '/\b(?:\d{1,4}[A-Z]?\s+)?(?:' . $word . '(?:\s+' . $word . '){0,5})\s+' . $sfx . '(?:\s+' . $word . '){0,4}\b/u',
        );
        foreach ($patterns as $pat) {
            if (preg_match_all($pat, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $out[] = $this->span($hit[1], strlen($hit[0]), 'address', 3, $hit[0]);
                }
            }
        }

        $out = array_merge($out, $this->detectNameAddressBlocks($text, $sfx, $word));
        $out = array_merge($out, $this->detectAddressClustersByEircode($text));

        return $out;
    }

    private function streetSuffixPattern()
    {
        return '(?:Road|Street|Avenue|Lane|Drive|Park|Close|Court|Way|Place|Square|Terrace|Crescent|'
            . 'Grove|Green|Estate|Downs|Heights|Manor|View|Walk|Row|Rise|Hill|Gardens|Mews|Wood|Woods|'
            . 'Vale|Meadows|Lawn|Lawns|Quay|Pier|Strand|Boulevard|Bypass|Parkway|Nook|Village|Gate|'
            . 'Bridge|Cottages|Centre|Center)';
    }

    /**
     * Name line followed by one or two address lines (common on forms and letters).
     *
     * @return array<int,array<string,mixed>>
     */
    private function detectNameAddressBlocks($text, $sfx, $word)
    {
        $out = array();
        $name = '(?:(?:Mr|Mrs|Ms|Dr|Prof)\.?\s+)?(?:' . $word . '\s+' . $word . ')';
        $house = '\d{1,4}[A-Z]?\s+(?:' . $word . '(?:\s+' . $word . '){0,5})(?:\s+' . $sfx . ')?';
        $street = '(?:' . $word . '(?:\s+' . $word . '){0,5}\s+)?' . $sfx . '(?:\s+' . $word . '){0,4}';
        $patterns = array(
            '/(?:^|\n)\s*(' . $name . ')\s*\n\s*(' . $house . ')\s*\n\s*(' . $street . ')\b/um',
            '/(?:^|\n)\s*(' . $name . ')\s*\n\s*(' . $house . ')\b/um',
        );
        foreach ($patterns as $pat) {
            if (preg_match_all($pat, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $out[] = $this->span($hit[1], strlen($hit[0]), 'address', 3, $hit[0]);
                }
            }
        }
        return $out;
    }

    /**
     * Lines immediately before a validated Eircode (name + address + eircode pattern).
     *
     * @return array<int,array<string,mixed>>
     */
    private function detectAddressClustersByEircode($text)
    {
        $out = array();
        if (preg_match_all(
            '/\b([AC-FHKNPRTV-Y]\d{2}[A-Z0-9]?\s?[A-Z0-9]{4})\b/i',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[0] as $hit) {
                $eStart = $hit[1];
                $routing = strtoupper(substr(preg_replace('/\s+/', '', $hit[0]), 0, 3));
                if (!isset($this->routingKeys[$routing])) {
                    continue;
                }
                $sliceStart = max(0, $eStart - 500);
                $before = substr($text, $sliceStart, $eStart - $sliceStart);
                $block = $this->addressBlockBeforeEircode($before);
                if ($block === null) {
                    continue;
                }
                $start = $sliceStart + $block['offset'];
                $out[] = $this->span($start, strlen($block['text']), 'address', 3, $block['text']);
            }
        }
        return $out;
    }

    /**
     * @return array{offset:int,text:string}|null
     */
    private function addressBlockBeforeEircode($before)
    {
        $before = rtrim($before, " \t\r\n");
        if ($before === '') {
            return null;
        }
        $lines = preg_split('/\R/u', $before);
        $lines = array_values(array_filter($lines, function ($line) {
            return trim($line) !== '';
        }));
        if (empty($lines)) {
            return null;
        }

        $collected = array();
        $i = count($lines) - 1;
        while ($i >= 0 && count($collected) < 3) {
            $line = trim($lines[$i]);
            if ($this->looksLikeAddressLine($line)) {
                array_unshift($collected, $line);
                $i--;
                continue;
            }
            break;
        }
        if (empty($collected)) {
            return null;
        }
        if ($i >= 0 && $this->looksLikePersonLine(trim($lines[$i]))) {
            array_unshift($collected, trim($lines[$i]));
        }

        $blockText = implode("\n", $collected);
        $offset = strrpos($before, $collected[0]);
        if ($offset === false) {
            return null;
        }
        return array('offset' => $offset, 'text' => substr($before, $offset));
    }

    private function looksLikeAddressLine($line)
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }
        if (preg_match('/^\d{1,4}[A-Z]?\s+/u', $line)) {
            return true;
        }
        if (preg_match('/\b' . $this->streetSuffixPattern() . '\b/iu', $line)) {
            return true;
        }
        return false;
    }

    private function looksLikePersonLine($line)
    {
        if (preg_match('/^(?:Mr|Mrs|Ms|Dr|Prof)\.?\s+/i', $line)) {
            return true;
        }
        if (preg_match('/^([A-Z][a-z]{2,})\s+([A-Z][\'’]?[a-z]{2,})$/u', $line, $m)) {
            $l1 = mb_strtolower($m[1]);
            $l2 = mb_strtolower(str_replace(array('’', "'"), '', $m[2]));
            return isset($this->firstNames[$l1]) && isset($this->surnames[$l2]);
        }
        return false;
    }

    private function detectPattern($text, $pattern, $category, $tier)
    {
        $out = array();
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $out[] = $this->span($hit[1], strlen($hit[0]), $category, $tier, $hit[0]);
            }
        }
        return $out;
    }

    private function span($start, $length, $category, $tier, $surface)
    {
        return array(
            'start' => (int) $start,
            'length' => (int) $length,
            'category' => $category,
            'tier' => (int) $tier,
            'surface' => $surface,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $spans
     * @return array<int,array<string,mixed>>
     */
    private function mergeSpans(array $spans)
    {
        if (empty($spans)) {
            return array();
        }
        usort($spans, function ($a, $b) {
            if ($a['start'] === $b['start']) {
                return $b['length'] - $a['length'];
            }
            return $a['start'] - $b['start'];
        });
        $merged = array();
        foreach ($spans as $s) {
            $overlap = false;
            foreach ($merged as $i => $m) {
                if ($this->overlaps($s, $m)) {
                    if ($s['tier'] < $m['tier'] || ($s['tier'] === $m['tier'] && $s['length'] > $m['length'])) {
                        $merged[$i] = $s;
                    }
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) {
                $merged[] = $s;
            }
        }
        usort($merged, function ($a, $b) {
            return $a['start'] - $b['start'];
        });
        return $merged;
    }

    private function overlaps($a, $b)
    {
        $aEnd = $a['start'] + $a['length'];
        $bEnd = $b['start'] + $b['length'];
        return $a['start'] < $bEnd && $b['start'] < $aEnd;
    }

    private function loadRoutingKeys()
    {
        $path = dirname(__DIR__) . '/config/eircode_routing_keys.txt';
        $keys = array();
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $keys[strtoupper(trim($line))] = true;
            }
        }
        return $keys;
    }

    /**
     * @return array{0:array<string,bool>,1:array<string,bool>}
     */
    private function loadNameGazetteers()
    {
        $first = array();
        $last = array();
        $dir = dirname(__DIR__) . '/config/gazetteers';
        $map = array(
            'irish_firstnames.txt' => 'first',
            'irish_surnames.txt' => 'last',
        );
        foreach ($map as $file => $kind) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $w = mb_strtolower(trim($line));
                if ($w === '') {
                    continue;
                }
                if ($kind === 'first') {
                    $first[$w] = true;
                } else {
                    $last[$w] = true;
                }
            }
        }
        return array($first, $last);
    }
}
