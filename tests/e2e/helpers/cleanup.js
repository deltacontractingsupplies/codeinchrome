import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const CONTROL = fileURLToPath(new URL('../../../control', import.meta.url));

/**
 * Remove a site through the control plane, whatever state the test left it in.
 *
 * Tests that provision against a real fleet must clean up even when they fail,
 * or every failed run abandons a container, a DNS record and a held name. An
 * earlier version of the config CLAIMED every spec cleaned up in a finally and
 * no spec actually did - two abandoned sites on two hosts were the proof.
 */
export function destroySite(siteId) {
  try {
    execFileSync('php', ['artisan', 'site:reap', siteId], {
      cwd: CONTROL,
      stdio: 'pipe',
      timeout: 120_000,
    });
  } catch (error) {
    // Reported, never thrown: a cleanup failure must not mask the real result
    // of the test, but it must not be silent either.
    console.warn(`[cleanup] could not remove ${siteId}: ${error.stdout?.toString() || error.message}`);
  }
}
