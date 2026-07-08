<?php

namespace DocForge\Analysis;

class ReferenceModule extends AbstractModule
{
    public function applies(array $ir)
    {
        return !empty($ir['full_text']);
    }

    public function analyse(array $ir)
    {
        $text = $ir['full_text'];
        $refs = array();
        $seen = array();

        if (preg_match_all('/10\.\d{4,}\/[^\s\]]+/i', $text, $dois)) {
            foreach ($dois[0] as $doi) {
                $key = strtolower($doi);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $refs[] = array('raw' => $doi, 'doi' => $doi, 'url' => 'https://doi.org/' . $doi);
            }
        }
        if (preg_match_all('#https?://[^\s\)\]\"\'<>]+#i', $text, $urls)) {
            foreach ($urls[0] as $url) {
                $key = strtolower(rtrim($url, '.,;'));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $refs[] = array('raw' => $url, 'doi' => null, 'url' => rtrim($url, '.,;'));
            }
        }
        if (preg_match_all('/\[[\d,\s\-]+\]/', $text, $numbered)) {
            foreach ($numbered[0] as $raw) {
                $key = $raw;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $refs[] = array('raw' => $raw, 'doi' => null, 'url' => null);
            }
        }

        return array('references' => array_slice($refs, 0, 100));
    }

    protected static function toolName()
    {
        return 'pattern matcher';
    }

    public function confidence()
    {
        return 65;
    }
}
