import { execFileSync } from 'node:child_process';
import { controlSsh } from './control-host.js';
import { fileURLToPath } from 'node:url';

const LOCAL_CONTROL = fileURLToPath(new URL('../../../control', import.meta.url));

/**
 * Remove a site through THE CONTROL PLANE THE TEST ACTUALLY USED.
 *
 * This used to always run `artisan site:reap` locally, even when the suite was
 * pointed at production. Against production that was actively wrong: the local
 * database has no row for the site, so reap fell through to sweeping every
 * host and withdrawing the DNS record - which worked - while production kept
 * a row saying the site was `live`. Five sites ended up marked live in
 * production with no container and no DNS behind them, and fleet:audit was the
 * only thing that knew.
 *
 * So the cleanup follows the base url: local runs reap locally, and a run
 * against app.codeinchrome.com reaps ON the control host, over ssh.
 */
function controlTarget() {
  const base = process.env.CIC_BASE_URL || 'http://127.0.0.1:8123';

  if (/127\.0\.0\.1|localhost/.test(base)) {
    return { kind: 'local' };
  }

  return {
    kind: 'remote',
    host: controlSsh(),
    path: process.env.CIC_CONTROL_PATH || '/srv/control',
  };
}

export function destroySite(siteId) {
  const target = controlTarget();

  try {
    if (target.kind === 'local') {
      execFileSync('php', ['artisan', 'site:reap', siteId], {
        cwd: LOCAL_CONTROL,
        stdio: 'pipe',
        timeout: 180_000,
      });
    } else {
      execFileSync(
        'ssh',
        [
          '-o', 'ConnectTimeout=20',
          '-o', 'StrictHostKeyChecking=accept-new',
          target.host,
          `cd ${target.path} && sudo -u codeinchrome php8.4 artisan site:reap ${JSON.stringify(siteId)}`,
        ],
        { stdio: 'pipe', timeout: 180_000 },
      );
    }
  } catch (error) {
    // Reported, never thrown: a cleanup failure must not mask the real result
    // of the test, but it must not be silent either.
    const detail = error.stdout?.toString() || error.stderr?.toString() || error.message;
    console.warn(`[cleanup] could not remove ${siteId} via ${target.kind} control plane: ${detail}`);
  }
}
