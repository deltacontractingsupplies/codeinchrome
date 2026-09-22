<?php

namespace App\Fleet;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Cloudflare DNS for the codeinchrome zone. */
class Dns
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $token,
        private readonly string $zoneId,
        private readonly string $zone,
    ) {}

    public static function make(): self
    {
        // config(), never env(). See config/fleet.php: env() outside a config
        // file returns null once the config is cached, which every production
        // deploy does - so an env() here would work locally and silently fail
        // to authenticate in production.
        $cf = config('fleet.cloudflare');

        foreach (['token', 'zone_id', 'zone_name'] as $key) {
            if (empty($cf[$key])) {
                throw new RuntimeException(
                    "fleet.cloudflare.$key is not set; DNS cannot be managed without it. " .
                    'Set CLOUDFLARE_' . strtoupper($key === 'zone_name' ? 'ZONE_NAME' : ($key === 'token' ? 'API_TOKEN' : 'ZONE_ID')) . ' in .env.'
                );
            }
        }

        return new self($cf['token'], $cf['zone_id'], $cf['zone_name']);
    }

    /**
     * Point a subdomain at a host. Returns the record id.
     *
     * Proxied is FALSE and must stay false. Caddy on the host answers the
     * ACME HTTP-01 challenge to get the certificate; with Cloudflare's proxy
     * in front, the challenge terminates at Cloudflare and the host never
     * gets a certificate of its own. The customer's site would then depend on
     * Cloudflare's edge for TLS, which is the opposite of "your code, your
     * server, take it with you".
     */
    public function upsert(string $name, string $ip): string
    {
        $fqdn = str_ends_with($name, $this->zone) ? $name : "$name.{$this->zone}";

        $existing = $this->records($fqdn);
        $payload = ['type' => 'A', 'name' => $fqdn, 'content' => $ip, 'proxied' => false, 'ttl' => 120];

        if ($existing) {
            $id = $existing[0]['id'];
            $this->request('put', "/zones/{$this->zoneId}/dns_records/$id", $payload);

            return $id;
        }

        $created = $this->request('post', "/zones/{$this->zoneId}/dns_records", $payload)['result'] ?? [];

        // Not a blind ['result']['id']. A response in an unexpected shape used
        // to surface as "Undefined array key \"id\"" attached to the customer's
        // site name, which says nothing about what actually went wrong.
        if (! is_array($created) || empty($created['id'])) {
            throw new RuntimeException(
                "Cloudflare accepted the record for $fqdn but returned no id, so it cannot be tracked. " .
                'Response: ' . json_encode($created)
            );
        }

        return (string) $created['id'];
    }

    /** Idempotent: a record that is already gone is a success, not an error. */
    public function delete(string $name): bool
    {
        $fqdn = str_ends_with($name, $this->zone) ? $name : "$name.{$this->zone}";
        $existing = $this->records($fqdn);

        if (! $existing) {
            return true;
        }

        foreach ($existing as $record) {
            $this->request('delete', "/zones/{$this->zoneId}/dns_records/{$record['id']}");
        }

        return true;
    }

    /**
     * The records matching a name, always as a list.
     *
     * Cloudflare's list endpoint returns a JSON array, but this normalises
     * anyway: a single object where a list was expected used to reach
     * $existing[0] and die with "Undefined array key 0", which surfaced to the
     * caller as an unexplained provisioning failure rather than as the
     * unexpected API response it was.
     *
     * @return array<int, array<string, mixed>>
     */
    private function records(string $fqdn): array
    {
        $result = $this->request('get', "/zones/{$this->zoneId}/dns_records", ['name' => $fqdn])['result'] ?? [];

        if (! is_array($result) || $result === []) {
            return [];
        }

        return array_is_list($result) ? $result : [$result];
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $response = Http::withToken($this->token)
            ->timeout(30)
            ->{$method}(self::BASE . $path, $data);

        $json = $response->json() ?? [];

        // Cloudflare answers 200 with success:false. Checking the status code
        // alone would read a rejected change as an applied one.
        if (($json['success'] ?? false) !== true) {
            $errors = collect($json['errors'] ?? [])->pluck('message')->implode('; ') ?: 'no reason given';
            throw new RuntimeException("Cloudflare refused " . strtoupper($method) . " $path: $errors");
        }

        return $json;
    }
}
