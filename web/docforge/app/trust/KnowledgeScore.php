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
        $structure = self::scoreStructure($doc);
        $content = self::scoreContent($doc);
        $refs = self::scoreReferences($doc);
        $tables = 70; // Phase 1: no table extraction yet

        $overall = (int) round(
            $structure * 0.25 +
            $content * 0.35 +
            $refs * 0.20 +
            $tables * 0.20
        );

        return array(
            'knowledge_score' => min(100, max(0, $overall)),
            'sub_scores' => array(
                'structure' => $structure,
                'content' => $content,
                'references' => $refs,
                'tables' => $tables,
            ),
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
