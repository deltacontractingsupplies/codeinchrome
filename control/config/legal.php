<?php

/*
 * Who the customer is dealing with, as shown on the public legal pages.
 *
 * Set from the operator's .env. The pages never invent an identity: a value
 * that is not set is left out of the page rather than filled with a guess, so
 * a missing legal name or jurisdiction is visible, not papered over.
 */
return [
    // The legal person or company that operates the service.
    'operator' => env('CIC_LEGAL_NAME'),
    // Country whose law governs the terms, e.g. "Finland".
    'jurisdiction' => env('CIC_LEGAL_JURISDICTION'),
    // Where customers write to. Must actually receive mail.
    'support_email' => env('CIC_SUPPORT_EMAIL', 'support@codeinchrome.com'),
    // Money back on the first payment of a plan, within this many days.
    'refund_days' => (int) env('CIC_REFUND_DAYS', 14),
    // The public source code: linked from every public page, and the security
    // policy that security.txt points to lives there (SECURITY.md).
    'source' => [
        'url' => 'https://github.com/deltacontractingsupplies/codeinchrome',
        'license' => 'FSL-1.1-ALv2',
    ],
    // Shown on every legal page.
    'updated' => '2026-09-24',
];
