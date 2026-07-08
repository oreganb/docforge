<?php

namespace DocForge\Core;

/**
 * Magic-byte and MIME validation (FR-2, NFR-2).
 */
class FileValidator
{
    /** @var array<int,array{0:string,1:string,2:string}> */
    private static $signatures = array(
        array('%PDF', 'application/pdf', 'PDF'),
        array("PK\x03\x04", 'application/zip', 'DOCX'), // also xlsx
        array('# ', 'text/markdown', 'MD'),
        array('---', 'text/markdown', 'MD'),
    );

    /**
     * @return array{ok:bool,type?:string,mime?:string,error?:string}
     */
    public static function inspect($path, $originalName, $maxBytes)
    {
        if (!is_file($path)) {
            return array('ok' => false, 'error' => 'Uploaded file could not be read.');
        }
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            return array('ok' => false, 'error' => 'File exceeds the 500 MB limit.');
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $head = file_get_contents($path, false, null, 0, 8192);
        if ($head === false) {
            return array('ok' => false, 'error' => 'Could not read file header.');
        }

        $type = self::detectType($head, $ext);
        if ($type === null) {
            if (self::looksLikeText($head)) {
                $type = ($ext === 'md') ? 'MD' : 'TXT';
            } else {
                return array(
                    'ok' => false,
                    'error' => 'This file type is not supported yet. Try PDF, DOCX, Markdown, or plain text.',
                );
            }
        }

        if ($type === 'DOCX' && in_array($ext, array('xlsx', 'xls', 'csv', 'tsv'), true)) {
            $type = strtoupper($ext === 'xls' ? 'XLSX' : $ext);
        }

        return array(
            'ok' => true,
            'type' => $type,
            'mime' => self::mimeForType($type),
        );
    }

    public static function fingerprint($path)
    {
        return hash_file('sha256', $path);
    }

    private static function detectType($head, $ext)
    {
        if (strncmp($head, '%PDF', 4) === 0) {
            return 'PDF';
        }
        if (strncmp($head, "PK\x03\x04", 4) === 0) {
            if (in_array($ext, array('docx', 'dotx'), true)) {
                return 'DOCX';
            }
            if (in_array($ext, array('xlsx', 'xls'), true)) {
                return 'XLSX';
            }
            return 'DOCX';
        }
        if ($ext === 'md') {
            return 'MD';
        }
        return null;
    }

    private static function looksLikeText($head)
    {
        if ($head === '') {
            return false;
        }
        return preg_match('//u', $head) === 1 && !preg_match('/[\x00-\x08\x0E-\x1F]/', $head);
    }

    private static function mimeForType($type)
    {
        $map = array(
            'PDF' => 'application/pdf',
            'DOCX' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'MD' => 'text/markdown',
            'TXT' => 'text/plain',
            'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        return isset($map[$type]) ? $map[$type] : 'application/octet-stream';
    }
}
