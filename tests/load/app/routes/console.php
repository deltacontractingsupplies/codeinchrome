<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;

// The WebSocket capacity test's publisher (tests/load/ws.sh): one "tick" a
// second on the public channel "bench", through the site's own Reverb, each
// carrying the time it was sent so every listener can measure how late it is.
Artisan::command('bench:tick {seconds=60}', function (int $seconds) {
    $end = microtime(true) + $seconds;
    $sent = 0;
    while (microtime(true) < $end) {
        $next = microtime(true) + 1;
        Broadcast::on('bench')->as('tick')->with(['t' => (int) round(microtime(true) * 1000)])->sendNow();
        $sent++;
        usleep((int) max(0, ($next - microtime(true)) * 1e6));
    }
    $this->line("sent $sent");
});
