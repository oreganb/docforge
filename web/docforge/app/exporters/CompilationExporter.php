<?php

namespace DocForge\Exporters;

/**
 * Forge Merge — multi-document compilation exporter.
 *
 * Operates ABOVE the Knowledge Layer: it never re-parses or re-analyses. It
 * takes already-forged reports (their stored `$doc` structures) and stitches
 * them into one trusted, token-efficient Markdown pack for an LLM:
 *
 *   - a compilation frontmatter (its own fingerprint + every constituent SHA-256),
 *   - a cross-document head (per-document Knowledge Scores, union key phrases,
 *     de-duplicated combined reference list),
 *   - each document's full report as a section, with its own verdict and
 *     provenance intact and its section numbers prefixed by document index so
 *     the stable-ID convention survives (doc 2's "4. Quality Verdict" → "2.4").
 *
 * The `context` profile produces the minimal pack — per-document frontmatter,
 * verdict and full text only — for maximum token efficiency.
 */
class CompilationExporter
{
    /**
     * @param array<string,mixed> $compilation {
     *   fingerprint: string,
     *   generated_at: string,
     *   profile: 'full'|'context',
     *   documents: array<int,array{index:int,title:string,sha256:string,type:string,
     *                              knowledge_score:int,source_name:string,doc:array}>,
     *   keyphrases: array<int,array{phrase:string,score:float}>,
     *   references: array<int,array{raw:string,doi:?string,url:?string}>
     * }
     */
    public function export(array $compilation)
    {
        $profile = isset($compilation['profile']) && $compilation['profile'] === 'context'
            ? 'context' : 'full';
        $documents = isset($compilation['documents']) ? $compilation['documents'] : array();

        $lines = $this->frontmatter($compilation, $profile, $documents);
        $lines[] = '# DocForge Compilation';
        $lines[] = '';
        $lines[] = '_A merge of ' . count($documents) . ' forged report(s). This is a compilation, '
            . 'not a source document — each constituent keeps its own fingerprint, verdict and provenance._';
        $lines[] = '';

        // Constituents + per-document Knowledge Scores.
        $lines[] = '## Constituent Documents';
        $lines[] = '';
        $lines[] = '| # | Title | Type | Knowledge Score | SHA-256 |';
        $lines[] = '|---|---|---|---:|---|';
        foreach ($documents as $d) {
            $lines[] = '| ' . $d['index'] . ' | ' . $this->cell($d['title']) . ' | '
                . $this->cell($d['type']) . ' | ' . (int) $d['knowledge_score'] . '% | `'
                . $this->cell($d['sha256']) . '` |';
        }
        $lines[] = '';

        // Cross-document head — union key phrases.
        if (!empty($compilation['keyphrases'])) {
            $lines[] = '## Combined Key Phrases';
            $lines[] = '';
            $lines[] = '_Union across all constituents (aggregate weight)._';
            $lines[] = '';
            foreach ($compilation['keyphrases'] as $kp) {
                $lines[] = '- **' . $kp['phrase'] . '** (' . round((float) $kp['score'], 4) . ')';
            }
            $lines[] = '';
        }

        // Cross-document head — de-duplicated references.
        if (!empty($compilation['references'])) {
            $lines[] = '## Combined References';
            $lines[] = '';
            $lines[] = '_De-duplicated by DOI / URL across all constituents._';
            $lines[] = '';
            foreach ($compilation['references'] as $ref) {
                $line = '- ' . $ref['raw'];
                if (!empty($ref['doi'])) {
                    $line .= ' — DOI: ' . $ref['doi'];
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // Each document as a section.
        $md = new MarkdownExporter();
        foreach ($documents as $d) {
            $lines[] = '---';
            $lines[] = '';
            $lines[] = '# Document ' . $d['index'] . ' — ' . $d['title'];
            $lines[] = '';
            $body = isset($d['doc']) && is_array($d['doc']) ? $md->export($d['doc']) : '';
            $sections = $this->splitSections($this->stripFrontmatter($body));
            foreach ($sections as $sec) {
                if ($profile === 'context' && !$this->keepInContext($sec['title'])) {
                    continue;
                }
                $lines[] = $this->renumberHeading($sec['header'], $d['index']);
                foreach ($sec['body'] as $bl) {
                    $lines[] = $bl;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $compilation
     * @param array<int,array<string,mixed>> $documents
     * @return array<int,string>
     */
    private function frontmatter(array $compilation, $profile, array $documents)
    {
        $constituents = array();
        foreach ($documents as $d) {
            $constituents[] = (string) $d['sha256'];
        }
        $lines = array();
        $lines[] = '---';
        $lines[] = 'doc_class: compilation';
        $lines[] = 'profile: ' . $profile;
        $lines[] = 'document_count: ' . count($documents);
        $lines[] = 'fingerprint: ' . $this->yaml(isset($compilation['fingerprint']) ? $compilation['fingerprint'] : '');
        $lines[] = 'constituents:';
        foreach ($constituents as $sha) {
            $lines[] = '  - ' . $this->yaml($sha);
        }
        $lines[] = 'generated_at: ' . $this->yaml(isset($compilation['generated_at']) ? $compilation['generated_at'] : gmdate('c'));
        $lines[] = '---';
        $lines[] = '';
        return $lines;
    }

    /** Sections kept in the compact "context" profile. */
    private function keepInContext($title)
    {
        return stripos($title, 'Quality Verdict') !== false
            || stripos($title, 'Full Extracted Text') !== false;
    }

    /** Drop a leading YAML frontmatter block from a rendered report. */
    private function stripFrontmatter($md)
    {
        return preg_replace('/\A---\R.*?\R---\R?/s', '', (string) $md);
    }

    /**
     * Split a rendered report into its level-2 (`## N. Title`) sections. Content
     * before the first `## ` (the `# title` line) is dropped — the compilation
     * supplies its own "# Document N —" header.
     *
     * @return array<int,array{title:string,header:string,body:array<int,string>}>
     */
    private function splitSections($md)
    {
        $lines = preg_split('/\R/', (string) $md);
        $sections = array();
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = array('title' => trim($m[1]), 'header' => $line, 'body' => array());
            } elseif ($current !== null) {
                $current['body'][] = $line;
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }
        return $sections;
    }

    /**
     * Prefix a section heading's number with the document index so numbering
     * stays stable and unambiguous across the merge: doc 2's "## 4. Quality
     * Verdict" becomes "## 2.4 Quality Verdict".
     */
    private function renumberHeading($header, $index)
    {
        return preg_replace_callback(
            '/^(#{2,6})\s+(\d+)\.\s+(.*)$/',
            function ($m) use ($index) {
                return $m[1] . ' ' . $index . '.' . $m[2] . ' ' . $m[3];
            },
            $header
        );
    }

    /** Quote a scalar for YAML (mirrors MarkdownExporter::yaml). */
    private function yaml($value)
    {
        $value = (string) $value;
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = preg_replace('/\s*\R\s*/u', ' ', $value);
        return '"' . $value . '"';
    }

    /** Make a string safe for a single GFM table cell. */
    private function cell($s)
    {
        $s = (string) $s;
        $s = preg_replace('/\s*\R\s*/u', ' ', $s);
        $s = str_replace('|', '\\|', $s);
        return trim($s);
    }
}
