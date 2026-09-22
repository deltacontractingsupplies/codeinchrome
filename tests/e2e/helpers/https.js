import https from 'node:https';

/**
 * GET over HTTPS to a specific address, with the real hostname for SNI and
 * certificate verification.
 *
 * Why not the operating system's resolver: measured on the machine this suite
 * was written on, a record created seconds earlier was answered by the
 * authoritative servers, 8.8.8.8 and 9.9.9.9, while 1.1.1.1 and the local
 * (carrier, via a phone hotspot) resolver still returned nothing. The name had
 * never been queried anywhere, so this was not a stale negative answer - some
 * resolvers are simply slower to see a new record.
 *
 * That is a property of whichever resolver the test machine happens to use,
 * not of this system. The test's job is to prove the SITE works: that the host
 * serves it, with a valid certificate for its name, and leaks nothing. So it
 * connects to the address the zone publishes, and verifies TLS against the
 * hostname exactly as a browser would. Certificate validation is NOT relaxed.
 */
export function httpsGet(hostname, path, address, { timeoutMs = 20_000 } = {}) {
  return new Promise((resolve) => {
    const request = https.request(
      {
        hostname,
        servername: hostname, // SNI: without it the host cannot pick the certificate
        path,
        method: 'GET',
        timeout: timeoutMs,
        // Pin the connection to the published address; everything else,
        // including certificate verification, is standard.
        lookup: (_host, options, callback) => {
          if (options && options.all) {
            callback(null, [{ address, family: 4 }]);
          } else {
            callback(null, address, 4);
          }
        },
        headers: { 'User-Agent': 'codeinchrome-e2e' },
      },
      (response) => {
        let body = '';
        response.setEncoding('utf8');
        response.on('data', (chunk) => {
          if (body.length < 2_000_000) body += chunk;
        });
        response.on('end', () => resolve({ status: response.statusCode, body }));
      },
    );

    // Any failure - refused, reset, TLS not ready yet - is status 0, so a
    // poller can keep polling. The reason is kept for the failure message.
    request.on('timeout', () => request.destroy(new Error('timeout')));
    request.on('error', (error) => resolve({ status: 0, body: '', error: error.code || error.message }));
    request.end();
  });
}
