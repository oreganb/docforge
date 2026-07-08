<?php

namespace DocForge\Plugins;

use PhpOffice\PhpWord\IOFactory;

class DocxParser extends AbstractParser
{
    public function detect($bytes, $mime)
    {
        return strncmp($bytes, "PK\x03\x04", 4) === 0
            && (strpos($mime, 'word') !== false || strpos($mime, 'document') !== false);
    }

    public function extract($filePath)
    {
        $phpWord = IOFactory::load($filePath);
        $blocks = array();
        $full = array();
        foreach ($phpWord->getSections() as $si => $section) {
            foreach ($section->getElements() as $ei => $element) {
                $text = method_exists($element, 'getText') ? trim($element->getText()) : '';
                if ($text === '') {
                    continue;
                }
                $style = method_exists($element, 'getStyle') ? $element->getStyle() : '';
                $isHeading = is_string($style) && stripos($style, 'heading') !== false;
                if ($isHeading) {
                    $level = 1;
                    if (preg_match('/heading\s*(\d)/i', $style, $m)) {
                        $level = (int) $m[1];
                    }
                    $blocks[] = $this->block('heading', $text, $level, 'section:' . $si);
                } else {
                    $blocks[] = $this->block('paragraph', $text, null, 'section:' . $si);
                }
                $full[] = $text;
            }
        }
        return array(
            'blocks' => $blocks,
            'full_text' => implode("\n\n", $full),
            'page_count' => max(1, count($phpWord->getSections())),
        );
    }

    public function metadata($filePath)
    {
        return array('pages' => 1);
    }

    public function confidence()
    {
        return array('overall' => 90);
    }
}
