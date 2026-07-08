<?php

namespace DocForge\Analysis;

use PhpScience\TextRank\TextRankFacade;
use PhpScience\TextRank\Tool\StopWords\English;
use PhpScience\TextRank\Tool\Summarize;

class SummaryModule extends AbstractModule
{
    public function applies(array $ir)
    {
        return !empty($ir['full_text']) && strlen($ir['full_text']) > 100;
    }

    /** Cap the text fed to TextRank to keep the O(n^2) graph fast on large docs. */
    const MAX_CHARS = 40000;

    public function analyse(array $ir)
    {
        $text = $ir['full_text'];
        $sentences = $this->sentences($text);
        $short = '';
        $extended = '';
        $findings = array();

        $ranked = array();
        try {
            $input = strlen($text) > self::MAX_CHARS ? substr($text, 0, self::MAX_CHARS) : $text;
            $facade = new TextRankFacade();
            $facade->setStopWords(new English());
            // Returns an array of the most important sentences (index => sentence).
            $topSentences = $facade->summarizeTextFreely($input, 12, 8, Summarize::GET_ALL_IMPORTANT);
            $ranked = array_values(array_filter(array_map('trim', $topSentences)));
        } catch (\Throwable $e) {
            $ranked = array();
        }

        if (empty($ranked)) {
            // Fallback: lead sentences.
            $ranked = array_slice($sentences, 0, 8);
        }

        $shortParts = array_slice($ranked, 0, 5);
        $short = $this->trimWords(implode(' ', $shortParts), 150);
        $extended = $this->trimWords(implode(' ', $ranked), 500);
        foreach ($shortParts as $sentence) {
            if (preg_match('/\d|%|therefore|thus|conclude|significant/i', $sentence)) {
                $findings[] = $sentence;
            }
        }

        if ($short === '' && !empty($sentences)) {
            $short = $this->trimWords($sentences[0], 150);
        }

        return array(
            'summaries' => array(
                'short' => $short,
                'extended' => $extended,
                'key_findings' => array_slice($findings, 0, 5),
            ),
        );
    }

    private function sentences($text)
    {
        $parts = preg_split('/(?<=[.!?])\s+/', trim($text));
        return array_values(array_filter($parts));
    }

    private function trimWords($text, $max)
    {
        $words = preg_split('/\s+/', trim($text));
        if (count($words) <= $max) {
            return trim($text);
        }
        return implode(' ', array_slice($words, 0, $max)) . '…';
    }

    protected static function toolName()
    {
        return 'TextRank';
    }

    public function confidence()
    {
        return 72;
    }
}
