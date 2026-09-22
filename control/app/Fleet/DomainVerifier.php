<?php

namespace App\Fleet;

use App\Models\SiteDomain;
use Illuminate\Support\Facades\Http;

/**
 * Checks, against public DNS, that a customer controls a domain and that it
 * points at the right host.
 *
 * Asked of two independent public resolvers over DNS-over-HTTPS rather than of
 * this machine's resolver: a record the customer has just published reaches
 * resolvers at different speeds (measured here: 5 seconds for one, over six
 * minutes for another), so either one seeing it is enough to proceed.
 */
class DomainVerifier
{
    private const RESOLVERS = [
        'https://cloudflare-dns.com/dns-query',
        'https://dns.google/resolve',
    ];

    /**
     * @return array{ok: bool, txt: bool, points: bool, message: string}
     */
    public function check(SiteDomain $d, string $hostIp): array
    {
        $txtValues = $this->lookup($d->challengeName(), 'TXT');
        $txt = in_array($d->token, $txtValues, true);

        $addresses = $this->lookup($d->domain, 'A');
        $points = in_array($hostIp, $addresses, true);

        $problems = [];
        if (! $txt) {
            $problems[] = "No TXT record \"{$d->challengeName()}\" with the value shown yet"
                . ($txtValues ? ' (found: ' . implode(', ', array_slice($txtValues, 0, 3)) . ')' : '') . '.';
        }
        if (! $points) {
            $problems[] = "{$d->domain} does not point at $hostIp yet"
                . ($addresses ? ' (it points at ' . implode(', ', $addresses) . ')' : '') . '.';
        }

        return [
            'ok' => $txt && $points,
            'txt' => $txt,
            'points' => $points,
            'message' => $problems ? implode(' ', $problems) . ' New records can take a few minutes to appear.' : 'Verified.',
        ];
    }

    /** @return array<int, string> answers from any resolver that gave them */
    private function lookup(string $name, string $type): array
    {
        $found = [];
        foreach (self::RESOLVERS as $url) {
            try {
                $r = Http::timeout(5)->withHeaders(['Accept' => 'application/dns-json'])
                    ->get($url, ['name' => $name, 'type' => $type]);
            } catch (\Throwable) {
                continue;
            }
            foreach ($r->json('Answer') ?? [] as $a) {
                // TXT answers arrive quoted; A answers do not. CNAMEs in the
                // chain are followed by the resolver and appear with type 5.
                if (($type === 'TXT' && ($a['type'] ?? 0) === 16) || ($type === 'A' && ($a['type'] ?? 0) === 1)) {
                    $found[] = trim((string) $a['data'], '"');
                }
            }
        }

        return array_values(array_unique($found));
    }
}
