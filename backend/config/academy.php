<?php

/** Institution details printed on receipts and certificates. Supply real values via environment; nothing here is invented. */
return [
    'name' => env('ACADEMY_NAME', 'GOSPEL LMS'),
    'legal_name' => env('ACADEMY_LEGAL_NAME'),
    'address' => env('ACADEMY_ADDRESS'),
    'support_email' => env('ACADEMY_SUPPORT_EMAIL'),
    'signatory_name' => env('ACADEMY_SIGNATORY_NAME'),
    'signatory_title' => env('ACADEMY_SIGNATORY_TITLE'),
];
