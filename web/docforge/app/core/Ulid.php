<?php

namespace DocForge\Core;

/**
 * Crockford Base32 ULID generator (PHP 7 compatible).
 */
class Ulid
{
    private static $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate()
    {
        $time = (int) floor(microtime(true) * 1000);
        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $timeChars = self::$encoding[$time % 32] . $timeChars;
            $time = (int) floor($time / 32);
        }
        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::$encoding[random_int(0, 31)];
        }
        return $timeChars . $random;
    }
}
