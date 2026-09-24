# Security policy

codeinchrome hosts other people's applications, so a vulnerability here can
reach their data. We take every report seriously.

## Reporting a vulnerability

**Do not open a public issue.** Report it privately:

- through GitHub: **Security → Report a vulnerability** on this repository
  (private vulnerability reporting), or
- by email to the address in <https://app.codeinchrome.com/.well-known/security.txt>.

Please include what you found, how to reproduce it, and what an attacker could
do with it. We will acknowledge your report within 3 working days and keep you
informed until it is fixed. We will not take legal action against research done
in good faith that respects these rules:

- test only against your own account and your own sites;
- never access, change or keep another customer's data - stop and report as
  soon as you see any;
- no denial of service, spam, social engineering or physical attacks.

## Scope

In scope: the control plane (`control/`), the host agent (`agent/`), the site
container image (`infra/images/`), the deploy and backup scripts (`infra/`),
and the live service at `*.codeinchrome.com`.

Of particular interest: one tenant reading or changing another tenant's files,
database, logs or `.env`; anything served from outside a site's `public/`
directory; escaping a site container; secrets in logs or responses.
