<?php

namespace App\Showcase;

use App\Fleet\AgentClient;
use App\Models\Site;
use App\Support\FileIcons;
use Illuminate\Support\Facades\Cache;

/**
 * The source of the public demos, read live from the demo sites and open to
 * read - never to edit - like an open-source repository. Used by the demo
 * code pages and the home page, so both always show the code as it is now.
 *
 * Public pages that read a customer container's files, so the rules are an
 * ALLOW-list and run on every path, listed or asked for directly:
 *   - only the sites named in config/showcase.php
 *   - only readable source: known extensions and a few known file names
 *   - never a dotfile or dot-folder (.env in every form, .git, ...), never
 *     dependencies, storage, build output, lock files, archives or dumps
 *   - never a file whose content looks like a secret (keys, tokens, private
 *     keys); a hard-coded password value is shown as bullets
 * Answers are cached for ten minutes, so readers never load the host.
 */
class DemoSource
{
    private const SOURCE_EXTENSIONS = ['php', 'js', 'mjs', 'ts', 'css', 'scss', 'json', 'md', 'xml', 'yml', 'yaml', 'txt', 'html', 'svg'];

    private const SOURCE_NAMES = ['artisan'];

    private const NEVER_UNDER = ['vendor/', 'node_modules/', 'storage/', 'bootstrap/cache/', 'public/build/', 'public/storage/', 'public/hot'];

    private const NEVER_NAMES = ['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'auth.json'];

    /** Content that must never be published, whatever file it is in. */
    private const SECRET_PATTERNS = [
        '/\b(sk|rk|pk)_live_[0-9A-Za-z]{10,}/',
        '/\bsk_test_[0-9A-Za-z]{10,}/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\bAPP_KEY\s*=\s*base64:/',
        '/\bgh[pousr]_[0-9A-Za-z]{30,}/',
        '/\bxox[baprs]-[0-9A-Za-z-]{10,}/',
    ];

    public const TTL = 600;

    public function __construct(private readonly FileIcons $icons) {}

    /** The demo's config and live site, or null: an unknown or offline demo. */
    public function demo(string $key): ?array
    {
        $config = config("showcase.demos.$key");
        if (! is_array($config)) {
            return null;
        }
        $site = Site::where('site_id', $config['site'])->where('status', 'live')->first();

        return $site ? [$config, $site] : null;
    }

    /** Every publishable path of the demo, sorted. Throws if the host cannot be asked. */
    public function paths(string $key, array $config, Site $site): array
    {
        return Cache::remember("demo-code:$key:paths", self::TTL, function () use ($config, $site) {
            $all = AgentClient::for($site->host)->paths($site->site_id)['paths'] ?? [];
            $paths = array_values(array_filter($all, fn ($p) => self::publishable($p, $config['hide'] ?? [])));
            sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

            return $paths;
        });
    }

    /** The first of $preferred the demo has, else its first file. */
    public function defaultPath(array $paths, array $preferred = []): string
    {
        foreach ([...$preferred, '/README.md', '/routes/web.php'] as $p) {
            if (in_array($p, $paths, true)) {
                return $p;
            }
        }

        return $paths[0] ?? '';
    }

    /**
     * Everything the home page's editor needs - or, when the demo's host
     * cannot be asked, the last good answer, else null: the home page is
     * never an error page because a demo's host is busy.
     *
     * @return array{key: string, demo: array, domain: string, path: string, file: array, tree: array, count: int}|null
     */
    public function preview(string $key): ?array
    {
        try {
            [$config, $site] = $this->demo($key) ?? [null, null];
            if (! $site) {
                return null;
            }
            $paths = $this->paths($key, $config, $site);
            $path = $this->defaultPath($paths, (array) ($config['feature'] ?? []));
            if ($path === '') {
                return null;
            }

            $preview = ['key' => $key, 'demo' => $config, 'domain' => $site->domain, 'path' => $path,
                'file' => $this->file($key, $site, $path), 'tree' => $this->tree($paths), 'count' => count($paths)];
            if (! $preview['file']['withheld']) {
                Cache::forever("demo-code:$key:last-preview", $preview);
            }

            return $preview;
        } catch (\Throwable $e) {
            report($e);

            // The last good one, if any: a host restarting is no reason to blank the home page.
            return Cache::get("demo-code:$key:last-preview");
        }
    }

    public static function publishable(string $path, array $hide = []): bool
    {
        $rel = ltrim($path, '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, '\\')) {
            return false;
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false; // .env, .env.backup, .git, .github, .htaccess ...
            }
        }
        foreach (self::NEVER_UNDER as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return false;
            }
        }
        foreach ($hide as $hidden) {
            $hidden = trim($hidden, '/');
            if ($rel === $hidden || str_starts_with($rel, $hidden.'/')) {
                return false;
            }
        }
        $name = strtolower(basename($rel));
        if (in_array($name, self::NEVER_NAMES, true) || str_contains($name, '.env')) {
            return false;
        }
        if (in_array($name, self::SOURCE_NAMES, true)) {
            return true;
        }

