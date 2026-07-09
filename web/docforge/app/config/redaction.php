<?php
/**
 * Forge Redact configuration (FORGE_REDACT_SPECS.md §5).
 * Override via config.local.php → 'redaction' => [...]
 */
return array(
    'categories' => array(
        'ppsn' => true,
        'iban' => true,
        'card' => true,
        'vat' => true,
        'eircode' => true,
        'email' => true,
        'phone' => true,
        'dob' => true,
        'account' => true,
        'person' => true,
        'address' => true,
    ),
    'tier3' => array(
        'ner' => false,
        'gazetteers' => true,
        'model' => 'Xenova/bert-base-NER',
    ),
    'map' => array(
        'retain' => true,
        'encrypt' => false,
    ),
    'mask_char' => 'XXXXXXXXX',
);
