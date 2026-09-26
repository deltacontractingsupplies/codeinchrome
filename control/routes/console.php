<?php

use Illuminate\Support\Facades\Schedule;

// Driven by one cron line on the control host (see infra/deploy-control.sh).
// withoutOverlapping: a slow or unreachable host must not stack up runs.
Schedule::command('fleet:sync-usage')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fleet:apply-limits')->everyFiveMinutes()->withoutOverlapping();
// ->after: the outside heartbeat proves the scheduler runs (App\Fleet\Heartbeat).
Schedule::command('fleet:monitor')->everyMinute()->withoutOverlapping()->after(fn () => \App\Fleet\Heartbeat::ping());
Schedule::command('trials:expire')->everyTenMinutes()->withoutOverlapping();
// After the hosts' automatic reboot window (04:30 h1 .. 05:15 the control host,
// install-agent.sh / deploy-control.sh): a job running while its host reboots
// is skipped, or - for the roll - cut off between removing a container and
// starting its replacement.
Schedule::command('fleet:check-exposure')->dailyAt('05:40')->withoutOverlapping();
// Every live site's newest backup, as the backup server lists it (monitoring alerts on an old one).
Schedule::command('fleet:sync-backups')->hourlyAt(20)->withoutOverlapping();
Schedule::command('explore:refresh')->hourlyAt(35)->withoutOverlapping();
Schedule::command('abuse:scan')->everySixHours(10)->withoutOverlapping();
Schedule::command('abuse:cpu')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('abuse:egress')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('sites:indexing')->hourlyAt(5)->withoutOverlapping();
Schedule::command('sites:idle')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('abuse:links')->hourlyAt(50)->withoutOverlapping();
// Which sites looked up exfiltration or mining endpoints (hosts' DNS forwarders).
Schedule::command('abuse:dns')->hourlyAt(20)->withoutOverlapping();
Schedule::command('audit:prune')->dailyAt('04:00');
// Hosts rebuild the base image on Sundays at 03:30 (infra/install-agent.sh); sites move onto it here.
Schedule::command('fleet:roll-image')->dailyAt('05:30')->withoutOverlapping();
