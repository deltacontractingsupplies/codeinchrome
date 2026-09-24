<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tests run on an in-memory SQLite database and nothing else: the schema is
 * built ONCE per run with a plain, non-destructive `migrate`, kept for every
 * test, and each test runs in a transaction that is rolled back.
 */
abstract class TestCase extends BaseTestCase
{
    private static ?\PDO $schema = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Refuse anything that could be the live database.
        $config = config('database.connections.'.config('database.default'));
        if (! $this->app->environment('testing') || ($config['driver'] ?? null) !== 'sqlite'
            || ($config['database'] ?? null) !== ':memory:' || ! empty($config['url'])) {
            throw new RuntimeException('Tests run only on an in-memory SQLite database (APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, no DB_URL).');
        }

        if (self::$schema) {
            DB::connection()->setPdo(self::$schema)->setReadPdo(self::$schema);
        } else {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            self::$schema = DB::connection()->getPdo();
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }
}
