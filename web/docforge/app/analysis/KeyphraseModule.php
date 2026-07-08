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
        $text = $this->prepare($ir['full_text']);
        $phrases = array();
        try {
            $rake = RakePlus::create($text, 'en_US');
            $results = $rake->sortByScore('desc')->scores();
            $i = 0;
            foreach ($results as $phrase => $score) {
                if ($i >= 15) {
                    break;
                }
                $len = mb_strlen($phrase);
                // Skip fragments and RAKE artefacts (a real keyphrase is short;
                // an over-long "phrase" is usually a run of text with no
                // punctuation/stopwords to split on).
                if ($len < 3 || $len > 80) {
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

    /**
     * Treat every line (bullet, heading, paragraph) as its own unit so RAKE
     * phrases never straddle a bullet or section boundary — the root cause of
     * contaminated keyphrases like "channels 2" (page/section number bleed) and
     * "services • manages projects" (cross-bullet runs). Also strips list glyphs.
     */
    private function prepare($text)
    {
        $text = preg_replace('/[\x{2022}\x{2023}\x{25AA}\x{25CF}\x{25E6}\x{2043}\x{2219}\x{00B7}\x{2713}\x{2714}\x{2717}\x{2718}\x{2610}\x{2611}\x{2612}]/u', ' ', (string) $text);
        $text = preg_replace('/\R+/u', ' . ', $text);
        return $text;
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
