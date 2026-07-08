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
            $this->walk($section->getElements(), $blocks, $full, 'section:' . $si);
        }
        return array(
            'blocks' => $blocks,
            'full_text' => implode("\n", $full),
            'page_count' => max(1, count($phpWord->getSections())),
        );
    }

    /**
     * Recursively walk PhpWord elements, descending into tables. Form-style
     * DOCX files (PDRS, appraisals) hold their body content in table cells;
     * reading only top-level paragraphs recovers headings but loses the body.
     *
     * @param iterable $elements
     * @param array    $blocks
     * @param array    $full
     */
    private function walk($elements, array &$blocks, array &$full, $ref)
    {
        foreach ($elements as $element) {
            // Tables: descend into every cell and keep collecting content.
            if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->walk($cell->getElements(), $blocks, $full, $ref);
                    }
                }
                continue;
            }

            $text = $this->inlineText($element);
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
                $blocks[] = $this->block('heading', $text, $level, $ref);
            } else {
                $blocks[] = $this->block('paragraph', $text, null, $ref);
            }
            $full[] = $text;
        }
    }

    /** Flatten an inline element (Text / TextRun / Title / ListItem) to a string. */
    private function inlineText($element)
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\ListItem
            && method_exists($element, 'getTextObject')) {
            return $this->inlineText($element->getTextObject());
        }
        if (method_exists($element, 'getText')) {
            $t = $element->getText();
            if (is_string($t)) {
                // PhpWord returns XML-escaped text (&amp;, &lt; …); decode it.
                return trim(html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (is_object($t)) {
                return $this->inlineText($t);
            }
        }
        if (method_exists($element, 'getElements')) {
            $parts = array();
            foreach ($element->getElements() as $child) {
                $ct = $this->inlineText($child);
                if ($ct !== '') {
                    $parts[] = $ct;
                }
            }
            return trim(implode('', $parts));
        }
        return '';
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
