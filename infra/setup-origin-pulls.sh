#!/usr/bin/env bash
#
# Authenticated Origin Pulls for the zone (audit A33): Cloudflare presents
# its origin-pull client certificate on every request to our origins, and
# the edges (deploy-host.sh, deploy-control.sh) require it once they see
# the setting ON.
#
#   infra/setup-origin-pulls.sh          show the setting
#
#   To turn it on - in this order, so no request is ever refused:
#     1. infra/setup-origin-pulls.sh on        Cloudflare starts sending it
#     2. re-deploy every host and the control plane: they start requiring it
#
#   To turn it off - the REVERSE order, for the same reason:
#     1. CIC_ORIGIN_PULLS=off infra/deploy-host.sh ... (every host), and
#        CIC_ORIGIN_PULLS=off infra/deploy-control.sh: the edges stop requiring it
#     2. infra/setup-origin-pulls.sh off
#   (Off first would leave every edge requiring a certificate Cloudflare
#   no longer sends: every visitor refused until the re-deploys finish.)

set -Eeuo pipefail
cd "$(dirname "$0")/.."
eval "$(grep -E '^CLOUDFLARE_(API_TOKEN|ZONE_ID)=' .env | sed 's/^/export /')"
api="https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/settings/tls_client_auth"
auth=(-H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json")
case ${1:-} in
  on|off) curl -fsS -X PATCH "${auth[@]}" --data "{\"value\":\"$1\"}" "$api" >/dev/null ;;
  "") ;;
  *) echo "usage: $0 [on|off]" >&2; exit 2 ;;
esac
curl -fsS "${auth[@]}" "$api" | python3 -c 'import json,sys; print("tls_client_auth:", json.load(sys.stdin)["result"]["value"])'
