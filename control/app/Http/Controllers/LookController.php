<?php

namespace App\Http\Controllers;

use App\Fleet\AgentClient;
use App\Audit\Audit;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A page of the site as one of ITS users sees it, in a real browser tab - so
 * an agent (or the person) can look at a page behind the app's own login,
 * which a screenshot of the live site cannot reach (found by a simulated
 * agent: "look at one page yourself" was impossible past a login).
 *
 * The page is fetched here, on the server, signed in as the site's user by
 * the same one-off session cookie cic.request uses - the cookie never reaches
 * the browser. It is served with CSP `sandbox` and no allow-* at all: the
 * site's HTML gets an opaque origin, runs no script, submits no form, and can
 * load images, styles and fonts from the site's own address only. It is
 * shown, not run.
 *
 * The address holds an unguessable token (a v4 UUID) kept for ten minutes and
 * bound to the site and the owner, so a link planted elsewhere cannot make the
 * platform fetch pages as a site's user. A token in the path, not a signed
 * query string: browser tools refuse to print an address with a query string,
 * and an agent that cannot read the address cannot open it.
 */
class LookController extends Controller
{
    private const TTL_MINUTES = 10;

    private const MAX_REDIRECTS = 5;

    public function create(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048', 'starts_with:/'],
            'as' => ['nullable', 'integer', 'min:1', 'prohibits:cookie'],
            // Or the session of a login the agent did itself through the app's
            // own form (cic.request) - for apps with no Laravel users. One
            // header value: no line breaks, so it cannot add headers.
            'cookie' => ['nullable', 'string', 'max:8192', 'not_regex:/[\r\n]/'],
        ]);

        $token = (string) Str::uuid();
        Cache::put("look:$token", ['site' => $site->id, 'owner' => $request->user()->id, 'path' => $data['path'],
            'as' => isset($data['as']) ? (int) $data['as'] : null, // A site session: kept encrypted, for these ten minutes only.
            'cookie' => isset($data['cookie']) ? Crypt::encryptString($data['cookie']) : null], now()->addMinutes(self::TTL_MINUTES));

        return response()->json(['ok' => true, 'url' => route('sites.look.show', [$site, $token]), 'expires_in_minutes' => self::TTL_MINUTES]);
    }

    public function show(Request $request, Site $site, string $token): Response
    {
        $this->authorizeSite($request, $site);
        $look = Cache::get("look:$token");
        // Unknown, expired, another site's - or issued to someone else, should
        // the site have changed hands since.
        abort_unless(is_array($look) && $look['site'] === $site->id && $look['owner'] === $request->user()->id, 403);
        try {
            $cookie = isset($look['cookie']) ? Crypt::decryptString($look['cookie']) : null;

            return $this->fetch($site, $look['path'], $look['as'], $cookie);
        } catch (\App\Fleet\AgentUnreachable|\App\Fleet\AgentRefused $e) {
            // The site's host is restarting or said no: a page that says so, never a 500.
            return $this->page($site, $look['path'], null, 502,
                '<p>The site could not be asked just now: '.e($e->getMessage()).' Try again in a minute.</p>');
        }
    }

    private function fetch(Site $site, string $path, ?int $as, ?string $cookie = null): Response
    {
        abort_unless(str_starts_with($path, '/') && ! str_starts_with($path, '//'), 404);

        $agent = AgentClient::for($site->host);
        $headers = ['accept' => 'text/html'];
        if ($as) {
            Audit::record('site.test_login', site: $site, detail: ['user' => $as, 'look' => true]);
            $login = $agent->loginCookie($site->site_id, $as);
            $headers['cookie'] = $login['name'].'='.rawurlencode($login['value']);
        } elseif ($cookie !== null) {
            $headers['cookie'] = $cookie;
        }

        // Redirects inside the site are followed (a login redirect is what a
        // person would see); one that leaves the site is shown, not followed.
        for ($hop = 0; ; $hop++) {
            $answer = $agent->siteRequest($site->site_id, ['method' => 'GET', 'path' => $path, 'headers' => (object) $headers, 'body' => '']);
            $status = (int) ($answer['status'] ?? 0);
            $location = $answer['headers']['location'] ?? $answer['headers']['Location'] ?? null;
            if ($status < 300 || $status >= 400 || ! $location || $hop >= self::MAX_REDIRECTS) {
                break;
            }
            $next = parse_url($location);
            $sameSite = ! isset($next['host']) || strcasecmp($next['host'], $site->domain) === 0;
            if (! $sameSite) {
                return $this->page($site, $path, $as, $status, '<p>This page redirects away from the site, to '.e($location).'.</p>');
            }
            $path = ($next['path'] ?? '/').(isset($next['query']) ? '?'.$next['query'] : '');
        }

        $type = strtolower($answer['headers']['content-type'] ?? $answer['headers']['Content-Type'] ?? '');
        if (! str_contains($type, 'html')) {
            return $this->page($site, $path, $as, 415, '<p>This address answers '.e($type ?: 'no content type').', not a page.</p>');
        }

        return $this->page($site, $path, $as, $status, (string) ($answer['body'] ?? ''), true);
    }

    /** 404, not 403: a 403 would confirm the site exists and is someone else's. */
    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live.');
    }

    private function page(Site $site, string $path, ?int $as, int $status, string $html, bool $sitePage = false): Response
    {
        $origin = 'https://'.$site->domain;
        $who = $as ? "the site's user $as" : 'a visitor';
        $banner = '<div style="position:sticky;top:0;z-index:2147483647;background:#0078d4;color:#fff;'
            .'font:13px/1.4 system-ui,sans-serif;padding:6px 12px">codeinchrome preview of '.e($path).' as '.$who
            .' (HTTP '.$status.') - shown, not run: scripts and forms are off here.</div>';
        // The page's own relative links, styles and images resolve against the
        // site; the first <base> in a document wins, so ours goes first.
        $base = '<base href="'.e($origin.$path).'">';

        // Callbacks, not '$0'.$text: a path holding "$1" or "\\" would be read
        // as a back-reference.
        if ($sitePage && preg_match('/<head\b[^>]*>/i', $html)) {
            $html = preg_replace_callback('/<head\b[^>]*>/i', fn ($m) => $m[0].$base, $html, 1);
        } else {
            $html = $base.$html;
        }
        $html = preg_match('/<body\b[^>]*>/i', $html)
            ? preg_replace_callback('/<body\b[^>]*>/i', fn ($m) => $m[0].$banner, $html, 1)
            : $banner.$html;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => implode('; ', [
                'sandbox',
                "default-src 'none'",
                "img-src $origin data:",
                "style-src $origin 'unsafe-inline'",
                "font-src $origin data:",
                "media-src $origin",
                "base-uri $origin",
                "form-action 'none'",
                "frame-ancestors 'none'",
            ]),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
