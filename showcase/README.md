# The showcase store

The demo shop on codeinchrome.com's home page: a small coffee roaster with a
cart, Stripe Checkout (test mode) and an admin panel whose public login is
read-only. It is written into a fresh codeinchrome site by the agent, through
the editor (`window.cic.write`), and recorded while it happens.

These files are the source of record. To rebuild the store on a site:

1. write each file below into the site at the same path (cic.write);
2. `cic.run('artisan', ['migrate', '--force'])`;
3. `cic.run('artisan', ['db:seed', '--class=ShopSeeder', '--force'], {confirm: true})`;
4. in the site's `.env`: `STRIPE_SECRET=sk_test_...` (test mode only - the
   store refuses a live key) and `SHOP_DEMO_PASSWORD=...`.

Nothing here needs a Composer package: Stripe is called over its HTTP API.
