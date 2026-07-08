<?php

namespace DocForge\Analysis;

class StructureModule extends AbstractModule
{
    public function applies(array $ir)
    {
        return !empty($ir['blocks']);
    }

    public function analyse(array $ir)
    {
        $sections = array();
        $current = null;
        foreach ($ir['blocks'] as $block) {
            if ($block['type'] === 'heading') {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = array(
                    'title' => $block['text'],
                    'level' => isset($block['level']) ? $block['level'] : 2,
                    'word_count' => 0,
                );
            } elseif ($current !== null && ($block['type'] === 'paragraph' || $block['type'] === 'list')) {
                $current['word_count'] += str_word_count($block['text']);
            } elseif ($current !== null && $block['type'] === 'table' && !empty($block['rows'])) {
                // Table cells hold body content (form-style DOCX); count them so
                // section word counts stay honest (corpus #002 / #004 guard).
                foreach ($block['rows'] as $row) {
                    foreach ($row as $cell) {
                        $current['word_count'] += str_word_count((string) $cell);
                    }
                }
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }
        return array('sections' => $sections);
    }

    protected static function toolName()
    {
        return 'heading tree';
    }

    public function confidence()
    {
        return 88;
    }
}
