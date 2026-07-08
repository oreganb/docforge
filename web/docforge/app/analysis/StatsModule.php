<?php

namespace DocForge\Analysis;

use DaveChild\TextStatistics\TextStatistics;
use LanguageDetection\Language;

class StatsModule extends AbstractModule
{
    public function applies(array $ir)
    {
        return !empty($ir['full_text']);
    }

    public function analyse(array $ir)
    {
        $text = $ir['full_text'];
        $wordCount = str_word_count($text);
        $readingMinutes = max(1, (int) ceil($wordCount / 200));

        $flesch = 0.0;
        try {
            $stats = new TextStatistics();
            $flesch = round($stats->fleschKincaidReadingEase($text), 1);
        } catch (\Throwable $e) {
            $flesch = 0.0;
        }

        $language = $this->detectLanguage($text);

        return array(
            'statistics' => array(
                'word_count' => $wordCount,
                'character_count' => strlen($text),
                'sentence_count' => max(1, preg_match_all('/[.!?]+/', $text)),
                'reading_time_minutes' => $readingMinutes,
                'flesch_reading_ease' => $flesch,
                'language' => $language,
            ),
            'language' => $language,
        );
    }

    private function detectLanguage($text)
    {
        try {
            // Cap the sample: language detection is O(text) and unnecessary on full docs.
            $sample = strlen($text) > 2000 ? substr($text, 0, 2000) : $text;
            $ld = new Language();
            $best = $ld->detect($sample)->bestResults()->close();
            if (!empty($best)) {
                $keys = array_keys($best);
                return (string) $keys[0];
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'en';
    }

    protected static function toolName()
    {
        return 'Text-Statistics';
    }

    public function confidence()
    {
        return 95;
    }
}
