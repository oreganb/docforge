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
            'meta_title' => $this->readMetaTitle($phpWord),
        );
    }

    /** Read the embedded document title from DOCX core properties, if set. */
    private function readMetaTitle($phpWord)
    {
        try {
            if (method_exists($phpWord, 'getDocInfo')) {
                $title = $phpWord->getDocInfo()->getTitle();
                return trim((string) $title);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return '';
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
                $rows = $element->getRows();
                $maxCols = 0;
                foreach ($rows as $row) {
                    $maxCols = max($maxCols, count($row->getCells()));
                }
                // Multi-column grids (e.g. competency maps) carry meaning in the
                // row/column relationship, which flattening destroys. Mark the
                // site so the honesty stays local rather than presenting a
                // matrix as an unlabelled vertical run of lines.
                if ($maxCols >= 3) {
                    $marker = '[table content, structure not preserved in this phase]';
                    $blocks[] = $this->block('note', $marker, null, $ref);
                    $full[] = $marker;
                }
                foreach ($rows as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->walk($cell->getElements(), $blocks, $full, $ref);
                    }
                }
                continue;
            }

            $text = $this->normalizeSpacing($this->inlineText($element));
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

    /**
     * Repair run-in concatenation. Adjacent runs/cells are joined without a
     * separator, so "Position:" + "Senior…" becomes "Position:Senior…". Insert
     * a space after a label colon (but not inside times like 14:30) and around
     * a pipe that sits hard against text.
     */
    private function normalizeSpacing($text)
    {
        if ($text === '') {
            return '';
        }
        // Space after a colon unless it is a digit:digit time (e.g. 14:30).
        $text = preg_replace_callback('/(.):(?=(\S))/u', function ($m) {
            $before = $m[1];
            $after = $m[2];
            if (ctype_digit($before) && ctype_digit($after)) {
                return $before . ':';
            }
            return $before . ': ';
        }, $text);
        // Sentence-mark run-in from joined runs ("delivery.Continued",
        // "issues).Builds"). Only split when a lowercase letter or a closing
        // bracket precedes the mark and an uppercase letter follows, so
        // decimals (3.5), acronyms (U.S.A) and "No.5" tokens are left intact.
        $text = preg_replace('/(?<=[a-z)\]])([.!?])(?=[A-Z])/u', '$1 ', $text);
        // Normalise pipe separators to " | " so cell joins stay legible.
        $text = preg_replace('/\s*\|\s*/u', ' | ', $text);
        // Collapse any doubled spaces introduced above.
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text);
        return trim($text);
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
