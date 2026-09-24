# Billing (Lemon Squeezy)

## What the API can and cannot do

Verified against the live API on 2026-09-22, not read from documentation:

| Operation | Endpoint | Result |
|---|---|---|
| Identify the account | `GET /v1/users/me` | works |
| Read the store | `GET /v1/stores/{id}` | works |
| List products | `GET /v1/products` | works (returns 0) |
| **Create a product** | `POST /v1/products` | **405 Method Not Allowed** |
| Create a checkout | `POST /v1/checkouts` | supported, needs a variant id |
| Create a webhook | `POST /v1/webhooks` | supported, needs a public URL |

**Products and variants cannot be created programmatically.** They exist only
through the Lemon Squeezy dashboard. So the plan catalog below is written as
configuration with the variant id left blank, and everything downstream of a
variant id - checkout creation, webhook handling, subscription state - is built
and tested without one.

## Two decisions that need a human

1. **Which store.** Decided: codeinchrome has its own Lemon Squeezy store,
   priced in USD (`LEMONSQUEEZY_STORE_ID` in `.env`), separate from any other
   store on the same account.

2. **Who creates the products.** They cannot be made over the API, so someone
   has to make them in the dashboard. Once they exist, put each variant id into
   `config/billing.php` and nothing else changes.

## The plan catalog

Prices are USD and deliberately below Lovable and Replit for the same work,
because the cost base is different: sites are packed onto shared hosts rather
than given a VM each.

| Plan | Price | Sites | CPU | Memory | Disk |
|---|---|---|---|---|---|
| Free | $0 | 1 | 0.25 | 256m | 1 GB |
| Starter | $12/mo | 3 | 0.5 | 512m | 5 GB |
| Pro | $29/mo | 10 | 1.0 | 1024m | 20 GB |
| Studio | $79/mo | 40 | 2.0 | 2048m | 100 GB |

Every plan gets a free `*.codeinchrome.com` subdomain and automatic TLS.
Custom domains are on Starter and above.

## The API key

`LEMONSQUEEZY_API_KEY` in `.env` is unscoped and does not expire until 2109.
**It must be rotated and scoped before this repository is made public**, and it
must never be committed - `.env` is gitignored and this file names no secret.
