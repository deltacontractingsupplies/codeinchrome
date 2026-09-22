# Security

## Reporting

Email **security@codeinchrome.com** rather than opening an issue. Please include
what you did, what happened, and what you expected. We will confirm receipt
within 72 hours.

Please do not test against other customers' sites. If you want a target, create
an account — the free plan provisions a real, isolated site in a few seconds,
and you are welcome to attack your own.

## What is in scope

- Reaching another tenant's files, network, processes or database
- Serving anything outside a site's `public/` directory
- Escaping a customer container, or reaching the host from one
- Reaching the host agent (`127.0.0.1:9440`) or the Caddy admin API from a
  container or from the internet
- Forging or replaying a billing webhook
- Acting on a site you do not own through the control plane

## What is already known and deliberate

- **Host IP addresses are public.** Every site resolves directly to its host;
  there is no proxy in front, because the certificate is issued to the host so
  the TLS is genuinely the customer's own. Hosts are firewalled to 22, 80 and
  443.
- **Customers get no shell and no root.** This is a product decision, not an
  oversight. It removes mining, spam relaying and outbound attacks as a class
  of problem rather than policing them after the fact.
- **A `past_due` subscription keeps serving.** A failed card is a billing
  problem, and taking a customer offline while the processor is still retrying
  loses the customer as well as the payment.
- **A plan downgrade never deletes sites.** Destroying a customer's work on a
  payment event is irreversible and is not a decision a webhook gets to make.

## How the boundaries are verified

`infra/verify-isolation.sh` runs against a live host and proves, per claim,
that one tenant cannot reach another's files, network, processes, logs, vhost,
the docker socket, the agent or the Caddy admin API, and that SMTP and mining
egress are refused.

It carries **positive controls**. An earlier version reported fifteen confident
DENIEDs against a container that did not exist, because `docker exec` fails the
same way whether the boundary held or the subject was absent. It now proves the
container is alive, can read its own files and has working egress before any
DENIED is allowed to count.
