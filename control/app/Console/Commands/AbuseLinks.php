<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Abuse\LinkScanner;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Every live site's pages read from outside (App\Abuse\LinkScanner): a link
 * to a program download bans the account; anything that needs a person's
 * judgement is emailed to the owner (once per distinct set of findings).
 */
class AbuseLinks extends Command
{
    protected $signature = 'abuse:links {site? : one site id; all live sites when left out}';

    protected $description = 'Check what every site\'s pages link to; ban on program downloads, report the rest';

    public function handle(LinkScanner $scanner, Enforcer $enforcer): int
    {
        $sites = Site::where('status', 'live')->when($this->argument('site'), fn ($q, $id) => $q->where('site_id', $id))->get();
        foreach ($sites as $site) {
            $r = $scanner->scan($site);
            if ($r['pages'] === 0) {
                // Not answering is not "clean": said, and left unlisted.
                $site->forceFill(['links_clean_at' => null])->save();
                $this->warn("{$site->site_id}: no page could be read");

                continue;
            }
            if ($r['ban']) {
                $this->error("{$site->site_id}: program downloads linked - account banned");
                $enforcer->ban($site->user, "Links to program downloads on {$site->domain}:\n".implode("\n", array_slice($r['ban'], 0, 20)));
                $site->forceFill(['links_clean_at' => null])->save();

                continue;
            }
            if ($r['review']) {
                $site->forceFill(['links_clean_at' => null])->save();
                $this->warn("{$site->site_id}: ".count($r['review']).' to review');
                $this->tellOwnerOnce($site, $r['review']);

                continue;
            }
            $site->forceFill(['links_clean_at' => now()])->save();
            $this->line("{$site->site_id}: clean ({$r['pages']} page(s))");
        }

        return self::SUCCESS;
    }

    private function tellOwnerOnce(Site $site, array $review): void
    {
        $key = 'abuse.links.told.'.$site->site_id;
        $digest = sha1(implode("\n", $review));
        if (Cache::get($key) === $digest) {
            return;
        }
        $to = config('fleet.owner_notify_email') ?: config('fleet.admin_emails');
        if (! $to || ! config('fleet.mail_enabled')) {
            return;
        }
        $body = "https://{$site->domain} (account ".($site->user?->email ?? '?').") has links to review. It is kept off Explore until they are gone.\n\n"
            .implode("\n", array_slice($review, 0, 30))
            ."\n\nIf it is abuse: php artisan abuse:ban ".($site->user?->email ?? '<email>').' --reason="..."';
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject("[codeinchrome] Links to review: {$site->domain}"));
            Cache::put($key, $digest, now()->addDays(30));
        } catch (\Throwable $e) {
            Log::error('link review email failed', ['site' => $site->site_id, 'error' => $e->getMessage()]);
        }
    }
}
