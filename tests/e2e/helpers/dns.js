import { Resolver } from 'node:dns/promises';

/**
 * Wait until a name is actually published, asking the zone's AUTHORITATIVE
 * nameservers rather than a recursive resolver.
 *
 * Two separate caching traps made this necessary, and both produced the same
 * symptom - a site that was live and serving looked dead for the entire run:
 *
 * 1. The OS resolver caches NEGATIVE answers for the zone's SOA minimum,
 *    around 30 minutes on Cloudflare. One lookup made in the seconds before a
 *    new record propagates poisons every later attempt.
 *
 * 2. A PUBLIC resolver does the same. Measured here: seconds after the record
 *    was created, the authoritative servers had it and 8.8.8.8 had it, while
 *    1.1.1.1 returned NXDOMAIN. Worse, node's Resolver treats the first
 *    configured server's NXDOMAIN as a final answer and never falls through to
 *    the second, so listing both did not help at all.
 *
 * Authoritative servers cannot serve a stale answer about their own zone, so
 * they are the only source that answers the question being asked: has this
 * record been published yet.
 *
 * A correction to an earlier version of this comment, which blamed every
 * failure on something having looked the name up before it existed. That was
 * not what the measurements showed: a name that had never been queried
 * anywhere was still missing from 1.1.1.1 and from this machine's resolver
 * seconds after creation, while 8.8.8.8 and 9.9.9.9 had it. Some resolvers are
 * slower to see a new record, full stop. The suite therefore no longer relies
 * on the local resolver for anything - see helpers/https.js. Each server is queried SEPARATELY and
 * any one answering is enough - a single unhealthy nameserver must not fail
 * the run.
 */
async function authoritativeServers(hostname) {
  const zone = hostname.split('.').slice(-2).join('.');
  const resolver = new Resolver();
  resolver.setServers(['8.8.8.8', '1.1.1.1']);

  const names = await resolver.resolveNs(zone);
  const addresses = [];

  for (const name of names) {
    try {
      addresses.push(...(await resolver.resolve4(name)));
    } catch {
      // A nameserver we cannot resolve is one we simply do not ask.
    }
  }

  return addresses;
}

async function askOne(server, hostname) {
  const resolver = new Resolver({ timeout: 3000, tries: 1 });
  resolver.setServers([server]);

  return resolver.resolve4(hostname);
}

export async function waitForDns(hostname, { timeoutMs = 120_000, intervalMs = 3_000 } = {}) {
  let servers = [];
  try {
    servers = await authoritativeServers(hostname);
  } catch {
    // fall through to the public resolvers below
  }
  if (servers.length === 0) {
    servers = ['8.8.8.8', '1.1.1.1'];
  }

  const deadline = Date.now() + timeoutMs;
  let lastError = 'never queried';

  while (Date.now() < deadline) {
    // Every server independently; the first to answer wins.
    const answers = await Promise.allSettled(servers.map((s) => askOne(s, hostname)));

    for (const answer of answers) {
      if (answer.status === 'fulfilled' && answer.value.length > 0) {
        return answer.value;
      }
    }
    lastError = answers.map((a) => a.reason?.code || a.reason?.message || 'empty').join('/');

    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }

  throw new Error(
    `${hostname} did not resolve within ${timeoutMs}ms via ${servers.join(', ')} (last: ${lastError})`
  );
}

/**
 * Wait until the zone stops publishing a name. Asked of the authoritative
 * servers for the same reason as waitForDns: a recursive resolver's cache says
 * nothing about whether the record was actually withdrawn.
 */
export async function waitForDnsGone(hostname, { timeoutMs = 60_000, intervalMs = 3_000 } = {}) {
  let servers = [];
  try {
    servers = await authoritativeServers(hostname);
  } catch {
    // below
  }
  if (servers.length === 0) {
    throw new Error(`cannot find the authoritative servers for ${hostname}, so withdrawal cannot be checked`);
  }

  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const answers = await Promise.allSettled(servers.map((s) => askOne(s, hostname)));
    // Gone means EVERY authoritative server has stopped answering, and at
    // least one of them actually replied - all of them timing out is not
    // evidence of anything.
    const replied = answers.some((a) => a.status === 'fulfilled' || a.reason?.code === 'ENOTFOUND' || a.reason?.code === 'ENODATA');
    const anyStillPublishes = answers.some((a) => a.status === 'fulfilled' && a.value.length > 0);
    if (replied && !anyStillPublishes) {
      return;
    }
    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }

  throw new Error(`${hostname} is still published after ${timeoutMs}ms`);
}
