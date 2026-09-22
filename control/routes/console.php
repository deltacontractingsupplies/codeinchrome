<?php

use Illuminate\Support\Facades\Schedule;

// Driven by one cron line on the control host (see infra/deploy-control.sh).
// withoutOverlapping: a slow or unreachable host must not stack up runs.
Schedule::command('fleet:sync-usage')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fleet:apply-limits')->everyFiveMinutes()->withoutOverlapping();
