<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every live site scanned for malware and obfuscated PHP (agent sites/scan.go).
 * Uploads and saves are already scanned as they land; this catches what came
 * in some other way (the app's own code writing files, composer, a restore)
 * and anything the signatures learn to recognise later.
 */
class AbuseScan extends Command
{
    protected $signature = 'abuse:scan {site? : one site id; all live sites when left out}';

    protected $description = 'Scan sites for malware and obfuscated PHP; ban the account on a finding';

    public function handle(Enforcer $enforcer): int
    {
        $sites = Site::where('status', 'live')->when($this->argument('site'), fn ($q, $id) => $q->where('site_id', $id))->get();
        $clean = $flagged = $failed = 0;
        foreach ($sites as $site) {
            try {
                $scan = AgentClient::for($site->host)->scanSite($site->site_id);
            } catch (\Throwable $e) {
                $failed++;
                $this->failedScan($site, $e->getMessage());

                continue;
            }
            // Unscannable (a password-protected or oversize archive): nothing
            // vouches for it, but it is not evidence of malware - a person
            // looks (the second security audit, 2026-09-25).
            // Banned for: malware and hidden code. Reviewed: what could not be
            // opened, and what looks like a phishing kit (a Telegram bot can be honest).
            $review = ['unscannable', 'phishing'];
            $findings = array_values(array_filter($scan['findings'], fn ($f) => ! in_array($f['kind'] ?? '', $review, true)));
            $unscannable = array_values(array_filter($scan['findings'], fn ($f) => in_array($f['kind'] ?? '', $review, true)));
            if ($findings !== []) {
                $flagged++;
                $this->error("{$site->site_id}: ".count($findings).' finding(s) - account banned');
                $enforcer->malware($site, $findings, 'scheduled scan');

                continue;
            }
            if ($scan['incomplete'] !== null) {
                // The rules ran and found nothing, but ClamAV did not: never clean.
                $failed++;
                $this->failedScan($site, $scan['incomplete']);

                continue;
            }
            if ($unscannable !== []) {
                $flagged++;
                $this->warn("{$site->site_id}: ".count($unscannable).' file(s) for review');
                $enforcer->review($site, 'files for a person to look at: '
                    .implode(', ', array_map(fn ($f) => $f['path'].' ('.$f['kind'].': '.$f['detail'].')', $unscannable)));

                continue;
            }
            $clean++;
            Cache::forget("abuse.scan_failures.{$site->site_id}");
            $site->forceFill(['scanned_clean_at' => now()])->save();
        }
        $this->info("scanned {$sites->count()} site(s): $clean clean, $flagged flagged, $failed could not be scanned");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Never counted as clean: said, tried again next run, and after two
     * failures in a row the owner is told - a site whose scans keep failing
     * is a site nobody is checking (the audit, 2026-09-25).
     */
    private function failedScan(Site $site, string $why): void
    {
        $this->warn("{$site->site_id}: scan failed - $why");
        Log::warning('abuse scan failed', ['site' => $site->site_id, 'error' => $why]);
        $n = Cache::increment("abuse.scan_failures.{$site->site_id}");
        if ($n === 2) {
            app(Enforcer::class)->review($site, "its malware scan has failed twice in a row ($why)");
        }
    }
}