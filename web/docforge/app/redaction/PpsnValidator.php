<?php

namespace DocForge\Redaction;

/**
 * Irish PPSN checksum validation (modulus 23).
 * Seven digits weighted 8..2; check letter maps remainder 0→W, 1→A … 22→V.
 */
class PpsnValidator
{
    /** @var string */
    private static $checkChars = 'WABCDEFGHIJKLMNOPQRSTUV';

    public static function isValid($value)
    {
        $s = strtoupper(preg_replace('/\s+/', '', (string) $value));
        if (!preg_match('/^(\d{7})([A-W])([AHWTX])?$/', $s, $m)) {
            return false;
        }
        $weights = array(8, 7, 6, 5, 4, 3, 2);
        $sum = 0;
        $digits = str_split($m[1]);
        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $digits[$i] * $weights[$i];
        }
        $expected = self::$checkChars[$sum % 23];
        if ($m[2] !== $expected) {
            return false;
        }
        if (!empty($m[3])) {
            $sum += 9 * (strpos('AWTXH', $m[3]) !== false ? 1 : 0);
            // Spouse suffix letter — must be one of A H W T X; already matched.
        }
        return true;
    }
}
