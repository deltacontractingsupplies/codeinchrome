<?php

namespace App\Abuse;

use App\Models\Site;
use Illuminate\Support\Facades\Http;

/**
 * What a site's pages send visitors to (owner, 2026-09-25): the edge refuses
 * an offsite REDIRECT and an executable DOWNLOAD from the site itself, but a
 * plain link or button pointing at malware elsewhere is only visible in the
 * page. So the site's pages are read from outside, as a visitor gets them,
 * and every link, form and meta refresh is checked.
 *
 *   ban:    a link to an executable or installer, on any site; a script that
 *           puts a PowerShell/mshta/shell command on the visitor's clipboard
 *           (ClickFix fake CAPTCHA pages)
 *   review: an archive, a URL shortener (hides where it goes), a raw IP
 *           address, a form that posts to another site (how phishing pages
 *           steal what is typed), a meta refresh to another site, and a
 *           password field on a page that names a bank or a big brand
 */
class LinkScanner
{
    public const MAX_PAGES = 30;

    /**
     * Sent like a browser, so a kit cannot show the scanner a clean page by
     * its User-Agent (the second security audit, 2026-09-25: the old one
     * announced itself). X-Cic-Probe is removed by the edge before the app
     * sees it (agent appProxy) and keeps these requests out of the visitor
     * count (agent visits.go).
     */
    public const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
        'X-Cic-Probe' => '1',
    ];

    private const EXECUTABLE = '/\.(exe|msi|msix|apk|aab|dmg|pkg|scr|bat|cmd|ps1|vbs|vbe|jar|hta|wsf|lnk|pif|cpl|appimage|deb|rpm)$/i';

    private const ARCHIVE = '/\.(zip|rar|7z|tar|gz|tgz|bz2|xz|iso|img)$/i';

    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 'goo.gl', 'is.gd', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'ow.ly',
        'buff.ly', 't.ly', 'rb.gy', 'tiny.cc', 'bl.ink', 'short.io', 'v.gd', 's.id', 'shorturl.me', 'lnkd.in'];

    private const BRANDS = ['paypal', 'apple id', 'icloud', 'microsoft', 'office 365', 'outlook', 'google account', 'gmail',
        'facebook', 'instagram', 'whatsapp', 'netflix', 'amazon', 'bank', 'chase', 'wells fargo', 'citibank', 'hsbc',
        'barclays', 'coinbase', 'binance', 'metamask', 'wallet', 'dhl', 'fedex', 'ups', 'usps'];

    /** Where a free site may post a form or send a visitor without review (the edge guard's list). */
    private const ALLOWED_HOSTS = ['checkout.stripe.com', 'billing.stripe.com', 'connect.stripe.com', 'paypal.com', 'www.paypal.com',
        'www.sandbox.paypal.com', 'accounts.google.com', 'appleid.apple.com',
        // Cloudflare's own analytics beacon, which Cloudflare - our CDN, not
        // the site - adds to pages it serves (seen on every page of every
        // site in a dry run, 2026-09-26).
        'static.cloudflareinsights.com'];

    /** The site's own script files read per scan. */
    public const MAX_SCRIPTS = 5;

    private const CLIPBOARD_COMMAND = '/(clipboard\.writeText|execCommand\(\s*["\']copy)[\s\S]{0,600}(powershell|mshta|cmd(\.exe)?\s*\/c|curl[^|<]{0,200}\|\s*(ba)?sh|iex\b|Invoke-WebRequest|-enc(odedcommand)?\b)/i';

    /** Pages rendered in a browser per scan (audit A15): a browser costs. */
    public const RENDER_PAGES = 3;

    /**
     * @return array{ban: list<string>, review: list<string>, pages: int}
     *
     * $render: also read the first pages as a browser has them once their
     * scripts ran - always for a report, otherwise once a day per site (the
     * scan itself is hourly).
     */
    public function scan(Site $site, ?bool $render = null): array
    {
        $render ??= \Illuminate\Support\Facades\Cache::add("linkscan.rendered.{$site->id}", true, now()->addDay());
        $rendered = 0;
        $scripts = [];
        $own = array_map('strtolower', array_merge([$site->domain], $site->domains()->pluck('domain')->all()));
        // The home page, and every GET route without parameters: a kit at a
        // path nothing links to was never fetched.
        $queue = array_merge(["https://{$site->domain}/"], array_map(fn ($p) => "https://{$site->domain}/".ltrim($p, '/'), $this->routes($site)));
        $seen = [];
        $ban = $review = [];
        $pages = 0;
        while ($queue && $pages < self::MAX_PAGES) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            try {
                $res = Http::timeout(10)->withoutRedirecting()
                    ->withHeaders(self::HEADERS)->get($url);
            } catch (\Throwable) {
                continue;
            }
            // A redirect within the site (a home page sending visitors to /login)
            // is followed; one to elsewhere the edge refuses anyway.
            if ($res->status() >= 300 && $res->status() < 400 && ($loc = $this->absolute($res->header('Location'), $url))) {
                if (in_array(strtolower((string) parse_url($loc, PHP_URL_HOST)), $own, true)) {
                    $queue[] = $loc;
                }

                continue;
            }
            if (! str_contains(strtolower($res->header('Content-Type')), 'html') || $res->status() !== 200) {
                continue;
            }
            $pages++;
            $html = $res->body();
            $this->inspect($html, $url, $url, $own, $ban, $review, $queue, $seen, $scripts);
            // The same page as a browser has it once its scripts ran: a kit that
            // builds its form, frame or "press Win+R" in JavaScript is invisible
            // in the HTML (audit A15). Rendered on another host than the site's.
            if ($render && $rendered < self::RENDER_PAGES && ($dom = $this->rendered($site, $url)) !== null) {
                $rendered++;
                $jsBan = $jsReview = [];
                $this->inspect($dom, $url, "$url, after its scripts ran", $own, $jsBan, $jsReview, $queue, $seen);
                // Only what the HTML alone did not already show.
                $new = fn (array $found, array $known) => array_filter($found,
                    fn ($m) => ! in_array(str_replace(', after its scripts ran)', ')', $m), $known, true));
                array_push($ban, ...$new($jsBan, $ban));
                array_push($review, ...$new($jsReview, $review));
            }
        }

        foreach ($scripts as $src => $page) {
            try {
                $js = Http::timeout(10)->withoutRedirecting()->withHeaders(self::HEADERS)->get($src);
            } catch (\Throwable) {
                continue;
            }
            if (! $js->successful()) {
                continue;
            }
            $code = substr($js->body(), 0, 1 << 20);
            [$sb, $sr] = $this->scriptFindings($code, "$src, a script of $page");
            array_push($ban, ...$sb);
            array_push($review, ...$sr);
            if (preg_match(self::CLIPBOARD_COMMAND, $code)) {
                $review[] = "a script puts a command (PowerShell, mshta or a shell) on the visitor's clipboard ($src, a script of $page)";
            }
        }

        return ['ban' => array_values(array_unique($ban)), 'review' => array_values(array_unique($review)), 'pages' => $pages];
    }

    /** One page's HTML (or rendered DOM) against every rule; findings added in place. */
    private function inspect(string $html, string $url, string $where, array $own, array &$ban, array &$review, array &$queue, array $seen, array &$scripts = []): void
    {
        [$sb, $sr] = $this->scriptFindings($html, $where);
        array_push($ban, ...$sb);
        array_push($review, ...$sr);
        foreach ($this->links($html, $url) as [$kind, $target]) {
            $host = strtolower((string) parse_url($target, PHP_URL_HOST));
            $path = (string) parse_url($target, PHP_URL_PATH);
            $internal = $host === '' || in_array($host, $own, true);
            // Followed only if it looks like a page: a PDF or an image is not one.
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($internal && $kind === 'a' && in_array($ext, ['', 'html', 'htm', 'php'], true)
                && count($queue) + count($seen) < self::MAX_PAGES * 3) {
                $queue[] = $target;
            }
            // A program on this site is refused at the edge anyway, so a
            // link to one is the owner's doing; a link elsewhere can be a
            // visitor's comment, so it goes to a person (the second
            // security audit, 2026-09-25: harmless GitHub release links
            // banned accounts).
            if (preg_match(self::EXECUTABLE, $path) && $internal) {
                $ban[] = "links to a program download on the site itself: $target (on $where)";
            } elseif (preg_match(self::EXECUTABLE, $path)) {
                $review[] = "links to a program download elsewhere: $target (on $where)";
            } elseif (preg_match(self::ARCHIVE, $path) && ! $internal) {
                $review[] = "links to an archive elsewhere: $target (on $where)";
            }
            if ($internal) {
                // The site's own scripts are read too (after the pages): a
                // ClickFix or a download built in script lives there.
                if ($kind === 'script' && count($scripts) < self::MAX_SCRIPTS) {
                    $scripts[$target] = $where;
                }

                continue;
            }
            if (in_array($host, self::SHORTENERS, true)) {
                $review[] = "links through a URL shortener, which hides where it goes: $target (on $where)";
            } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
                $review[] = "links to a bare IP address: $target (on $where)";
            } elseif ($kind === 'form' && ! $this->allowed($host, $path)) {
                $review[] = "a form posts what is typed to another site: $host (on $where)";
            } elseif ($kind === 'refresh' && ! $this->allowed($host, $path)) {
                // The edge refuses the same redirect sent as a header.
                $ban[] = "sends visitors on to another site with a meta refresh: $target (on $where)";
            } elseif (in_array($kind, ['iframe', 'script', 'js-redirect'], true) && ! $this->allowed($host, $path)) {
                $review[] = ['iframe' => 'frames another site', 'script' => 'runs a script from another site', 'js-redirect' => 'sends visitors on to another site from a script'][$kind].": $target (on $where)";
            }
        }
        // "ClickFix" fake CAPTCHA pages (Trend Micro, 2025-26, on Lovable,
        // Netlify and Vercel): the page's script puts a command on the
        // clipboard and tells the visitor to press Win+R and paste it.
        // Both halves make the attack: a command put on the clipboard AND
        // the visitor told to press Win+R and paste it. Either alone is
        // also a documentation page's "copy the install command" button.
        $clipboardCommand = preg_match('/(clipboard\.writeText|execCommand\(\s*["\']copy)[\s\S]{0,600}(powershell|mshta|cmd(\.exe)?\s*\/c|curl[^|<]{0,200}\|\s*(ba)?sh|iex\b|Invoke-WebRequest|-enc(odedcommand)?\b)/i', $html) === 1;
        $winR = preg_match('/\b(win(dows)?(\s*key)?\s*\+\s*r|⊞\s*\+\s*r)\b/iu', strip_tags($html)) === 1
            && preg_match('/(ctrl\s*\+\s*v|paste|verify|human|captcha)/i', strip_tags($html)) === 1;
        if ($clipboardCommand && $winR) {
            $ban[] = "puts a command on the visitor's clipboard and tells them to press Win+R and paste it: a ClickFix malware page (on $where)";
        } elseif ($clipboardCommand) {
            $review[] = "puts a command (PowerShell, mshta or a shell) on the visitor's clipboard (on $where)";
        } elseif ($winR) {
            $review[] = "tells visitors to press Win+R and paste something: possible ClickFix fake CAPTCHA (on $where)";
        }
        if (preg_match('/<input[^>]+type\s*=\s*["\']?password/i', $html)) {
            $text = strtolower(strip_tags($html));
            foreach (self::BRANDS as $brand) {
                if (preg_match('/\b'.preg_quote($brand, '/').'\b/', $text)) {
                    $review[] = "a password form on a page that names \"$brand\": possible phishing (on $where)";
                    break;
                }
            }
        }
    }

    /**
     * A program built or carried by the page's script (audit A17): the edge
     * refuses to serve a program, so a script that assembles one in the
     * browser (a Blob and a download of an .exe, an installer's MIME type)
     * or carries one inline (a base64 Windows executable, "TVqQ...") is
     * going round that on purpose. No honest page does either; a CSV export
     * built the same way is left alone.
     *
     * @return array{0: list<string>, 1: list<string>} [ban, review]
     */
    private function scriptFindings(string $code, string $where): array
    {
        $ban = [];
        $program = '\.(exe|msi|msix|scr|bat|cmd|ps1|vbs|vbe|jar|hta|wsf|lnk|apk|dmg|pkg|appimage)\b[\'"`]';
        $mime = 'application\/(x-msdownload|x-msdos-program|vnd\.microsoft\.portable-executable|x-dosexec|vnd\.android\.package-archive|x-apple-diskimage)';
        $blob = '(createObjectURL|msSaveOrOpenBlob|msSaveBlob|new\s+Blob|\.download\s*=)';
        if (preg_match("/$blob".'[\s\S]{0,800}'."($program|$mime)|($program|$mime)".'[\s\S]{0,800}'."$blob/i", $code)) {
            $ban[] = "builds a program download in the page's own script, round the edge's refusal to serve programs ($where)";
        }
        if (preg_match('/TVqQAAMAAAAEAAAA|TVpQAAIAAAAEAA8A|TVqAAAEAAAAEABAA/', $code)) {
            $ban[] = "carries a Windows program inside the page itself ($where)";
        }

        return [$ban, []];
    }

    /** The page rendered on another host than the site's; null if that fails. */
    private function rendered(Site $site, string $url): ?string
    {
        $hosts = array_keys(config('fleet.hosts', []));
        $others = array_values(array_diff($hosts, [$site->host]));
        $host = $others ? $others[crc32($site->site_id) % count($others)] : $site->host;
        try {
            $dom = \App\Fleet\AgentClient::for($host)->render($url)['dom'] ?? '';
        } catch (\Throwable) {
            return null; // the raw read still counts; a renderer that failed proves nothing
        }

        return is_string($dom) && $dom !== '' ? $dom : null;
    }

    /**
     * Payment and sign-in providers. Lemon Squeezy only at a checkout: anyone
     * can open a store under lemonsqueezy.com (the audit, 2026-09-25; the
     * edge applies the same rule, agent create.go lemonSqueezyCheckout).
     */
    private function allowed(string $host, string $path): bool
    {
        return in_array($host, self::ALLOWED_HOSTS, true)
            || (str_ends_with($host, '.lemonsqueezy.com') && preg_match('#^/(checkout|buy)/#', $path) === 1);
    }

    /** @return list<array{0: string, 1: string}> [kind, absolute url] for links, forms and meta refreshes */
    private function links(string $html, string $base): array
    {
        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $out = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            if ($u = $this->absolute($a->getAttribute('href'), $base)) {
                $out[] = ['a', $u];
            }
        }
        foreach ($doc->getElementsByTagName('form') as $f) {
            if ($u = $this->absolute($f->getAttribute('action'), $base)) {
                $out[] = ['form', $u];
            }
        }
        foreach (['iframe' => 'iframe', 'script' => 'script'] as $tag => $kind) {
            foreach ($doc->getElementsByTagName($tag) as $el) {
                if (($src = $el->getAttribute('src')) !== '' && ($u = $this->absolute($src, $base))) {
                    $out[] = [$kind, $u];
                }
            }
        }
        // location = / .href = / .replace( / .assign( / window.open( to an absolute URL.
        if (preg_match_all('/(?:location(?:\.href)?\s*=|location\.(?:replace|assign)\(|window\.open\()\s*[\'"`]((?:https?:)?\/\/[^\'"`\s]+)/i', $html, $js)) {
            foreach ($js[1] as $u) {
                if ($abs = $this->absolute($u, $base)) {
                    $out[] = ['js-redirect', $abs];
                }
            }
        }
        foreach ($doc->getElementsByTagName('meta') as $m) {
            if (strtolower($m->getAttribute('http-equiv')) === 'refresh' && preg_match('/url\s*=\s*[\'"]?([^\'";]+)/i', $m->getAttribute('content'), $mm)) {
                if ($u = $this->absolute(trim($mm[1]), $base)) {
                    $out[] = ['refresh', $u];
                }
            }
        }

        return $out;
    }

    /** The site's GET routes without parameters, from the app itself; none if it cannot be asked. */
    private function routes(Site $site): array
    {
        try {
            $r = \App\Fleet\AgentClient::for($site->host)->runCommand($site->site_id, 'artisan', ['route:list', '--json', '--method=GET']);
            $list = json_decode((string) ($r['result']['output'] ?? ''), true);
        } catch (\Throwable) {
            return [];
        }

        return collect(is_array($list) ? $list : [])->pluck('uri')
            ->filter(fn ($u) => is_string($u) && ! str_contains($u, '{') && ! str_starts_with($u, '_') && ! str_starts_with($u, 'up'))
            ->take(self::MAX_PAGES)->values()->all();
    }

    private function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        if (preg_match('#^https?://#i', $href)) {
            return strtok($href, '#') ?: null;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '');
        if (str_starts_with($href, '/')) {
            return $origin.strtok($href, '#');
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin.$dir.strtok($href, '#');
    }
}
