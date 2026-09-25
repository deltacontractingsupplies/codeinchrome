<?php

/*
 * Who may create an account (owner's decision, 2026-09-25, once the code was
 * public and free sites were listed on Explore).
 *
 * Sign in with Google or Apple is always open: the provider has already
 * verified the person and their address. Sign-up with an email and password
 * is open only for addresses at the providers listed here - a mailbox at a
 * large provider is a real person's far more often than a throwaway domain,
 * which is where spam and phishing sign-ups come from. Existing accounts are
 * never affected, whatever their address: this is checked at sign-up only.
 */
return [
    'email_domains' => array_values(array_filter(array_map(
        fn ($d) => strtolower(trim($d)),
        explode(',', (string) env('CIC_SIGNUP_EMAIL_DOMAINS', 'gmail.com,googlemail.com'))
    ))),

    // The e2e suite's reserved addresses (@codeinchrome.test). Accepted only
    // where this is set. They can never receive mail, so such an account
    // stays unverified - able to do nothing - unless an operator marks it
    // verified from the server (tests/e2e/helpers/fixtures.js markVerified).
    'test_domain' => env('CIC_SIGNUP_TEST_DOMAIN'),
    // ...accepted only with this secret's daily HMAC in X-CIC-E2E (App\Auth\TestSuite).
    'test_secret' => env('CIC_SIGNUP_TEST_SECRET'),
];
