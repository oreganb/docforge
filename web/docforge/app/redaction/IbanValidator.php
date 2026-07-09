<?php

namespace DocForge\Redaction;

class IbanValidator
{
    public static function isValid($iban)
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        $len = strlen($rearranged);
        for ($i = 0; $i < $len; $i++) {
            $c = $rearranged[$i];
            $numeric .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }
        $mod = 0;
        $nlen = strlen($numeric);
        for ($i = 0; $i < $nlen; $i++) {
            $mod = ($mod * 10 + (int) $numeric[$i]) % 97;
        }
        return $mod === 1;
    }
}
