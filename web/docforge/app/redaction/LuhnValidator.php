<?php

namespace DocForge\Redaction;

class LuhnValidator
{
    public static function isValid($digits)
    {
        $digits = preg_replace('/\D/', '', (string) $digits);
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = $len - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }
}
