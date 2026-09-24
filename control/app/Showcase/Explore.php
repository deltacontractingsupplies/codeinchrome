<?php

namespace App\Showcase;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Explore: every FREE site that is live and has been built, listed publicly
 * by its address and nothing else - no owner, no email, no credentials, no
 * content (owner's decision, 2026-09-24). Free sites are listed with no opt
 * out, and the person is told so before creating one (the create form,
 * pricing, terms, privacy). Paid sites and the highlighted demos are not in
 * this list.
 *
 * Whether a site answers with a page of its own is found by explore:refresh
 * (hourly), never while a visitor waits. The list is filtered again on every
 * read, so a site deleted, suspended or moved to a paid plan leaves it at once.
 */
class Explore
{
    public const CACHE_KEY = 'explore.built';

    public function candidates(): Builder
    {
        $demoSites = collect(config('showcase.demos'))->pluck('site')->filter()->values()->all();

        return Site::query()
            ->where('status', 'live')
            ->whereNotIn('site_id', $demoSites)
            ->whereHas('user', function (Builder $q) {
                $q->where('plan', 'free');
                // The platform's own test accounts (the e2e suite's throwaway sites).
                foreach (config('showcase.explore.exclude_email_suffixes', []) as $suffix) {
                    $q->where('email', 'not like', '%'.$suffix);
                }
            });
    }

    /** @return list<string> the listed sites' domains, newest first */
    public function listed(?int $limit = null): array
    {
        $built = Cache::get(self::CACHE_KEY, []);
        if (! is_array($built) || $built === []) {
            return [];
        }

        return $this->candidates()
            ->whereIn('site_id', array_keys($built))
            ->latest()
            ->when($limit, fn (Builder $q) => $q->limit($limit))
            ->pluck('domain')
            ->all();
    }

    /** @return array<string, int> site id => when it was last seen built */
    public function refresh(): array
    {
        $built = [];
        foreach ($this->candidates()->limit(1000)->get() as $site) {
            if ($this->isBuilt($site)) {
                $built[$site->site_id] = now()->getTimestamp();
            }
        }
        Cache::forever(self::CACHE_KEY, $built);

        return $built;
    }

    /**
     * Answers 200 over HTTPS with a page that is not Laravel's untouched
     * start page (a new site is seeded with one; an empty site is not worth
     * a visitor's click).
     */
    private function isBuilt(Site $site): bool
    {
        try {
            $res = Http::timeout(8)->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'codeinchrome-explore (+https://codeinchrome.com)'])
                ->get('https://'.$site->domain.'/');
        } catch (\Throwable) {
            return false;
        }
        if ($res->status() !== 200) {
            return false;
        }
        $body = $res->body();

        return ! (str_contains($body, "Let's get started") && str_contains($body, 'laravel.com/docs'));
    }
}
