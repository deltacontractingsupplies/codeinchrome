<?php
/*
 * Database isolation probe. Run INSIDE a customer container, as that customer:
 *
 *   docker exec -i -u 33:33 cic-B php -- OTHER_DB OTHER_USER < infra/probe-db.php
 *
 * It uses the site's own credentials from its own .env - exactly what a
 * hostile customer's code would have - and tries to reach another tenant.
 *
 * Positive controls run first. If this site cannot use its OWN database, every
 * "DENIED" below would be meaningless, so the probe stops instead.
 */

[$otherDb, $otherUser] = [$argv[1] ?? '', $argv[2] ?? ''];

$env = [];
foreach (file('/var/www/html/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
        $env[$m[1]] = $m[2];
    }
}

function connect(string $user, string $pass, ?string $db = null): PDO
{
    $dsn = 'mysql:host=cic-db;port=3306' . ($db ? ";dbname=$db" : '');
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
}

$fails = 0;
function line(string $label, string $verdict): void { printf("  %-52s %s\n", $label, $verdict); }
function denied(string $label, callable $attempt): void
{
    global $fails;
    try {
        $result = $attempt();
        if ($result === null || $result === false || $result === '' || $result === []) {
            line($label, 'DENIED (empty)');
            return;
        }
        line($label, '*** REACHED: ' . substr(json_encode($result), 0, 60) . ' ***');
        $fails++;
    } catch (Throwable $e) {
        line($label, 'DENIED');
    }
}

echo "=== positive controls ===\n";
try {
    $own = connect($env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_DATABASE']);
    $own->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $own->exec('CREATE TABLE IF NOT EXISTS probe_control (id INT)');
    $own->exec('DROP TABLE probe_control');
    line('connects, reads and writes its OWN database', 'YES');
} catch (Throwable $e) {
    line('connects, reads and writes its OWN database', 'NO - probe is vacuous: ' . $e->getMessage());
    exit(2);
}

echo "=== reaching another tenant (every line must be DENIED) ===\n";
denied("USE the other tenant's database", fn () => $own->exec("USE `$otherDb`") === false ? null : 'used');
denied("read the other tenant's users table", fn () => $own->query("SELECT * FROM `$otherDb`.users")->fetchAll());
denied('see the other database in SHOW DATABASES', fn () => in_array($otherDb, $own->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true) ? $otherDb : null);
denied("log in as the other tenant's user (own password)", fn () => connect($otherUser, $env['DB_PASSWORD'])->query('SELECT 1')->fetchColumn());
denied('log in as root (no password)', fn () => connect('root', '')->query('SELECT 1')->fetchColumn());
denied('log in as root (own password)', fn () => connect('root', $env['DB_PASSWORD'])->query('SELECT 1')->fetchColumn());
denied('read mysql.user', fn () => $own->query('SELECT user, host FROM mysql.user')->fetchAll());
denied('create a new database', fn () => $own->exec('CREATE DATABASE probe_escape') === false ? null : 'created');
denied('grant itself more', fn () => $own->exec("GRANT ALL ON *.* TO CURRENT_USER()") === false ? null : 'granted');
denied('read a server file with LOAD_FILE', fn () => $own->query("SELECT LOAD_FILE('/etc/passwd')")->fetchColumn());
denied('write a server file with INTO OUTFILE', fn () => $own->exec("SELECT 1 INTO OUTFILE '/tmp/probe'") === false ? null : 'written');
denied("see other tenants' connections", fn () => array_values(array_filter(
    $own->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC),
    fn ($r) => $r['User'] !== $env['DB_USERNAME']
)));

echo "=== one tenant cannot exhaust the shared server ===\n";
$held = [];
try {
    for ($i = 0; $i < 40; $i++) {
        $held[] = connect($env['DB_USERNAME'], $env['DB_PASSWORD']);
    }
    line('40 simultaneous connections refused past the cap', "*** all 40 accepted ***");
    $fails++;
} catch (Throwable $e) {
    line('40 simultaneous connections refused past the cap', 'capped at ' . count($held));
}
$held = [];

echo "\n" . ($fails === 0 ? "RESULT: database isolation held\n" : "RESULT: $fails DATABASE ISOLATION FAILURE(S)\n");
exit($fails === 0 ? 0 : 1);
