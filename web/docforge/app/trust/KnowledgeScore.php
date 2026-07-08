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

        return array(
            'knowledge_score' => min(100, max(0, $overall)),
            'sub_scores' => $subs,
        );
    }

    private static function scoreStructure(array $doc)
    {
        $sections = isset($doc['sections']) ? count($doc['sections']) : 0;
        $blocks = isset($doc['blocks']) ? count($doc['blocks']) : 0;
        if ($blocks === 0) {
            return 30;
        }
        return min(98, 50 + $sections * 8 + min(20, (int) ($blocks / 5)));
    }

    private static function scoreContent(array $doc)
    {
        $summary = isset($doc['summaries']['short']) ? $doc['summaries']['short'] : '';
        $kp = isset($doc['keyphrases']) ? count($doc['keyphrases']) : 0;
        $score = 60;
        if (strlen($summary) > 50) {
            $score += 15;
        }
        if ($kp >= 5) {
            $score += 15;
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
