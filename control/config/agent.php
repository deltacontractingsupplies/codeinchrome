<?php

/*
 * The AI agent customers bring: Claude in Chrome. Anthropic's product, sold by
 * Anthropic - codeinchrome sells the hosting and the editor, and must say so
 * plainly. Every fact below was read from Anthropic's own pages on the date
 * given; re-check them there before changing any (they change).
 */
return [
    'checked_on' => '2026-09-24',

    'extension' => [
        'name' => 'Claude in Chrome',
        'page' => 'https://claude.com/claude-in-chrome',
        // The Chrome Web Store listing the help centre links to.
        'install' => 'https://chromewebstore.google.com/detail/claude/fcoeoabgfenejglbffodgkkbkcdhcgfn',
        'help' => 'https://support.claude.com/en/articles/12012173-get-started-with-claude-in-chrome',
        // "Claude in Chrome is not supported on other Chromium-based web browsers or mobile devices."
        'browser' => 'Google Chrome on a computer (not other Chromium browsers, not phones)',
    ],

    // "Claude in Chrome is available for all paid plans (Pro, Max, Team, and
    // Enterprise)" - not the free plan. Prices: claude.com/pricing and the Max
    // plan help article (web subscriptions; mobile may differ).
    'plans_url' => 'https://claude.com/pricing',
    'plans' => [
        ['name' => 'Pro', 'price' => '$20 a month', 'note' => '$17 a month billed yearly'],
        ['name' => 'Max 5x', 'price' => '$100 a month', 'note' => 'five times the use of Pro'],
        ['name' => 'Max 20x', 'price' => '$200 a month', 'note' => 'twenty times the use of Pro'],
    ],
    'from' => '$20',
    'to' => '$200',
];
