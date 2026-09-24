<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;
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
                $findings = AgentClient::for($site->host)->scanSite($site->site_id);
            } catch (\Throwable $e) {
                // Never counted as clean: said, and tried again next run.
                $failed++;
                $this->warn("{$site->site_id}: scan failed - ".$e->getMessage());
                Log::warning('abuse scan failed', ['site' => $site->site_id, 'error' => $e->getMessage()]);

                continue;
            }
            if ($findings === []) {
                $clean++;
                $site->forceFill(['scanned_clean_at' => now()])->save();

                continue;
            }
            $flagged++;
            $this->error("{$site->site_id}: ".count($findings).' finding(s) - account banned');
            $enforcer->malware($site, $findings, 'scheduled scan');
        }
        $this->info("scanned {$sites->count()} site(s): $clean clean, $flagged flagged, $failed could not be scanned");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
