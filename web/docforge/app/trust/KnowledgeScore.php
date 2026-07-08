<?php

namespace DocForge\Trust;

/**
 * Knowledge Score composer (FR-18).
 */
class KnowledgeScore
{
    /**
     * @param array<string,mixed> $doc
     * @return array{knowledge_score:int,sub_scores:array<string,int>}
     */
    public static function compute(array $doc)
    {
        // Dimensions that produced no content report n/a and drop out of the
        // composite (their weight is redistributed), rather than being scored
        // as a misleading default. Phase 1 has no table extraction, and
        // references only apply to documents that actually cite sources.
        $dims = array(
            'structure' => array('score' => self::scoreStructure($doc), 'weight' => 0.25),
            'content' => array('score' => self::scoreContent($doc), 'weight' => 0.35),
        );

        $refCount = isset($doc['references']) ? count($doc['references']) : 0;
        $dims['references'] = array(
            'score' => $refCount > 0 ? self::scoreReferences($doc) : null,
            'weight' => 0.20,
        );
        $dims['tables'] = array('score' => null, 'weight' => 0.20);

        $weighted = 0.0;
        $weightSum = 0.0;
        $subs = array();
        foreach ($dims as $name => $d) {
            if ($d['score'] === null) {
                $subs[$name] = 'n/a';
                continue;
            }
            $subs[$name] = (int) $d['score'];
            $weighted += $d['score'] * $d['weight'];
            $weightSum += $d['weight'];
        }
        $overall = $weightSum > 0 ? (int) round($weighted / $weightSum) : 0;

        // Critical-dimension floor cap: a fatal failure in Content (body-content
        // loss) must not be masked by a strong Structure score. A beautiful
        // skeleton with no body cannot be trusted, so it cannot score highly.
        if (is_int($subs['content']) && $subs['content'] < 40) {
            $overall = min($overall, max(30, $subs['content']));
        }

        return array(
            'knowledge_score' => min(100, max(0, $overall)),
            'sub_scores' => $subs,
        );
    }

    private static function scoreStructure(array $doc)
    {
        $sectionList = isset($doc['sections']) ? $doc['sections'] : array();
        $sections = count($sectionList);
        $blocks = isset($doc['blocks']) ? count($doc['blocks']) : 0;
        if ($blocks === 0) {
            return 30;
        }
        $score = min(98, 50 + $sections * 8 + min(20, (int) ($blocks / 5)));

        // A credible skeleton distributes body content across its sections.
        // When one "section" swallows the overwhelming majority of the words
        // while the rest are near-empty, the heading detection has most likely
        // mis-segmented the document — collapse confidence instead of rewarding
        // it (the Test 2 lesson: don't let a weak dimension read strong).
        if ($sections >= 4) {
            $total = 0;
            $max = 0;
            foreach ($sectionList as $s) {
                $wc = (int) (isset($s['word_count']) ? $s['word_count'] : 0);
                $total += $wc;
                if ($wc > $max) {
                    $max = $wc;
                }
            }
            if ($total > 0 && ($max / $total) >= 0.7) {
                $score = min($score, 55);
            }
        }

        return $score;
    }

    private static function scoreContent(array $doc)
    {
        $summary = isset($doc['summaries']['short']) ? $doc['summaries']['short'] : '';
        $kp = isset($doc['keyphrases']) ? count($doc['keyphrases']) : 0;
        $words = isset($doc['statistics']['word_count']) ? (int) $doc['statistics']['word_count'] : 0;
        $score = 60;
        if (strlen($summary) > 50) {
            $score += 15;
        }
        if ($kp >= 5) {
            $score += 15;
        }

        // Content quality is about the BODY, not the skeleton. If the structure
        // was detected but the sections are empty (table content not read, etc.),
        // a headings-only summary/keyphrase set must not earn a high score.
        $sections = isset($doc['sections']) ? $doc['sections'] : array();
        $sectionCount = count($sections);
        if ($sectionCount >= 3) {
            $empty = 0;
            foreach ($sections as $s) {
                if ((int) (isset($s['word_count']) ? $s['word_count'] : 0) === 0) {
                    $empty++;
                }
            }
            if ($empty / $sectionCount >= 0.9) {
                $score = min($score, 25);
            }
        }
        if ($words < 50) {
            $score = min($score, 25);
        }

        return min(98, $score);
    }

    private static function scoreReferences(array $doc)
    {
        $refs = isset($doc['references']) ? count($doc['references']) : 0;
        if ($refs === 0) {
            return 70;
        }
        return min(98, 70 + min(28, $refs * 2));
    }

    public static function stars($score)
    {
        if ($score >= 95) {
            return 5;
        }
        if ($score >= 85) {
            return 4;
        }
        if ($score >= 70) {
            return 3;
        }
        if ($score >= 50) {
            return 2;
        }
        return 1;
    }

    public static function starString($score)
    {
        $n = self::stars($score);
        return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
    }
}
