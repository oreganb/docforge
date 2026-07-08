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
