<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A Google sign-in failed in production with "database is locked"
 * (2026-09-24): its transaction read, then wrote, while the scheduler wrote.
 * SQLite refuses a DEFERRED reader's upgrade to a write at once rather than
 * wait (waiting could deadlock). These settings are the fix; see
 * config/database.php.
 */
class SqliteLockingTest extends TestCase
{
    public function test_transactions_take_the_write_lock_first_and_wait_for_it(): void
    {
        $sqlite = config('database.connections.sqlite');
        $this->assertSame('IMMEDIATE', $sqlite['transaction_mode']);
        $this->assertGreaterThanOrEqual(5000, $sqlite['busy_timeout']);
    }

    public function test_a_transaction_waits_for_another_writer_instead_of_failing(): void
    {
        // Two real connections to one database file, as two PHP processes
        // would be: one holds the write lock briefly, the other must wait.
        $file = storage_path('framework/testing/locking-'.getmypid().'.sqlite');
        @unlink($file);
        try {
            $holder = new \PDO("sqlite:$file");
            $holder->exec('CREATE TABLE t (n INTEGER)');
            config(['database.connections.locking' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '',
                'busy_timeout' => 3000, 'transaction_mode' => 'IMMEDIATE', 'foreign_key_constraints' => false]]);
            $db = \Illuminate\Support\Facades\DB::connection('locking');

            // Without a wait, this is the production failure: refused at once.
            $holder->exec('BEGIN IMMEDIATE');
            $started = microtime(true);
            try {
                $db->transaction(fn () => $db->table('t')->insert(['n' => 1]));
                $this->fail('the lock was not held');
            } catch (\PDOException $e) { // QueryException too - it extends PDOException
                $this->assertStringContainsString('database is locked', $e->getMessage());
                $this->assertGreaterThan(2.5, microtime(true) - $started, 'it WAITED busy_timeout before giving up');
            }
            $holder->exec('COMMIT');

            // Released: the same transaction goes through.
            $db->transaction(function () use ($db) {
                $db->table('t')->count();          // read first...
                $db->table('t')->insert(['n' => 2]); // ...then write, as the sign-in does
            });
            $this->assertSame(1, $db->table('t')->count());
        } finally {
            \Illuminate\Support\Facades\DB::purge('locking');
            $holder = null;
            @unlink($file);
        }
    }
}
