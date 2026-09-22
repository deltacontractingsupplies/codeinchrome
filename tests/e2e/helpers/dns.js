import { Resolver } from 'node:dns/promises';

/**
 * Wait until a name resolves, asking a public resolver DIRECTLY rather than
 * through the operating system.
 *
 * This is not a nicety. The OS resolver caches NEGATIVE answers for the zone's
 * SOA minimum - around 30 minutes on Cloudflare - so a single lookup made in
 * the seconds before a new record propagates poisons every later attempt in
 * the run. That is exactly what happened here: sites that were live and
 * serving correctly looked dead to the test for its whole duration, while
 * `curl --resolve` against the same host returned 200.
 *
 * So confirm propagation out of band FIRST, and only then let anything make an
 * OS-resolved request, so the first answer the OS caches is the right one.
 */
export async function waitForDns(hostname, { timeoutMs = 120_000, intervalMs = 3_000 } = {}) {
  const resolver = new Resolver();
  resolver.setServers(['1.1.1.1', '8.8.8.8']);

  const deadline = Date.now() + timeoutMs;
  let lastError = 'never queried';

  while (Date.now() < deadline) {
    try {
      const addresses = await resolver.resolve4(hostname);
      if (addresses.length > 0) {
        return addresses;
      }
      lastError = 'resolved to an empty answer';
    } catch (error) {
      lastError = error.code || error.message;
    }
    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }

  throw new Error(`${hostname} did not resolve within ${timeoutMs}ms (last: ${lastError})`);
}
