#!/usr/bin/env bash
#
# Outgoing mail for the control plane, with no third-party provider.
#
#   infra/setup-mail.sh
#
# Idempotent. Postfix on the control host, SEND-ONLY and bound to loopback
# (it accepts mail from this machine's application and from nothing else - it
# is not an open relay, because nothing outside can connect to it at all),
# with every message DKIM-signed by OpenDKIM. The zone gets:
#
#   SPF    codeinchrome.com          v=spf1 ip4:<control ip> include:<Cloudflare routing> -all
#   DKIM   <selector>._domainkey     the public key
#   DMARC  _dmarc                    p=quarantine, strict alignment
#
# so receiving servers can verify that mail from codeinchrome.com really came
# from here, and treat anything else claiming the name as forged.
#
# Customer containers can NOT use this: SMTP egress from containers is
# rejected at every host (bootstrap.sh), and Postfix listens on the control
# host's loopback only.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
. infra/hosts.env
set -a; . ./.env; set +a

ip=${CIC_CONTROL_HOST##*:}
host_name=${CIC_CONTROL_HOST%%:*}
zone=${CLOUDFLARE_ZONE_NAME:-codeinchrome.com}
selector=${CIC_DKIM_SELECTOR:-cic2026}
helo="${host_name}.${zone}"

for forbidden in $CIC_FORBIDDEN_HOSTS; do
  [[ "$ip" == "$forbidden" ]] && { echo "REFUSING: production host" >&2; exit 1; }
done

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
control() { ssh -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "root@$ip" "$@"; }

cf_upsert_txt() { # name content
  # Replaces the TXT record at `name` of the same KIND (v=spf1, v=DKIM1,
  # v=DMARC1) and nothing else: the apex also carries other TXT records
  # (domain verifications), and the first version overwrote whichever came first.
  local name=$1 content=$2 id
  id=$(curl -fsS -g "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records?type=TXT&name=$name" \
        -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" | KIND="${content%%;*}" python3 -c '
import json, os, sys
kind = os.environ["KIND"].split()[0]
r = [x for x in json.load(sys.stdin)["result"] if x["content"].strip("\"").startswith(kind)]
print(r[0]["id"] if r else "")')
  local body
  body=$(python3 -c 'import json,sys; print(json.dumps({"type":"TXT","name":sys.argv[1],"content":sys.argv[2],"ttl":300}))' "$name" "$content")
  if [[ -n $id ]]; then
    curl -fsS -X PUT "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records/$id" \
      -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" -d "$body" >/dev/null
  else
    curl -fsS -X POST "https://api.cloudflare.com/client/v4/zones/$CLOUDFLARE_ZONE_ID/dns_records" \
      -H "Authorization: Bearer $CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" -d "$body" >/dev/null
  fi
}

# ── Postfix + OpenDKIM on the control host ───────────────────────────────────
control "ZONE=$zone SELECTOR=$selector HELO=$helo bash -s" <<'REMOTE'
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
echo "postfix postfix/main_mailer_type select Internet Site" | debconf-set-selections
echo "postfix postfix/mailname string $ZONE" | debconf-set-selections
apt-get install -y -qq postfix opendkim opendkim-tools >/dev/null

# The DKIM private key: generated once, never leaves this host.
install -d -m 0750 -o opendkim -g opendkim /etc/opendkim/keys
if [[ ! -f /etc/opendkim/keys/$SELECTOR.private ]]; then
  opendkim-genkey -b 2048 -d "$ZONE" -s "$SELECTOR" -D /etc/opendkim/keys
  chown opendkim:opendkim /etc/opendkim/keys/"$SELECTOR".*
  chmod 0600 /etc/opendkim/keys/"$SELECTOR".private
fi

cat > /etc/opendkim.conf <<CONF
# Managed by codeinchrome infra/setup-mail.sh
Syslog          yes
SyslogSuccess   yes
UMask          007
Mode            s
Domain          $ZONE
Selector        $SELECTOR
KeyFile         /etc/opendkim/keys/$SELECTOR.private
Canonicalization relaxed/simple
OversignHeaders From
Socket          inet:8891@127.0.0.1
PidFile         /run/opendkim/opendkim.pid
UserID          opendkim
CONF
systemctl enable --now opendkim >/dev/null 2>&1
systemctl restart opendkim

# Send-only: loopback in, nothing accepted for local delivery, every message
# through the DKIM milter, TLS to receiving servers whenever they offer it.
# No TLS on the INBOUND side: it only ever hears from this machine over
# loopback, and offering STARTTLS with the default self-signed certificate
# made the application's mailer refuse to send at all.
postconf -e \
  "myhostname = $HELO" \
  "myorigin = $ZONE" \
  "mydestination =" \
  "inet_interfaces = loopback-only" \
  "inet_protocols = ipv4" \
  "mynetworks = 127.0.0.0/8" \
  "relayhost =" \
  "smtp_tls_security_level = may" \
  "smtpd_tls_security_level = none" \
  "smtp_tls_loglevel = 1" \
  "smtpd_milters = inet:127.0.0.1:8891" \
  "non_smtpd_milters = inet:127.0.0.1:8891" \
  "milter_default_action = tempfail" \
  "disable_vrfy_command = yes" \
  "message_size_limit = 10240000"

# Reserved names (RFC 2606 / 6761) can never receive mail. The e2e suite signs
# up @codeinchrome.test accounts; without this each signup sent a confirmation
# that bounced, and the bounce then bounced too. Discarded here instead.
cat > /etc/postfix/transport <<'MAP'
# Managed by codeinchrome infra/setup-mail.sh
.test       discard:reserved test domain
.invalid    discard:reserved invalid domain
.example    discard:reserved example domain
.localhost  discard:reserved localhost domain
MAP
postmap /etc/postfix/transport
postconf -e "transport_maps = hash:/etc/postfix/transport"
systemctl enable --now postfix >/dev/null 2>&1
systemctl restart postfix
REMOTE
ok "Postfix (send-only, loopback) and OpenDKIM on the control host"

# ── the records that let receivers verify the mail ───────────────────────────
dkim_txt=$(control "cat /etc/opendkim/keys/$selector.txt" | tr -d '\n' | sed -E 's/^[^(]*\(//; s/\).*$//; s/"[[:space:]]*"//g; s/"//g; s/[[:space:]]+/ /g; s/^ //; s/ $//')
[[ $dkim_txt == v=DKIM1* ]] || die "could not read the DKIM public key (got: ${dkim_txt:0:60})"
# include:_spf.mx.cloudflare.net - Cloudflare Email Routing forwards mail
# for support@ to the operators (enabled in the dashboard; it owns the MX and
# cf2024-1 DKIM records). A domain may have only ONE SPF record, so its entry
# lives here rather than as the second record the dashboard offers to add.
cf_upsert_txt "$zone"                      "v=spf1 ip4:$ip include:_spf.mx.cloudflare.net -all"
cf_upsert_txt "$selector._domainkey.$zone" "$dkim_txt"
cf_upsert_txt "_dmarc.$zone"               "v=DMARC1; p=quarantine; adkim=s; aspf=s"
ok "SPF, DKIM ($selector) and DMARC published"

# The application's settings, read by deploy-control.sh from the local .env.
if ! grep -q '^MAIL_MAILER=' .env; then
  printf '\n# Outgoing mail: the control host'"'"'s own send-only Postfix (infra/setup-mail.sh).\nMAIL_MAILER=smtp\nMAIL_HOST=127.0.0.1\nMAIL_PORT=25\nMAIL_SCHEME=smtp\nMAIL_FROM_ADDRESS=no-reply@%s\nMAIL_FROM_NAME=codeinchrome\n' "$zone" >> .env
fi
ok "application mail settings in the operator's .env"

# ── verify ───────────────────────────────────────────────────────────────────
fails=0
check() { if eval "$2" >/dev/null 2>&1; then ok "$1"; else printf '\033[33m  !!\033[0m %s\n' "$1"; fails=$((fails+1)); fi; }
check "postfix listens on loopback only" "control 'ss -Hltn | awk \"{print \\\$4}\" | grep -q \"^127.0.0.1:25\$\"' && ! control 'ss -Hltn | awk \"{print \\\$4}\" | grep -Eq \"^(0.0.0.0|\\\\*|\\\\[::\\\\]):25\$\"'"
check "not reachable from the internet" "! nc -z -G 5 $ip 25"
# Polled: the record takes a few seconds to reach the nameservers, and the
# first version checked immediately and failed a correct setup.
dkim_ok() { for _ in $(seq 1 20); do control "opendkim-testkey -d $zone -s $selector -vvv 2>&1 | grep -q 'key OK'" && return 0; sleep 3; done; return 1; }
check "DKIM key matches the published record" dkim_ok
check "exactly one SPF record" "[ \$(dig +short TXT $zone @\$(dig +short NS $zone | head -1) | grep -c 'v=spf1') = 1 ]"
check "inbound mail is routed (Cloudflare MX)" "dig +short MX $zone @\$(dig +short NS $zone | head -1) | grep -q mx.cloudflare.net"
check "reserved test domains are discarded, not sent" "control 'postmap -q .test hash:/etc/postfix/transport' | grep -q '^discard:'"
(( fails )) && die "$fails check(s) failed"
printf '\033[32mmail ready\033[0m  from no-reply@%s via %s\n' "$zone" "$helo"
