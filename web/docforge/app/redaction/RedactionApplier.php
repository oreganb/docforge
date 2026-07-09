<?php

namespace DocForge\Redaction;

/**
 * Apply mask or token substitutions to text using detected spans.
 */
class RedactionApplier
{
    /** @var string */
    private $maskChar;
    /** @var TokenRegistry */
    private $tokens;

    /** @param array<string,mixed> $config */
    public function __construct(array $config, $mode)
    {
        $this->maskChar = isset($config['mask_char']) ? $config['mask_char'] : 'XXXXXXXXX';
        $this->tokens = new TokenRegistry($mode === 'token' ? 'token' : 'mask', $this->maskChar);
    }

    /**
     * @param array<int,array<string,mixed>> $spans
     */
    public function apply($text, array $spans)
    {
        if ($text === '' || empty($spans)) {
            return $text;
        }
        $sorted = $spans;
        usort($sorted, function ($a, $b) {
            return $b['start'] - $a['start'];
        });
        foreach ($sorted as $s) {
            $replacement = $this->tokens->replace($s['category'], $s['surface']);
            $text = substr_replace($text, $replacement, $s['start'], $s['length']);
        }
        return $text;
    }

    /**
     * Replace every whole-word occurrence of each detected surface in the text.
     * Catches repeats the span pass missed (e.g. a name used many times).
     *
     * @param array<int,array<string,mixed>> $spans
     */
    public function applyAllOccurrences($text, array $spans)
    {
        if ($text === '' || empty($spans)) {
            return $text;
        }
        $unique = array();
        foreach ($spans as $s) {
            if (empty($s['surface'])) {
                continue;
            }
            $key = mb_strtolower($s['surface']);
            if (!isset($unique[$key]) || mb_strlen($s['surface']) > mb_strlen($unique[$key]['surface'])) {
                $unique[$key] = $s;
            }
        }
        $list = array_values($unique);
        usort($list, function ($a, $b) {
            return mb_strlen($b['surface']) - mb_strlen($a['surface']);
        });
        foreach ($list as $s) {
            $replacement = $this->tokens->replace($s['category'], $s['surface']);
            $quoted = preg_quote($s['surface'], '/');
            $text = preg_replace(
                '/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu',
                $replacement,
                $text
            );
        }
        return $text;
    }

    /** @return array<string,string> token => surface (for re-identification) */
    public function getMap()
    {
        return $this->tokens->exportMap();
    }

    public function getReverseMap()
    {
        return $this->tokens->exportReverseMap();
    }
}
