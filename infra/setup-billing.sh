#!/usr/bin/env bash
#
# Connect the codeinchrome Lemon Squeezy store to the control plane.
#
#   infra/setup-billing.sh
#
# Run after the store, its subscription product and the webhook exist
# in the Lemon Squeezy dashboard (products cannot be created through the API),
# and after LEMONSQUEEZY_API_KEY and LEMONSQUEEZY_WEBHOOK_SECRET for THAT store
# are in the operator's .env. It:
#
#   - finds the store named "codeinchrome" and REFUSES any other - the same
#     account also holds another business's store
#   - finds Starter (the one paid plan) and checks it against
#     control/config/billing.php: a monthly subscription at the same price, so
#     a customer can never be charged something the app does not describe
#   - checks the webhook points at the control plane with every event the
#     app handles (app/Billing/WebhookHandler.php)
#   - writes LEMONSQUEEZY_STORE_ID and LS_VARIANT_* into .env
#
# Read-only against Lemon Squeezy. Deploy afterwards with deploy-control.sh.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

STORE_NAME=codeinchrome
WEBHOOK_URL=https://app.codeinchrome.com/webhooks/lemonsqueezy
EVENTS="subscription_created subscription_updated subscription_resumed subscription_unpaused subscription_paused subscription_cancelled subscription_expired"
# name:price-in-cents, from control/config/billing.php
PLANS="Starter:2000"

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

: "${LEMONSQUEEZY_WEBHOOK_SECRET:=}"
[[ -n ${LEMONSQUEEZY_API_KEY:-} ]] || die "LEMONSQUEEZY_API_KEY is not in .env"
[[ ${#LEMONSQUEEZY_WEBHOOK_SECRET} -ge 32 && ${#LEMONSQUEEZY_WEBHOOK_SECRET} -le 40 ]] || die "LEMONSQUEEZY_WEBHOOK_SECRET must be 32-40 characters (Lemon Squeezy allows at most 40)"

ls_get() { # path -> JSON on stdout
  curl -fsS -g "https://api.lemonsqueezy.com/v1/$1" \
    -H "Authorization: Bearer $LEMONSQUEEZY_API_KEY" -H "Accept: application/vnd.api+json"
}

# ── the store ────────────────────────────────────────────────────────────────
store_id=$(ls_get stores | STORE_NAME=$STORE_NAME python3 -c '
import json, os, sys
want = os.environ["STORE_NAME"].lower()
stores = json.load(sys.stdin)["data"]
match = [s for s in stores if s["attributes"]["name"].strip().lower() == want]
if len(match) != 1:
    names = ", ".join(s["attributes"]["name"] for s in stores) or "none"
    sys.exit(f"expected exactly one store named {want!r}; this key sees: {names}")
print(match[0]["id"])
') || die "store lookup failed (see above)"
ok "store $STORE_NAME is $store_id"

# ── the products ─────────────────────────────────────────────────────────────
declare -a env_lines=("LEMONSQUEEZY_STORE_ID=$store_id")
products=$(ls_get "products?filter[store_id]=$store_id&page[size]=100")
for plan in $PLANS; do
  name=${plan%%:*} cents=${plan##*:}
  product_id=$(NAME=$name python3 -c '
import json, os, sys
name = os.environ["NAME"]
m = [p for p in json.load(sys.stdin)["data"] if p["attributes"]["name"].strip().lower() == name.lower()]
if len(m) != 1: sys.exit(f"expected exactly one product named {name!r}, found {len(m)}")
if m[0]["attributes"]["status"] != "published": sys.exit(f"{name} is not published")
print(m[0]["id"])
' <<<"$products") || die "product $name"

  variant_id=$(ls_get "variants?filter[product_id]=$product_id" | NAME=$name CENTS=$cents python3 -c '
import json, os, sys
name, cents = os.environ["NAME"], int(os.environ["CENTS"])
vs = json.load(sys.stdin)["data"]
# A product with no extra variants has one default variant, reported as
# "pending"; with extra variants, the published ones are the real choices.
real = [v for v in vs if v["attributes"]["status"] == "published"] or vs
if len(real) != 1: sys.exit(f"{name} has {len(real)} variants; keep exactly one")
a = real[0]["attributes"]
if not a.get("is_subscription"): sys.exit(f"{name} is not a subscription")
if a.get("interval") != "month" or a.get("interval_count") != 1: sys.exit(f"{name} does not bill every 1 month")
price = a.get("price")
if price != cents: sys.exit(f"{name} costs {price} cents; the app says {cents}")
print(real[0]["id"])
') || die "variant for $name"
  ok "$name: product $product_id, variant $variant_id, \$$((cents / 100))/month"
  env_lines+=("LS_VARIANT_$(printf %s "$name" | tr a-z A-Z)=$variant_id")
done

# ── the webhook ──────────────────────────────────────────────────────────────
ls_get "webhooks?filter[store_id]=$store_id" | URL=$WEBHOOK_URL EVENTS=$EVENTS python3 -c '
import json, os, sys
url, need = os.environ["URL"], set(os.environ["EVENTS"].split())
hooks = [w for w in json.load(sys.stdin)["data"] if w["attributes"]["url"] == url]
if len(hooks) != 1: sys.exit(f"expected one webhook to {url}, found {len(hooks)}")
missing = need - set(hooks[0]["attributes"]["events"])
if missing: sys.exit("webhook is missing events: " + ", ".join(sorted(missing)))
' || die "webhook"
ok "webhook to $WEBHOOK_URL has every handled event"

# ── .env ─────────────────────────────────────────────────────────────────────
printf '%s\n' "${env_lines[@]}" | python3 -c '
import re, sys
lines = [l.strip() for l in sys.stdin if l.strip()]
env = open(".env").read()
for line in lines:
    key = line.split("=", 1)[0]
    pat = re.compile(rf"^{key}=.*$", re.M)
    env = pat.sub(line, env) if pat.search(env) else env.rstrip("\n") + "\n" + line + "\n"
open(".env", "w").write(env)
'
ok "wrote ${env_lines[*]%%=*} to .env"
echo "billing connected; deploy with infra/deploy-control.sh"
