<?php

namespace DocForge\Plugins;

use League\CommonMark\CommonMarkConverter;

class MdParser extends AbstractParser
{
    public function detect($bytes, $mime)
    {
        return $mime === 'text/markdown' || strpos($bytes, '#') !== false;
    }

    public function extract($filePath)
    {
        $text = file_get_contents($filePath);
        if ($text === false) {
            throw new \RuntimeException('Could not read Markdown file.');
        }
        $blocks = array();
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $i => $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+)/', $trim, $m)) {
                $blocks[] = $this->block('heading', $m[2], strlen($m[1]), 'line:' . ($i + 1));
            } elseif (preg_match('/^[-*]\s+/', $trim)) {
                $blocks[] = $this->block('paragraph', preg_replace('/^[-*]\s+/', '', $trim), null, 'line:' . ($i + 1));
            } else {
                $blocks[] = $this->block('paragraph', $trim, null, 'line:' . ($i + 1));
            }
        }
        return array(
            'blocks' => $blocks,
            'full_text' => $text,
            'page_count' => 1,
        );
    }

    public function metadata($filePath)
    {
        return array('pages' => 1);
    }
}
