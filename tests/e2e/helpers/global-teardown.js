import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { lsCancelAllTestSubscriptions } from './fixtures.js';

/**
 * After the whole run: remove the test ACCOUNTS it created. Sites are reaped
 * per spec; accounts were not, and 78 had accumulated in production before
 * this existed. The command only touches @codeinchrome.test addresses and
 * refuses any account that still owns a site.
 */
export default async function globalTeardown() {
  // Before the accounts go: no test subscription may be left renewing, even
  // one whose spec was killed by its timeout before its own finally ran.
  try {
    const n = await lsCancelAllTestSubscriptions();
    if (n) console.warn(`[teardown] cancelled ${n} test subscription(s) a spec left renewing`);
  } catch (error) {
    console.warn(`[teardown] could not check test subscriptions: ${error.message}`);
  }
  const base = process.env.CIC_BASE_URL || 'http://127.0.0.1:8123';
  try {
    if (/127\.0\.0\.1|localhost/.test(base)) {
      execFileSync('php', ['artisan', 'accounts:purge-test'], {
        cwd: fileURLToPath(new URL('../../../control', import.meta.url)), stdio: 'pipe', timeout: 60_000,
      });
    } else {
      execFileSync('ssh', ['-o', 'ConnectTimeout=20', process.env.CIC_CONTROL_SSH || 'root@203.0.113.104',
        'cd /srv/control && sudo -u codeinchrome php8.4 artisan accounts:purge-test'], { stdio: 'pipe', timeout: 60_000 });
    }
  } catch (error) {
    console.warn(`[teardown] test accounts not purged: ${error.stderr?.toString() || error.message}`);
  }
}
