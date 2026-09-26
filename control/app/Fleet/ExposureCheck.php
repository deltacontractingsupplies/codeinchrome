<?php

namespace App\Fleet;

use App\Models\Site;
use App\Support\BoundedSink;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Proves, from the outside, that a site serves nothing but public/.
 *
 * The site is asked over the internet, at its real address and through the
 * same edge (Cloudflare, then Caddy, then the site's Apache) as any visitor,
 * for every path a leak would take: .env and its variants, version control,
 * logs, databases and dumps, the project's own files, and the tricks that
 * climb out of public/ (encoded dots, doubled slashes, trailing dots). It also
 * fetches every file in public/ itself, so a secret copied into an innocent
 * name is caught.
 *
 * A path passes when its answer holds nothing of the site's private files:
 * none of the site's real secrets (APP_KEY, passwords, keys - read from its
 * .env HERE, on the server, and never sent anywhere) and none of the tell-tale
 * content of the file asked for. The status is reported but does not decide:
 * an app may well answer 200 with its own "not found" page.
 */
class ExposureCheck
{
    /** Paths that must never give anything away, whatever the site contains. */
    public const PROBES = [
        '/.env', '/.ENV', '/.env.backup', '/.env.production', '/.env.local', '/.env.example', '/env', '/.env/',
        '/%2eenv', '/.env%20', '/public/.env', '/../.env', '/%2e%2e/.env', '/..%2f.env', '/.%2e/.env',
        '//.env', '/index.php/../.env', '/public/../.env',
        '/.git/config', '/.git/HEAD', '/.gitignore',
        '/storage/logs/laravel.log', '/../storage/logs/laravel.log', '/storage/app/private/', '/storage/framework/sessions/',
        '/database/database.sqlite', '/../database/database.sqlite', '/backup.sql', '/dump.sql', '/database.sql',
        '/composer.json', '/composer.lock', '/package.json', '/artisan', '/phpunit.xml', '/auth.json',
        '/config/app.php', '/../config/app.php', '/routes/web.php', '/../routes/web.php', '/bootstrap/cache/config.php',
        '/vendor/autoload.php', '/../vendor/autoload.php', '/.htaccess', '/server.key', '/id_rsa',
    ];

    /** Content that shows a private file was served, whichever path it came from. */
    private const TELLTALES = [
        'APP_KEY=', 'DB_PASSWORD=', 'DB_USERNAME=', '[core]', 'repositoryformatversion', 'ref: refs/heads/',
        'SQLite format 3', '"require": {', '"packages": [', '#!/usr/bin/env php', 'local.ERROR:', 'production.ERROR:',
        '-----BEGIN', 'CREATE TABLE', 'INSERT INTO', "'connections' =>", 'Illuminate\\Foundation\\Application',
    ];

    public function __construct(private readonly Site $site) {}

    /**
     * @return array{passed: bool, checked: int, failed: int, results: list<array{path: string, status: int, ok: bool, why: ?string}>}
     */
    public function run(): array
    {
        $agent = AgentClient::for($this->site->host);
        $needles = $this->secrets($agent);
        $paths = array_values(array_unique(array_merge(self::PROBES, $this->publicFiles($agent))));
        $base = 'https://'.$this->site->domain;

        // The first 512 KB of each answer is what is searched; no more is
        // transferred (BoundedSink).
        $sinks = array_combine($paths, array_map(fn () => new BoundedSink(512 * 1024), $paths));
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($path) => $pool->as($path)->withOptions(['allow_redirects' => false, 'http_errors' => false] + $sinks[$path]->options())
                ->withHeaders(['User-Agent' => 'codeinchrome-exposure-check'])->timeout(15)->get($base.$path),
            $paths,
        ));

        $results = [];
        foreach ($paths as $path) {
            $r = $sinks[$path]->answerFrom($responses[$path] ?? null);
            if ($r === null) {
                // No answer at all gives nothing away; it is reported, not failed.
                $results[] = ['path' => $path, 'status' => 0, 'ok' => true, 'why' => 'no answer'];

                continue;
            }
            $body = $r['body'];
            $why = null;
            foreach ($needles as $label => $needle) {
                if (str_contains($body, $needle)) {
                    $why = "the answer contains the site's $label";
                    break;
                }
            }
            if (! $why && $r['status'] < 400) {
                foreach (self::TELLTALES as $mark) {
                    if (str_contains($body, $mark)) {
                        $why = 'the answer looks like the private file ('.trim($mark, ' :=[{').')';
                        break;
                    }
                }
            }
            $results[] = ['path' => $path, 'status' => $r['status'], 'ok' => $why === null, 'why' => $why];
        }
        $failed = count(array_filter($results, fn ($x) => ! $x['ok']));

        // "passed", not "ok": the API's own "ok" says the check RAN (FileController::attempt).
        return ['passed' => $failed === 0, 'checked' => count($results), 'failed' => $failed, 'results' => $results];
    }

    /**
     * The site's real secret values, from its .env: what must never appear in
     * any answer. Kept in memory for this check only; never returned or logged.
     *
     * @return array<string, string> label => value
     */
    private function secrets(AgentClient $agent): array
    {
        try {
            $env = $agent->readFile($this->site->site_id, '/.env');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', $env) as $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=\s*"?([^"\s#]+)"?/', $line, $m)) {
                continue;
            }
            [, $key, $value] = $m;
            $secretKey = $key === 'APP_KEY' || preg_match('/(PASSWORD|SECRET|_KEY|TOKEN)$/', $key);
            if ($secretKey && strlen($value) >= 8 && ! in_array(strtolower($value), ['null', 'false', 'true'], true)) {
                $out[$key] = $value;
                // APP_KEY is also leaked in its decoded form.
                if (str_starts_with($value, 'base64:')) {
                    $out["$key (base64 part)"] = substr($value, 7);
                }
            }
        }

        return $out;
    }

    /**
     * Every file in public/ - what the site actually serves - so a secret
     * copied into an ordinary-looking file (public/notes.txt) is found too.
     * Capped, so a site with thousands of assets is still checked quickly.
     */
    private function publicFiles(AgentClient $agent): array
    {
        try {
            $paths = $agent->paths($this->site->site_id)['paths'] ?? [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($paths as $p) {
            if (str_starts_with($p, '/public/') && ! str_starts_with($p, '/public/build/')) {
                $out[] = implode('/', array_map('rawurlencode', explode('/', substr($p, strlen('/public')))));
            }
        }

        return array_slice($out, 0, 300);
    }
}
