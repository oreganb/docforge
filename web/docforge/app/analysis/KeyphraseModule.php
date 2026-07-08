<?php

namespace DocForge\Analysis;

use DonatelloZa\RakePlus\RakePlus;

class KeyphraseModule extends AbstractModule
{
    public function applies(array $ir)
    {
        return !empty($ir['full_text']);
    }

    public function analyse(array $ir)
    {
        $text = $ir['full_text'];
        $phrases = array();
        try {
            $rake = RakePlus::create($text, 'en_US');
            $results = $rake->sortByScore('desc')->scores();
            $i = 0;
            foreach ($results as $phrase => $score) {
                if ($i >= 15) {
                    break;
                }
                if (strlen($phrase) < 3) {
                    continue;
                }
                $phrases[] = array('phrase' => $phrase, 'score' => round($score, 4));
                $i++;
            }
        } catch (\Throwable $e) {
            $phrases = $this->fallback($text);
        }
        return array('keyphrases' => $phrases);
    }

    private function fallback($text)
    {
        $words = str_word_count(strtolower($text), 1);
        $stop = array('the', 'and', 'for', 'that', 'with', 'this', 'from', 'are', 'was', 'were');
        $freq = array();
        foreach ($words as $w) {
            if (strlen($w) < 4 || in_array($w, $stop, true)) {
                continue;
            }
            if (!isset($freq[$w])) {
                $freq[$w] = 0;
            }
            $freq[$w]++;
        }
        arsort($freq);
        $out = array();
        $i = 0;
        foreach ($freq as $word => $count) {
            if ($i >= 15) {
                break;
            }
            $out[] = array('phrase' => $word, 'score' => (float) $count);
            $i++;
        }
        return $out;
    }

    protected static function toolName()
    {
        return 'rake-php-plus';
    }
}