        return in_array(pathinfo($name, PATHINFO_EXTENSION), self::SOURCE_EXTENSIONS, true);
    }

    /** @return array{content: ?string, lines: int, language: string, withheld: ?string, redacted: bool} */
    public function file(string $key, Site $site, string $path): array
    {
        return Cache::remember("demo-code:$key:file:".sha1($path), self::TTL, function () use ($site, $path) {
            $language = self::language($path);
            $withheld = fn (string $why) => ['content' => null, 'lines' => 0, 'language' => $language, 'withheld' => $why, 'redacted' => false];
            try {
                $content = AgentClient::for($site->host)->readFile($site->site_id, $path);
            } catch (\Throwable) {
                return $withheld('This file could not be read just now.');
            }
            if (strlen($content) > 300_000) {
                return $withheld('This file is too large to show here.');
            }
            foreach (self::SECRET_PATTERNS as $pattern) {
                if (preg_match($pattern, $content)) {
                    return $withheld('This file is not shown: it may hold a secret.');
                }
            }
            [$content, $redacted] = self::redact($content);

            return ['content' => $content, 'lines' => substr_count($content, "\n") + 1, 'language' => $language, 'withheld' => null, 'redacted' => $redacted];
        });
    }

    /** A hard-coded password, token or key value is shown as bullets. */
    public static function redact(string $content): array
    {
        $count = 0;
        $out = preg_replace(
            '/((?:password|passwd|secret|token|api[_-]?key)[\'"]?\s*(?:=>|=|:)\s*)([\'"])(?!\s*\2)([^\'"\n]{4,})\2/i',
            '$1$2••••••$2',
            $content, -1, $count,
        );

        return [$out ?? $content, $count > 0];
    }

    public static function language(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.blade.php') => 'php-template',
            str_ends_with($path, '.php'), basename($path) === 'artisan' => 'php',
            str_ends_with($path, '.js'), str_ends_with($path, '.mjs') => 'javascript',
            str_ends_with($path, '.ts') => 'typescript',
            str_ends_with($path, '.css'), str_ends_with($path, '.scss') => 'css',
            str_ends_with($path, '.json') => 'json',
            str_ends_with($path, '.md') => 'markdown',
            str_ends_with($path, '.yml'), str_ends_with($path, '.yaml') => 'yaml',
            str_ends_with($path, '.xml'), str_ends_with($path, '.svg'), str_ends_with($path, '.html') => 'xml',
            default => 'plaintext',
        };
    }

    /** The language's name as the editor's status bar shows it. */
    public static function languageLabel(string $language): string
    {
        return ['php-template' => 'Blade', 'php' => 'PHP', 'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'css' => 'CSS',
            'json' => 'JSON', 'markdown' => 'Markdown', 'yaml' => 'YAML', 'xml' => 'XML'][$language] ?? 'Plain Text';
    }

    /**
     * Paths as a nested tree, each entry with its editor icon:
     * ['dirs' => [name => ['icon' => [closed, open], ...tree]], 'files' => [path => icon]].
     */
    public function tree(array $paths): array
    {
        $root = ['dirs' => [], 'files' => []];
        foreach ($paths as $path) {
            $parts = explode('/', ltrim($path, '/'));
            $node = &$root;
            foreach (array_slice($parts, 0, -1) as $dir) {
                $node['dirs'][$dir] ??= ['dirs' => [], 'files' => [],
                    'icon' => ['closed' => $this->icons->for($dir, true, false), 'open' => $this->icons->for($dir, true, true)]];
                $node = &$node['dirs'][$dir];
            }
            $node['files'][$path] = $this->icons->for(end($parts));
            unset($node);
        }
        $sort = function (array &$node) use (&$sort) {
            uksort($node['dirs'], 'strnatcasecmp');
            uksort($node['files'], fn ($a, $b) => strnatcasecmp(basename($a), basename($b)));
            foreach ($node['dirs'] as &$child) {
                $sort($child);
            }
        };
        $sort($root);

        return $root;
    }
}
