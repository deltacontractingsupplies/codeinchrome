<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that ever removes audit events: by age, 400 days - a year
 * plus a margin, enough for any annual review. A query-builder delete on
 * purpose, bypassing the model that refuses deletes everywhere else.
 */
class AuditPrune extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Delete audit events older than 400 days';

    public function handle(): int
    {
        $n = DB::table('audit_events')->where('created_at', '<', now()->subDays(400))->delete();
        $this->line("pruned $n event(s) older than 400 days");

        return self::SUCCESS;
    }
}
