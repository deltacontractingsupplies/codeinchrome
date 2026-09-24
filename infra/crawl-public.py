#!/usr/bin/env python3
"""
Crawl the platform's public pages, signed out, and report every page that
does not answer: any 5xx, and any 4xx reached from one of our own links.

    python3 infra/crawl-public.py [https://app.codeinchrome.com] [--max 600]

Signed in: CRAWL_COOKIE='name=value' in the environment (never an argument,
so it does not show in the process list) - e.g. a demo site's session from
the platform's login-cookie, which needs no password.

Follows links on the same host only, GET only (a crawler never submits a
form or signs out). The demo code pages are rate-limited per visitor
(throttle:demo-code, 120 a minute), so they are paced to stay under it -
a 429 here would be the crawler's fault, not the site's. Writes nothing.
"""
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque

base = next((a for a in sys.argv[1:] if a.startswith('http')), 'https://app.codeinchrome.com').rstrip('/')
limit = int(sys.argv[sys.argv.index('--max') + 1]) if '--max' in sys.argv else 600
host = urllib.parse.urlparse(base).netloc
HEADERS = {'User-Agent': 'codeinchrome-crawl-public'}
if os.environ.get('CRAWL_COOKIE'):
    HEADERS['Cookie'] = os.environ['CRAWL_COOKIE']
SKIP = re.compile(r'^/(logout|auth/.+/redirect|billing/checkout|up$)|\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|pdf|zip)(\?|$)', re.I)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(NoRedirect)
queue, seen, bad = deque(['/']), {'/'}, []
checked = 0
last_demo = 0.0

while queue and checked < limit:
    path = queue.popleft()
    if '/demos/' in path:
        wait = 0.55 - (time.time() - last_demo)
        if wait > 0:
            time.sleep(wait)
        last_demo = time.time()
    req = urllib.request.Request(base + path, headers=HEADERS)
    try:
        resp = opener.open(req, timeout=30)
        status, body, location = resp.status, resp.read().decode('utf-8', 'replace'), None
    except urllib.error.HTTPError as e:
        status, body, location = e.code, e.read().decode('utf-8', 'replace'), e.headers.get('Location')
    except Exception as e:  # noqa: BLE001 - any failure to answer is reported
        bad.append(f'no answer {path} ({e})')
        checked += 1
        continue
    checked += 1
    if status >= 400:
        bad.append(f'{status} {path}')
    links = [location] if location else re.findall(r'''href\s*=\s*["']([^"'#]+)["']''', body)
    for href in links:
        u = urllib.parse.urlparse(urllib.parse.urljoin(base + path, href.replace('&amp;', '&')))
        if u.netloc != host:
            continue
        nxt = u.path + (f'?{u.query}' if u.query else '')
        if nxt not in seen and not SKIP.search(nxt):
            seen.add(nxt)
            queue.append(nxt)

print(f'{checked} pages checked, {len(queue)} not reached (limit {limit})')
for line in bad:
    print('  ' + line)
print('every page answered' if not bad else f'{len(bad)} page(s) failed')
sys.exit(1 if bad else 0)
