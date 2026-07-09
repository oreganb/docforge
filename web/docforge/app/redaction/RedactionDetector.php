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
    private $gazetteer;

    /** @param array<string,mixed> $config redaction config categories + tier3 */
    public function __construct(array $config)
    {
        $this->config = isset($config['categories']) ? $config['categories'] : array();
        $tier3 = isset($config['tier3']) ? $config['tier3'] : array();
        $this->routingKeys = $this->loadRoutingKeys();
        $this->gazetteer = !empty($tier3['gazetteers']) ? $this->loadGazetteers() : array();
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
        if (!empty($this->gazetteer)) {
            if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][\'’][a-z]+|[A-Z][a-z]+)?)\b/u', $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $hit) {
                    $parts = preg_split('/\s+/', $hit[0]);
                    foreach ($parts as $part) {
                        if (isset($this->gazetteer[mb_strtolower($part)])) {
                            $pos = mb_strpos($text, $hit[0], 0, 'UTF-8');
                            if ($pos !== false) {
                                $out[] = $this->span($pos, mb_strlen($hit[0], 'UTF-8'), 'person', 3, $hit[0]);
                            }
                            break;
                        }
                    }
                }
            }
        }
        return $out;
    }

    private function detectAddresses($text)
    {
        $out = array();
        if (preg_match_all(
            '/\b\d{1,4}\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,3}\s+(?:Road|Street|Avenue|Lane|Drive|Park|Close|Court|Way)\b[^\\n]{0,40}/u',
            $text,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[0] as $hit) {
                $out[] = $this->span($hit[1], strlen($hit[0]), 'address', 3, $hit[0]);
            }
        }
        return $out;
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

    private function loadGazetteers()
    {
        $words = array();
        $dir = dirname(__DIR__) . '/config/gazetteers';
        if (!is_dir($dir)) {
            return $words;
        }
        foreach (glob($dir . '/*.txt') as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $w = mb_strtolower(trim($line));
                if ($w !== '') {
                    $words[$w] = true;
                }
            }
        }
        return $words;
    }
}
