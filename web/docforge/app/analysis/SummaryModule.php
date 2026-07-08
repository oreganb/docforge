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

    /** A document is "list-dominant" when most blocks are bullet items. */
    const LIST_DOMINANT = 0.5;

    /**
     * A sentence carries a genuine finding when it has real quantitative signal
     * (a percentage or a multi-digit quantity) or a conclusive verb. Single
     * digits (e.g. "Grade 7", "Admin II") are labels, not findings.
     */
    const FINDING_SIGNAL = '/%|\d{2,}|\b(therefore|thus|conclude[ds]?|concluded|significant(ly)?|increase[ds]?|decrease[ds]?|reduced|improved)\b/i';

    public function analyse(array $ir)
    {
        // List-dominant documents (competency frameworks, checklists, spec
        // sheets) give sentence-ranking no signal — ranked prose assumptions
        // don't hold. Switch to a deterministic, structure-templated summary
        // assembled entirely from the extracted section headings.
        $headings = isset($ir['headings']) ? $ir['headings'] : array();
        $listRatio = isset($ir['list_ratio']) ? $ir['list_ratio'] : 0.0;
        if ($listRatio >= self::LIST_DOMINANT && count($headings) >= 2) {
            return $this->templatedSummary($ir, $headings);
        }

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
            if (preg_match(self::FINDING_SIGNAL, $sentence)) {
                $findings[] = $sentence;
            }
        }

        if ($short === '' && !empty($sentences)) {
            $short = $this->trimWords($sentences[0], 150);
        }

        $findings = array_slice($findings, 0, 5);
        return array(
            'summaries' => array(
                'short' => $short,
                'extended' => $extended,
                'key_findings' => $findings,
                'findings_note' => empty($findings)
                    ? 'No quantitative or conclusive findings detected.'
                    : '',
            ),
        );
    }

    /**
     * Build a heading-templated summary for list-dominant documents. Every word
     * comes from the extracted structure — no generation, no invented content.
     */
    private function templatedSummary(array $ir, array $headings)
    {
        $lead = $this->leadSentence($ir);
        $n = count($headings);
        $joined = implode('; ', $headings);
        $body = 'Defines ' . $this->numberWord($n) . ' sections: ' . $joined . '.';
        $short = trim(($lead !== '' ? $lead . ' ' : '') . $body);

        // Key Findings must not simply echo the Contents tree. Only surface
        // genuinely quantitative/conclusive sentences from the body; if a
        // list-type document has none, say so honestly (FR-4.3).
        $findings = $this->extractFindings($ir);

        return array(
            'summaries' => array(
                'short' => $this->trimWords($short, 150),
                'extended' => $this->trimWords($short, 500),
                'key_findings' => $findings,
                'findings_note' => empty($findings)
                    ? 'No quantitative or conclusive findings detected (list-type document).'
                    : '',
                'strategy' => 'structure-templated',
            ),
        );
    }

    /**
     * Scan body blocks (lists + paragraphs, not headings) for sentences that
     * carry quantitative or conclusive signal. Returns up to five, or an empty
     * array when the document is purely descriptive.
     *
     * @return array<int,string>
     */
    private function extractFindings(array $ir)
    {
        if (empty($ir['blocks']) || !is_array($ir['blocks'])) {
            return array();
        }
        $findings = array();
        foreach ($ir['blocks'] as $block) {
            $type = isset($block['type']) ? $block['type'] : '';
            if ($type !== 'list' && $type !== 'paragraph') {
                continue;
            }
            foreach ($this->sentences((string) $block['text']) as $sentence) {
                if (preg_match(self::FINDING_SIGNAL, $sentence)) {
                    $findings[] = trim($sentence);
                    if (count($findings) >= 5) {
                        return $findings;
                    }
                }
            }
        }
        return $findings;
    }

    /** First sentence of the first paragraph block (document lead-in), if any. */
    private function leadSentence(array $ir)
    {
        if (empty($ir['blocks']) || !is_array($ir['blocks'])) {
            return '';
        }
        foreach ($ir['blocks'] as $block) {
            if (isset($block['type']) && $block['type'] === 'heading') {
                return ''; // reached the first heading before any prose lead-in
            }
            if (isset($block['type']) && $block['type'] === 'paragraph' && trim($block['text']) !== '') {
                $parts = $this->sentences($block['text']);
                return isset($parts[0]) ? trim($parts[0]) : trim($block['text']);
            }
        }
        return '';
    }

    private function numberWord($n)
    {
        $words = array(1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
            11 => 'eleven', 12 => 'twelve');
        return isset($words[$n]) ? $words[$n] : (string) $n;
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
