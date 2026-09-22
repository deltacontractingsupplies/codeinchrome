<?php

use Illuminate\Support\Facades\Schedule;

// Driven by one cron line on the control host (see infra/deploy-control.sh).
// withoutOverlapping: a slow or unreachable host must not stack up runs.
Schedule::command('fleet:sync-usage')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fleet:apply-limits')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fleet:monitor')->everyMinute()->withoutOverlapping();
Schedule::command('audit:prune')->dailyAt('04:00');
// Hosts rebuild the base image on Sundays at 03:30 (infra/install-agent.sh); sites move onto it here.
Schedule::command('fleet:roll-image')->dailyAt('04:45')->withoutOverlapping();
