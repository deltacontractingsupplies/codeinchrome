<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /*
     * Isolation is a transaction that is rolled back, never a rebuild.
     *
     * RefreshDatabase, DatabaseMigrations, migrate:fresh and db:wipe are all
     * banned here. If a run ever points at the wrong database they destroy it,
     * and a rolled-back transaction cannot. They also replay every migration
     * per test, which is writes for no added certainty.
     */
    use DatabaseTransactions;

    /*
     * The schema is built ONCE and then reused for every test and every
     * subsequent run.
     *
     * `:memory:` looks like the obvious choice and does not work: Laravel
     * rebuilds the application between tests, the connection is remade, and a
     * new in-memory database is empty - so the schema silently vanishes after
     * the first test and every later one fails with "no such table".
     *
     * So the schema lives in one small file, named after a hash of the
     * migrations directory. Change a migration and the hash changes, a fresh
     * file is built, and the stale one is ignored - no manual invalidation
     * step to forget. The file holds schema and nothing else, because every
     * test's data is rolled back, so it stays a couple of hundred kilobytes
     * and is written once rather than once per test.
     *
     * It lives under the REPOSITORY, not in sys_get_temp_dir(). On macOS the
     * temp directory is /var/folders, which is on the boot disk - and on this
     * machine the boot disk is a soldered SSD being deliberately spared. The
     * repository is wherever the developer put it, which is the one location
     * they have actually chosen. CIC_TEST_SCHEMA_DIR overrides it.
     */
    private static ?string $schemaPath = null;

    protected function setUp(): void
    {
        self::$schemaPath ??= self::buildSchemaOnce();

        // Set before the application boots, so the connection opens against it.
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = self::$schemaPath;
        putenv('DB_DATABASE=' . self::$schemaPath);

        parent::setUp();
    }

    private static function buildSchemaOnce(): string
    {
        $root = dirname(__DIR__);

        $fingerprint = collect(glob($root . '/database/migrations/*.php'))
            ->map(fn ($f) => basename($f) . ':' . md5_file($f))
            ->implode('|');

        $dir = getenv('CIC_TEST_SCHEMA_DIR') ?: $root . '/storage/framework/testing';
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $path = $dir . '/schema-' . substr(sha1($fingerprint), 0, 12) . '.sqlite';

        if (! file_exists($path)) {
            touch($path);

            // Built in a separate process so this one is not left holding a
            // half-configured container. A normal, non-destructive `migrate`
            // against a file that did not exist a moment ago: nothing is
            // dropped, wiped or refreshed, because there is nothing there yet.
            $command = sprintf(
                'cd %s && DB_CONNECTION=sqlite DB_DATABASE=%s php artisan migrate --force 2>&1',
                escapeshellarg($root),
                escapeshellarg($path),
            );
            exec($command, $output, $status);

            if ($status !== 0) {
                @unlink($path); // never leave a half-built schema to be reused
                throw new \RuntimeException(
                    "Could not build the test schema at $path:\n" . implode("\n", $output)
                );
            }
        }

        return $path;
    }
}
