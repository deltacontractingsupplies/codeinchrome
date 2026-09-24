#!/usr/bin/env bash
#
# The publish checks (infra/publish-repo.sh), over only the commits about to
# leave this machine:
#
#   infra/check-outgoing.sh public/main..HEAD
#   infra/check-outgoing.sh <sha> --not --remotes=public
#
# Arguments are git rev-list arguments. Run by infra/hooks/pre-push on every
# push to GitHub, so nothing reaches the public repository unchecked:
#
#   - no value from .env or control/.env (except PUBLIC_KEYS)
#   - no private key block
#   - nothing matching infra/publish-deny.local, and no address from
#     infra/hosts.local.env - in the code, messages, notes or author lines
#   - gitleaks finds nothing
#   - no binary artifact (zip, webm, har, sqlite, keys)
#
# Fails closed: without the local rule files there is nothing to check
# against, and that is a refusal, not a pass.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
root=$PWD
(( $# > 0 )) || { echo "usage: $0 <rev-list arguments>" >&2; exit 2; }

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

# The same list as infra/publish-repo.sh.
PUBLIC_KEYS='^(APP_NAME|APP_ENV|APP_URL|APP_DEBUG|CLOUDFLARE_ZONE_NAME|MAIL_(MAILER|HOST|PORT|SCHEME|FROM_ADDRESS|FROM_NAME)|LOG_.*|DB_CONNECTION|SESSION_.*|CACHE_STORE|QUEUE_CONNECTION|APPLE_CLIENT_ID|SHOWCASE_(URL|ADMIN_URL|ADMIN_EMAIL|FLOWERS_URL|FLOWERS_ADMIN_URL|FLOWERS_ADMIN_EMAIL|RECORDING))$'

deny_rules=$root/infra/publish-deny.local
hosts=$root/infra/hosts.local.env
[[ -f $deny_rules ]] || die "infra/publish-deny.local is missing - nothing to check against, so nothing is pushed"
[[ -f $hosts ]] || die "infra/hosts.local.env is missing - its addresses must be checked, so nothing is pushed"
command -v gitleaks >/dev/null || die "gitleaks is not installed (brew install gitleaks)"

commits=$(git rev-list "$@")
if [[ -z $commits ]]; then
  ok "no new commits to check"
  exit 0
fi
count=$(wc -l <<<"$commits" | tr -d ' ')

# Kept beside the repository (not in $TMPDIR, which is the internal disk) and
# removed however the script ends: the deny list is what it matches against.
work=$(mktemp -d "$root/.publish-check.XXXX")
trap 'rm -rf "$work"' EXIT
text=$work/text
deny=$work/deny
git log -p --no-color --no-walk=unsorted --format='commit %H%nauthor %an <%ae>%ncommitter %cn <%ce>%n%B' $commits > "$text"
# Notes attached to these commits.
for c in $commits; do git notes show "$c" 2>/dev/null || true; done >> "$text"

leaks=0
for envfile in "$root/.env" "$root/control/.env"; do
  [[ -f $envfile ]] || continue
  while IFS= read -r line; do
    [[ -z $line || $line == \#* || $line != *=* ]] && continue
    k=${line%%=*}; v=${line#*=}; v=${v%\"}; v=${v#\"}
    [[ $k =~ $PUBLIC_KEYS || ${#v} -lt 8 ]] && continue
    # Loopback is Laravel's default for REDIS_HOST, MEMCACHED_HOST and more:
    # an address every machine has, never a secret (and in the agent's code).
    [[ $v =~ ^(127\.0\.0\.1|localhost|::1)$ ]] && continue
    if grep -qF -- "$v" "$text"; then echo "  the value of $k (${envfile#"$root"/}) is in an outgoing commit" >&2; leaks=$((leaks+1)); fi
  done < "$envfile"
done
(( leaks == 0 )) || die "$leaks secret value(s) from .env in the outgoing commits"
ok ".env: no secret value in $count outgoing commit(s)"

grep -qE -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' "$text" && die "a private key block is in an outgoing commit"
ok "no private key"

grep -vE '^\s*(#|$)' "$deny_rules" > "$deny" || true
grep -E '^CIC_(HOSTS|CONTROL_HOST|FORBIDDEN_HOSTS)=' "$hosts" | grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | sort -u | sed 's/\./\\./g' >> "$deny"
[[ -s $deny ]] || die "the deny list is empty - refusing rather than checking nothing"
hits=0
while IFS= read -r re; do
  if grep -qE -- "$re" "$text"; then
    # Where, not what: the pattern is private too.
    awk -v re="$re" '/^(diff --git|commit )/ {where=$0} $0 ~ re {print "  denied pattern, first in: " where; exit}' "$text" >&2
    hits=$((hits+1))
  fi
done < "$deny"
(( hits == 0 )) || die "$hits denied pattern(s) (infra/publish-deny.local, infra/hosts.local.env) in the outgoing commits"
ok "nothing denied (other businesses, server addresses) in code, messages, notes or authors"

oldest=$(tail -1 <<<"$commits")
if git rev-parse -q --verify "$oldest^" >/dev/null; then range="$oldest^..$(head -1 <<<"$commits")"; else range=$(head -1 <<<"$commits"); fi
gitleaks git --no-banner --redact --exit-code 1 --log-opts="$range" . >/dev/null 2>&1 \
  || { gitleaks git --no-banner --redact --log-opts="$range" . 2>&1 | tail -20 >&2; die "gitleaks found a secret"; }
ok "gitleaks: nothing"

artifacts=$(git log --no-walk=unsorted --format= --name-only $commits | sort -u | grep -iE '\.(zip|webm|har|sqlite|sqlite3|db|pem|key|p12)$' || true)
[[ -z $artifacts ]] || die "binary artifacts in the outgoing commits: $artifacts"
ok "no binary artifacts"
