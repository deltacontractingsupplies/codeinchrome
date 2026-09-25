<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A scheduled job that fails is an incident, alerted once, like a host that is down. */
class ScheduledJobMonitoringTest extends TestCase
{
    /** @var list<string> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.alert_webhook' => 'https://hooks.example.test/alert']);
        Http::fake(['hooks.example.test/*' => function (ClientRequest $r) {
            $this->alerts[] = $r['text'];

            return Http::response('ok');
        }]);
    }

    private function job(string $command, string $cron): ScheduledEvent
    {
        return app(Schedule::class)->command($command)->cron($cron);
    }

    private function runFails(ScheduledEvent $job): void
    {
        $job->exitCode = 1;
        event(new ScheduledTaskFinished($job, 0.5)); // what the scheduler fires first on a non-zero exit
        event(new ScheduledTaskFailed($job, new \Exception("Scheduled command [{$job->command}] failed with exit code [1].")));
    }

    private function runSucceeds(ScheduledEvent $job): void
    {
        $job->exitCode = 0;
        event(new ScheduledTaskFinished($job, 0.5));
    }

    public function test_a_daily_or_hourly_job_alerts_on_its_first_failure_and_on_recovery(): void
    {
        $scan = $this->job('abuse:scan', '10 */6 * * *');

        $this->runFails($scan);
        $this->assertFalse((bool) Monitor::where('key', 'job:abuse:scan')->value('up'));
        $this->assertSame(1, Incident::where('monitor_key', 'job:abuse:scan')->whereNull('resolved_at')->count());
        $this->assertCount(1, $this->alerts);
        $this->assertStringContainsString('DOWN: scheduled job abuse:scan - last run failed', $this->alerts[0]);

        $this->runFails($scan);
        $this->assertCount(1, $this->alerts, 'one alert per incident, not per failed run');

        $this->runSucceeds($scan);
        $this->assertTrue((bool) Monitor::where('key', 'job:abuse:scan')->value('up'));
        $this->assertStringContainsString('RECOVERED: scheduled job abuse:scan', $this->alerts[1]);
    }

    public function test_a_job_every_few_minutes_alerts_only_on_its_third_failure_in_a_row(): void
    {
        $egress = $this->job('abuse:egress', '*/2 * * * *');

        $this->runFails($egress);
        $this->runFails($egress);
        $this->runSucceeds($egress);
        $this->runFails($egress);
        $this->runFails($egress);
        $this->assertSame([], $this->alerts, 'two failures, a success, two failures: a blip');
        $this->runFails($egress);
        $this->assertCount(1, $this->alerts);
        $this->assertStringContainsString('scheduled job abuse:egress', $this->alerts[0]);
    }

    public function test_a_successful_run_is_recorded_with_how_long_it_took(): void
    {
        $this->runSucceeds($this->job('sites:indexing', '5 * * * *'));

        $m = Monitor::where('key', 'job:sites:indexing')->firstOrFail();
        $this->assertTrue((bool) $m->up);
        $this->assertSame('last run succeeded in 0.5 s', $m->detail);
        $this->assertSame([], $this->alerts);
    }
}
