<?php

namespace DocForge\Redaction;

class TokenRegistry
{
    /** @var string */
    private $mode;
    /** @var string */
    private $maskChar;
    /** @var array<string,int> */
    private $counters = array();
    /** @var array<string,string> normalized => token */
    private $lookup = array();
    /** @var array<string,string> token => surface */
    private $map = array();

    public function __construct($mode, $maskChar)
    {
        $this->mode = $mode;
        $this->maskChar = $maskChar;
    }

    public function replace($category, $surface)
    {
        if ($this->mode === 'mask') {
            return $this->maskChar;
        }
        $label = $this->categoryLabel($category);
        $key = $label . ':' . $this->normalize($surface);
        if (!isset($this->lookup[$key])) {
            $this->counters[$label] = isset($this->counters[$label]) ? $this->counters[$label] + 1 : 1;
            $token = '[' . $label . '-' . $this->counters[$label] . ']';
            $this->lookup[$key] = $token;
            $this->map[$token] = $surface;
        }
        return $this->lookup[$key];
    }

    /** @return array<string,string> */
    public function exportMap()
    {
        return $this->map;
    }

    /** @return array<string,string> surface-normalized => token */
    public function exportReverseMap()
    {
        $rev = array();
        foreach ($this->lookup as $key => $token) {
            $parts = explode(':', $key, 2);
            if (isset($parts[1])) {
                $rev[$parts[1]] = $token;
            }
        }
        return $rev;
    }

    private function categoryLabel($category)
    {
        $map = array(
            'person' => 'PERSON',
            'address' => 'ADDRESS',
            'ppsn' => 'PPSN',
            'iban' => 'IBAN',
            'card' => 'CARD',
            'vat' => 'VAT',
            'eircode' => 'EIRCODE',
            'email' => 'EMAIL',
            'phone' => 'PHONE',
            'dob' => 'DOB',
            'account' => 'ACCOUNT',
        );
        return isset($map[$category]) ? $map[$category] : strtoupper($category);
    }

    private function normalize($surface)
    {
        $s = mb_strtolower(trim((string) $surface));
        return preg_replace('/\s+/u', ' ', $s);
    }
}
