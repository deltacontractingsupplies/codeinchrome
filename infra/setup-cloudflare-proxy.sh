#!/usr/bin/env bash
#
# Put the platform behind Cloudflare's proxy.
#
#   infra/setup-cloudflare-proxy.sh            certificate + zone settings
#   infra/setup-cloudflare-proxy.sh --dns      ...then proxy the records
#
# Why: every site under codeinchrome.com used to get its own Let's Encrypt
# certificate, and Let's Encrypt allows 50 a week per registered domain - the
# fleet hit it, and the 51st new site of a week had no HTTPS. Behind the
# proxy, Cloudflare terminates visitors' TLS (its Universal SSL covers
# *.codeinchrome.com) and reaches the hosts with ONE Cloudflare Origin CA
# wildcard: no ACME order per site, no rate limit. The proxy also hides the
# hosts' addresses from visitors and absorbs floods before they reach them.
#
# Idempotent. Order matters and is enforced here: every host must hold the
# origin certificate (and run an agent that uses it: deploy-host.sh) BEFORE
# --dns sends visitors through Cloudflare in Full (strict) mode.
#
# The origin private key is generated ON the control host and copied host to
# host through ssh pipes; it is never written on the operator's machine.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env
eval "$(grep -E '^CLOUDFLARE_(API_TOKEN|ZONE_ID|ZONE_NAME)=' .env | sed 's/^/export /')"
zone=${CLOUDFLARE_ZONE_NAME:-codeinchrome.com}
control_ip=${CIC_CONTROL_HOST##*:}

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
cf()  { # method path [json]
  local args=(-fsS -X "$1" "https://api.cloudflare.com/client/v4$2" -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json")
  [[ -n ${3:-} ]] && args+=(-d "$3")
  curl "${args[@]}"
}
on()  { local ip=$1; shift; ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  for h in $CIC_HOSTS $CIC_CONTROL_HOST; do
    [[ ${h##*:} == "$forbidden" ]] && die "REFUSING: $forbidden is the production host"
  done
done

# ── the origin certificate, on the control host ──────────────────────────────
# 15 years (Cloudflare's maximum), so renewal is not a recurring operation.
# Reissued only when missing or within 90 days of expiry.
# The token is read from the control host's own .env, not passed on the
# command line, where it would show in `ps`.
on "$control_ip" "ZONE=$zone bash -s" <<'REMOTE'
set -Eeuo pipefail
CF_TOKEN=$(grep '^CLOUDFLARE_API_TOKEN=' /srv/control/.env | cut -d= -f2-)
d=/opt/codeinchrome/tls
install -d -m 0700 "$d"
if [[ -s $d/origin.crt ]] && openssl x509 -checkend $((90*86400)) -noout -in "$d/origin.crt" >/dev/null; then
  exit 0
fi
umask 077
openssl ecparam -name prime256v1 -genkey -noout -out "$d/origin.key.new"
openssl req -new -key "$d/origin.key.new" -subj "/CN=$ZONE" -out "$d/origin.csr"
body=$(python3 -c 'import json,sys; print(json.dumps({"hostnames":[sys.argv[1],"*."+sys.argv[1]],"requested_validity":5475,"request_type":"origin-ecc","csr":open(sys.argv[2]).read()}))' "$ZONE" "$d/origin.csr")
curl -fsS -X POST https://api.cloudflare.com/client/v4/certificates -H "Authorization: Bearer $CF_TOKEN" \
  -H "Content-Type: application/json" -d "$body" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d["success"], d["errors"]; print(d["result"]["certificate"])' > "$d/origin.crt.new"
mv "$d/origin.key.new" "$d/origin.key"; mv "$d/origin.crt.new" "$d/origin.crt"; rm -f "$d/origin.csr"
REMOTE
expiry=$(on "$control_ip" 'openssl x509 -enddate -noout -in /opt/codeinchrome/tls/origin.crt' | cut -d= -f2)
ok "origin certificate for $zone and *.$zone (until $expiry)"

# ── onto every host, readable by caddy only ──────────────────────────────────
install_on() { # ip
  on "$control_ip" 'cat /opt/codeinchrome/tls/origin.crt' | on "$1" 'install -d -m 0750 -o root -g caddy /etc/caddy/origin && umask 027 && cat > /etc/caddy/origin/cert.pem.new'
  on "$control_ip" 'cat /opt/codeinchrome/tls/origin.key' | on "$1" 'umask 077 && cat > /etc/caddy/origin/key.pem.new'
  on "$1" 'cd /etc/caddy/origin && chown root:caddy cert.pem.new key.pem.new && chmod 0640 cert.pem.new key.pem.new \
    && openssl x509 -noout -in cert.pem.new && mv cert.pem.new cert.pem && mv key.pem.new key.pem'
}
for h in $CIC_HOSTS $CIC_CONTROL_HOST; do
  install_on "${h##*:}"
  on "${h##*:}" '[ "$(openssl x509 -noout -pubkey -in /etc/caddy/origin/cert.pem | sha256sum)" = "$(openssl pkey -pubout -in /etc/caddy/origin/key.pem | sha256sum)" ] && [ "$(stat -c %a:%U:%G /etc/caddy/origin/key.pem)" = 640:root:caddy ]' \
    || die "${h%%:*}: certificate and key do not match, or the key is readable by more than caddy"
  ok "${h%%:*}: origin certificate installed (key root:caddy 0640)"
done

# ── zone settings ────────────────────────────────────────────────────────────
# strict: Cloudflare verifies the origin certificate, so nothing between it
# and the host can present another. always_use_https and TLS 1.2+ at the edge.
setting() { cf PATCH "/zones/$CLOUDFLARE_ZONE_ID/settings/$1" "{\"value\":\"$2\"}" | python3 -c 'import json,sys; assert json.load(sys.stdin)["success"]'; }
setting ssl strict
setting always_use_https on
setting min_tls_version 1.2
# Off: Cloudflare hides email addresses behind an inline decoder script, which
# our Content-Security-Policy (rightly) blocks - so the support address and
# the showcase login read "[email protected]" to every visitor.
setting email_obfuscation off
setting tls_1_3 on
ok "zone: SSL Full (strict), HTTPS always, TLS 1.2 minimum, TLS 1.3 on"

[[ ${1:-} == --dns ]] || { echo "certificate and settings ready; run deploy-host.sh / deploy-control.sh, then: $0 --dns"; exit 0; }

# ── proxy the records ────────────────────────────────────────────────────────
# Every direct subdomain that serves HTTP: customer sites, app, the apex and
# www, and the hosts' own names (2026-09-25: nothing connects to a host by
# name - SSH and the agent tunnels use addresses - and a DNS-only name
# published the host's IP). NOT backups: restic uploads are larger than the
# proxy accepts, so it stays DNS-only.
keep_direct=" backups.$zone "
records=$(cf GET "/zones/$CLOUDFLARE_ZONE_ID/dns_records?type=A&per_page=500")
flipped=0
while IFS=$'\t' read -r id name proxied; do
  [[ $keep_direct == *" $name "* ]] && continue
  label=${name%".$zone"}
  [[ $name == "$zone" || ( $label != "$name" && $label != *.* ) ]] || continue
  [[ $proxied == True ]] && continue
  cf PATCH "/zones/$CLOUDFLARE_ZONE_ID/dns_records/$id" '{"proxied":true}' >/dev/null
  flipped=$((flipped+1))
done < <(python3 -c 'import json,sys; [print(r["id"], r["name"], r["proxied"], sep="\t") for r in json.load(sys.stdin)["result"]]' <<<"$records")
ok "proxied $flipped record(s); only backups stays DNS-only"
