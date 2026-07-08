<?php

namespace DocForge\Plugins;

class TxtParser extends AbstractParser
{
    public function detect($bytes, $mime)
    {
        return $mime === 'text/plain' || preg_match('//u', $bytes);
    }

    public function extract($filePath)
    {
        $text = file_get_contents($filePath);
        if ($text === false) {
            throw new \RuntimeException('Could not read text file.');
        }
        $blocks = array();
        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $i => $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (preg_match('/^#{1,6}\s+/', $trim)) {
                $level = strlen(ltrim($trim, '#')) - strlen(ltrim(ltrim($trim, '#'), ' '));
                $blocks[] = $this->block('heading', preg_replace('/^#+\s+/', '', $trim), $level, 'line:' . ($i + 1));
            } elseif (strlen($trim) < 80 && !preg_match('/[.!?]$/', $trim) && strtoupper($trim) === $trim) {
                $blocks[] = $this->block('heading', $trim, 2, 'line:' . ($i + 1));
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
