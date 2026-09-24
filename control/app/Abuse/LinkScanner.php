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
    public const MAX_PAGES = 15;

    private const EXECUTABLE = '/\.(exe|msi|msix|apk|aab|dmg|pkg|scr|bat|cmd|ps1|vbs|vbe|jar|hta|wsf|lnk|pif|cpl|appimage|deb|rpm)$/i';

    private const ARCHIVE = '/\.(zip|rar|7z|tar|gz|tgz|bz2|xz|iso|img)$/i';

    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 'goo.gl', 'is.gd', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'ow.ly',
        'buff.ly', 't.ly', 'rb.gy', 'tiny.cc', 'bl.ink', 'short.io', 'v.gd', 's.id', 'shorturl.me', 'lnkd.in'];

    private const BRANDS = ['paypal', 'apple id', 'icloud', 'microsoft', 'office 365', 'outlook', 'google account', 'gmail',
        'facebook', 'instagram', 'whatsapp', 'netflix', 'amazon', 'bank', 'chase', 'wells fargo', 'citibank', 'hsbc',
        'barclays', 'coinbase', 'binance', 'metamask', 'wallet', 'dhl', 'fedex', 'ups', 'usps'];

    /** Where a free site may post a form or send a visitor without review (the edge guard's list). */
    private const ALLOWED_HOSTS = ['checkout.stripe.com', 'billing.stripe.com', 'connect.stripe.com', 'paypal.com', 'www.paypal.com',
        'www.sandbox.paypal.com', 'accounts.google.com', 'appleid.apple.com'];

    /** @return array{ban: list<string>, review: list<string>, pages: int} */
    public function scan(Site $site): array
    {
        $own = array_map('strtolower', array_merge([$site->domain], $site->domains()->pluck('domain')->all()));
        $queue = ["https://{$site->domain}/"];
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
                    ->withHeaders(['User-Agent' => 'codeinchrome-safety (+https://codeinchrome.com/report)'])->get($url);
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
                if (preg_match(self::EXECUTABLE, $path)) {
                    $ban[] = "links to a program download: $target (on $url)";
                } elseif (preg_match(self::ARCHIVE, $path) && ! $internal) {
                    $review[] = "links to an archive elsewhere: $target (on $url)";
                }
                if ($internal) {
                    continue;
                }
                if (in_array($host, self::SHORTENERS, true)) {
                    $review[] = "links through a URL shortener, which hides where it goes: $target (on $url)";
                } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
                    $review[] = "links to a bare IP address: $target (on $url)";
                } elseif ($kind === 'form' && ! $this->allowed($host)) {
                    $review[] = "a form posts what is typed to another site: $host (on $url)";
                } elseif ($kind === 'refresh' && ! $this->allowed($host)) {
                    $review[] = "sends visitors on to another site: $target (on $url)";
                }
            }
            // "ClickFix" fake CAPTCHA pages (Trend Micro, 2025-26, on Lovable,
            // Netlify and Vercel): the page's script puts a command on the
            // clipboard and tells the visitor to press Win+R and paste it.
            if (preg_match('/(clipboard\.writeText|execCommand\(\s*["\']copy)[\s\S]{0,600}(powershell|mshta|cmd(\.exe)?\s*\/c|curl[^|<]{0,200}\|\s*(ba)?sh|iex\b|Invoke-WebRequest|-enc(odedcommand)?\b)/i', $html)) {
                $ban[] = "puts a command (PowerShell, mshta or a shell) on the visitor's clipboard: a ClickFix malware page (on $url)";
            } elseif (preg_match('/\b(win(dows)?(\s*key)?\s*\+\s*r|⊞\s*\+\s*r)\b/iu', strip_tags($html))
                && preg_match('/(ctrl\s*\+\s*v|paste|verify|human|captcha)/i', strip_tags($html))) {
                $review[] = "tells visitors to press Win+R and paste something: possible ClickFix fake CAPTCHA (on $url)";
            }
            if (preg_match('/<input[^>]+type\s*=\s*["\']?password/i', $html)) {
                $text = strtolower(strip_tags($html));
                foreach (self::BRANDS as $brand) {
                    if (preg_match('/\b'.preg_quote($brand, '/').'\b/', $text)) {
                        $review[] = "a password form on a page that names \"$brand\": possible phishing (on $url)";
                        break;
                    }
                }
            }
        }

        return ['ban' => array_values(array_unique($ban)), 'review' => array_values(array_unique($review)), 'pages' => $pages];
    }

    private function allowed(string $host): bool
    {
        return in_array($host, self::ALLOWED_HOSTS, true) || str_ends_with($host, '.lemonsqueezy.com');
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
        foreach ($doc->getElementsByTagName('meta') as $m) {
            if (strtolower($m->getAttribute('http-equiv')) === 'refresh' && preg_match('/url\s*=\s*[\'"]?([^\'";]+)/i', $m->getAttribute('content'), $mm)) {
                if ($u = $this->absolute(trim($mm[1]), $base)) {
                    $out[] = ['refresh', $u];
                }
            }
        }

        return $out;
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
