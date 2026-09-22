<?php

namespace App\Audit;

use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class Audit
{
    /**
     * Record an event. Never throws: an audit write that fails must not turn
     * a successful action into an error for the customer - but it is logged
     * loudly, because a silent gap in an audit trail is the worst kind.
     *
     * @param  array<string, mixed>  $detail  never secrets: no passwords, tokens or keys
     */
    public static function record(string $action, ?User $account = null, Site|string|null $site = null, array $detail = [], ?User $actor = null): void
    {
        $request = app()->runningInConsole() ? null : request();
        $actor ??= $request?->user();
        $account ??= $actor ?? ($site instanceof Site ? $site->user : null);

        try {
            AuditEvent::create([
                'account_id' => $account?->id,
                'actor_id' => $actor?->id,
                'site' => $site instanceof Site ? $site->site_id : $site,
                'action' => $action,
                'detail' => $detail ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : 'system',
            ]);
        } catch (\Throwable $e) {
            Log::error('AUDIT WRITE FAILED', ['action' => $action, 'site' => $site instanceof Site ? $site->site_id : $site, 'error' => $e->getMessage()]);
        }
    }
}
