<?php

namespace App\Billing;

/**
 * What each plan was MEASURED to serve (tests/load/run.sh writes
 * resources/capacity.json). Only measured numbers are ever shown: a plan
 * with no measurement shows no capacity claim at all, never an estimate.
 *
 * "Concurrent visitors" is derived, and the pricing page says how: page views
 * per second at the bar (p95 <= 500 ms, errors <= 1%), times the seconds a
 * browsing visitor spends per page (VISITOR_SECONDS_PER_PAGE).
 */
class Capacity
{
    public const VISITOR_SECONDS_PER_PAGE = 10;

    private ?array $data = null;

    public function __construct(private readonly ?string $path = null) {}

    public function all(): array
    {
        if ($this->data === null) {
            $file = $this->path ?? config('billing.capacity_file', resource_path('capacity.json'));
            $this->data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }

        return $this->data;
    }

    /**
     * @return array{page_views_per_second: int, concurrent_visitors: int, p95_ms: ?int, websocket_connections: ?int, websocket_label: ?string}|null
     */
    public function forPlan(string $plan): ?array
    {
        $p = $this->all()['plans'][$plan] ?? null;
        if (! $p || empty($p['page_views_per_second'])) {
            return null;
        }

        return [
            'page_views_per_second' => (int) $p['page_views_per_second'],
            'concurrent_visitors' => (int) $p['page_views_per_second'] * self::VISITOR_SECONDS_PER_PAGE,
            'p95_ms' => $p['p95_ms'] ?? null,
            // Measured separately (tests/load/ws.sh); absent until it has been.
            'websocket_connections' => ! empty($p['websocket_connections']) ? (int) $p['websocket_connections'] : null,
            // The plan passed the test's highest step: its real limit is above it.
            'websocket_label' => ! empty($p['websocket_connections'])
                ? number_format((int) $p['websocket_connections']).(! empty($p['websocket_at_least']) ? '+' : '')
                : null,
        ];
    }
}
