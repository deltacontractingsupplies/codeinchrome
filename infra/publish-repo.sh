#!/usr/bin/env bash
#
# Push this repository to GitHub - from a CLEANED COPY, never from here.
#
#   infra/publish-repo.sh                              build the clean copy and scan it
#   infra/publish-repo.sh --private OWNER/NAME         ...then create a PRIVATE repo and push
#   infra/publish-repo.sh --public  OWNER/NAME         ...then create a PUBLIC repo and push
#
# The owner chose private (2026-09-23). The same cleaning and scan run either
# way: a private repository is one sharing setting away from public.
#
# This working repository is never modified. A fresh clone is made under
# .publish/, and in that clone the history is rewritten to drop paths that
# should never have been committed:
#
#   tests/e2e/report/   Playwright's generated report. Committed before it was
#                       gitignored; its traces record requests, cookies and the
#                       throwaway passwords of e2e accounts.
#
# Then the rewritten history is scanned, and publishing is REFUSED if any of
# these appear in any commit:
#
#   - any value from the operator's .env (API keys, tokens, passwords), except
#     the few settings that are public by design (PUBLIC_KEYS below)
#   - a private key block
#   - a binary artifact (zip, webm, har, sqlite) anywhere in history
#   - anything matching infra/publish-deny.local (NOT committed): addresses
#     and names of other businesses, personal details - checked in the code,
#     the commit messages, the notes AND the author lines of every commit
#
# Before the scan, infra/publish-scrub.local.pl (NOT committed: its rules name
# what they remove) rewrites every text file of every commit, every commit
# message and every note. Both local files are REQUIRED for --public.
#
#   --author "Name <email>"   publish every commit under this author and
#                             committer (e.g. a GitHub no-reply address), so
#                             a personal address is not published
#
# Rotate the Lemon Squeezy key before publishing anyway: it has lived on a
# laptop, and a published repository is forever.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
root=$PWD
work=$root/.publish
target=""
visibility=""

author=""
if [[ ${1:-} == --author ]]; then author=${2:?usage: --author "Name <email>"}; shift 2; fi
case "${1:-}" in
  "") ;;
  --private|--public) visibility=${1#--}; target=${2:?usage: $1 OWNER/NAME} ;;
  *) echo "usage: $0 [--private|--public OWNER/NAME]" >&2; exit 2 ;;
esac

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

REMOVE_PATHS=(tests/e2e/report)
# .env settings that are public by design and may appear in the code.
# APPLE_CLIENT_ID is the Sign in with Apple Services ID, sent in the browser's
# address bar on every sign-in; the SHOWCASE_* addresses and the demo admin's
# email are printed on the home page. The demo PASSWORDS are not listed here.
PUBLIC_KEYS='^(APP_NAME|APP_ENV|APP_URL|APP_DEBUG|CLOUDFLARE_ZONE_NAME|MAIL_(MAILER|HOST|PORT|SCHEME|FROM_ADDRESS|FROM_NAME)|LOG_.*|DB_CONNECTION|SESSION_.*|CACHE_STORE|QUEUE_CONNECTION|APPLE_CLIENT_ID|SHOWCASE_(URL|ADMIN_URL|ADMIN_EMAIL|FLOWERS_URL|FLOWERS_ADMIN_URL|FLOWERS_ADMIN_EMAIL|RECORDING))$'

[[ -z $(git status --porcelain) ]] || die "commit or stash first: the copy is made from committed history only"
scrub_rules=$root/infra/publish-scrub.local.pl
deny_rules=$root/infra/publish-deny.local
# Every server address the operator knows (infra/hosts.local.env: the fleet,
# the control host, servers outside the fleet) is scrubbed from history and
# then denied - so a host added later is covered without editing any rule.
registry_ips=()
if [[ -f $root/infra/hosts.local.env ]]; then
  mapfile -t registry_ips < <(grep -E '^CIC_(HOSTS|CONTROL_HOST|FORBIDDEN_HOSTS)=' "$root/infra/hosts.local.env" \
    | grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | sort -u)
fi
mkdir -p "$work.rules"
# It holds the patterns being removed: gone when the script ends, however it ends.
trap 'rm -rf "$work.rules"' EXIT
scrub=$work.rules/scrub.pl
deny=$work.rules/deny.txt
: > "$scrub"; : > "$deny"
[[ -f $scrub_rules ]] && cat "$scrub_rules" >> "$scrub"
[[ -f $deny_rules ]] && cat "$deny_rules" >> "$deny"
n=0
for ip in "${registry_ips[@]}"; do
  n=$((n + 1))
  printf 's/\\Q%s\\E/203.0.113.%d/g;\n' "$ip" "$((100 + n))" >> "$scrub"
  printf '%s\n' "${ip//./\\.}" >> "$deny"
done
[[ -s $scrub ]] || scrub=""
[[ -s $deny ]] || deny=""
if [[ $visibility == public ]]; then
  [[ -f $scrub_rules && -f $deny_rules ]] || die "--public needs infra/publish-scrub.local.pl and infra/publish-deny.local (not committed)"
  (( ${#registry_ips[@]} > 0 )) || die "--public needs infra/hosts.local.env: its addresses are what the scrub removes"
fi
if [[ -n $author ]]; then
  [[ $author =~ ^(.+)\ \<([^<>@]+@[^<>]+)\>$ ]] || die "--author must look like: Name <email>"
  author_name=${BASH_REMATCH[1]}; author_email=${BASH_REMATCH[2]}
fi

# ── the clean copy ───────────────────────────────────────────────────────────
rm -rf "$work"
mkdir -p "$work"
git clone -q --no-local "$root" "$work/repo"
cd "$work/repo"
git remote remove origin

# Notes are keyed by commit hash, which the rewrite changes. Remember each
# note by the commit's subject and author date, then re-attach it.
declare -a note_keys=() note_files=()
while read -r _note commit; do
  f=$(mktemp "$work/note.XXXX")
  git -C "$root" notes show "$commit" > "$f"
  note_keys+=("$(git -C "$root" log -1 --format='%ad%x09%s' --date=raw "$commit")")
  note_files+=("$f")
done < <(git -C "$root" notes list)

rm_cmd="git rm -r -q --cached --ignore-unmatch ${REMOVE_PATHS[*]}"
FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch -f --index-filter "$rm_cmd" --prune-empty -- --all >/dev/null
rm -rf .git/refs/original

# The scrub: every TEXT file of every commit (lock files excluded - their
# hashes must stay byte-exact), every message, every note.
if [[ -n $scrub ]]; then
  env_filter=":"
  [[ -n $author ]] && env_filter="export GIT_AUTHOR_NAME='${author_name//\'/}' GIT_AUTHOR_EMAIL='$author_email' GIT_COMMITTER_NAME='${author_name//\'/}' GIT_COMMITTER_EMAIL='$author_email'"
  FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch -f --tree-filter "perl '$root/infra/publish-scrub-tree.pl' '$scrub'" \
    --msg-filter "perl -p '$scrub'" --env-filter "$env_filter" -- --all >/dev/null
  rm -rf .git/refs/original
  git checkout -q -f HEAD
  for f in "${note_files[@]}"; do perl -pi "$scrub" "$f"; done
  # Notes are re-attached by subject: scrub the subjects they are found by too.
  for i in "${!note_keys[@]}"; do note_keys[i]=$(printf '%s' "${note_keys[i]}" | perl -p "$scrub"); done
  ok "scrubbed every file, message and note${author:+; every commit now by $author}"
elif [[ -n $author ]]; then
  FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch -f --env-filter "export GIT_AUTHOR_NAME='${author_name//\'/}' GIT_AUTHOR_EMAIL='$author_email' GIT_COMMITTER_NAME='${author_name//\'/}' GIT_COMMITTER_EMAIL='$author_email'" -- --all >/dev/null
  rm -rf .git/refs/original
fi
git reflog expire --expire=now --all
git gc -q --prune=now --aggressive

for i in "${!note_keys[@]}"; do
  # No early exit in awk: it would SIGPIPE git log, and pipefail would end the script.
  new=$(git log --format='%H%x09%ad%x09%s' --date=raw | awk -F'\t' -v k="${note_keys[$i]}" '$2"\t"$3==k && !found++ {print $1}')
  [[ -n $new ]] || die "could not re-attach a git note (commit: ${note_keys[$i]})"
  # The notes ref gets commits of its own: made under --author too, or they
  # would carry this machine's identity.
  if [[ -n $author ]]; then
    GIT_AUTHOR_NAME=$author_name GIT_AUTHOR_EMAIL=$author_email GIT_COMMITTER_NAME=$author_name GIT_COMMITTER_EMAIL=$author_email \
      git notes add -f -F "${note_files[$i]}" "$new"
  else
    git notes add -f -F "${note_files[$i]}" "$new"
  fi
done
ok "clean copy at .publish/repo ($(git rev-list --count HEAD) commits, ${#note_keys[@]} note(s) carried over)"

# ── scan the rewritten history ───────────────────────────────────────────────
hist=$work/history.txt
git log -p --all --no-color --binary > "$hist"
git notes list | while read -r n _; do git cat-file -p "$n"; done >> "$hist"

for p in "${REMOVE_PATHS[@]}"; do
  [[ -z $(git log --all --format= --name-only -- "$p") ]] || die "$p is still in history"
done
ok "removed from every commit: ${REMOVE_PATHS[*]}"

leaks=0
while IFS= read -r line; do
  [[ -z $line || $line == \#* || $line != *=* ]] && continue
  k=${line%%=*}; v=${line#*=}; v=${v%\"}; v=${v#\"}
  [[ $k =~ $PUBLIC_KEYS || ${#v} -lt 8 ]] && continue
  if grep -qF -- "$v" "$hist"; then echo "  the value of $k is in history" >&2; leaks=$((leaks+1)); fi
done < "$root/.env"
(( leaks == 0 )) || die "$leaks secret value(s) from .env found in history"
ok "no .env secret in any commit or note"

grep -qE -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' "$hist" && die "a private key block is in history"
ok "no private key in history"

if [[ -n $deny ]]; then
  # Text only (a binary patch's base64 can spell anything), plus every
  # author and committer line.
  text=$work/history-text.txt
  { git log -p --all --no-color --format='commit %H%nauthor %an <%ae>%ncommitter %cn <%ce>%n%B'
    git notes list | while read -r n _; do git cat-file -p "$n"; done; } > "$text"
  hits=0
  while IFS= read -r re; do
    [[ -z $re || $re == \#* ]] && continue
    if grep -qE -- "$re" "$text"; then
      echo "  still in history: /$re/ ($(grep -cE -- "$re" "$text") line(s)), first in:" >&2
      # Where, not what: the nearest file or commit header above the first match.
      awk -v re="$re" '/^(diff --git|commit )/ {where=$0} $0 ~ re {print "    " where; exit}' "$text" >&2
      hits=$((hits+1))
    fi
  done < "$deny"
  rm -f "$text"
  (( hits == 0 )) || die "$hits denied pattern(s) remain (infra/publish-deny.local); nothing published"
  ok "nothing from infra/publish-deny.local in the code, messages, notes or authors of any commit"
fi

# An independent scanner too (gitleaks: hundreds of secret patterns, not ours),
# over every commit of the clean copy. Required for --public.
if command -v gitleaks >/dev/null; then
  gitleaks git --no-banner --redact --exit-code 1 . >/dev/null 2>&1 \
    || { gitleaks git --no-banner --redact . 2>&1 | tail -20 >&2; die "gitleaks found secrets in history"; }
  ok "gitleaks: nothing in any commit"
elif [[ $visibility == public ]]; then
  die "--public needs gitleaks (brew install gitleaks)"
fi

artifacts=$(git log --all --format= --name-only | sort -u | grep -iE '\.(zip|webm|har|sqlite|sqlite3|db|pem|key|p12)$' || true)
[[ -z $artifacts ]] || die "binary artifacts in history: $artifacts"
ok "no binary artifacts in history"
rm -f "$hist" "$work"/note.*

# ── publish ──────────────────────────────────────────────────────────────────
if [[ -z $target ]]; then
  echo "clean copy ready; nothing pushed. Push with: $0 --private OWNER/NAME"
  exit 0
fi
gh auth status >/dev/null 2>&1 || die "not signed in to GitHub: run gh auth login"
gh repo create "$target" "--$visibility" --source . --push --description "Laravel hosting driven by an AI agent in the browser"
git push -q origin 'refs/notes/*'
[[ $(gh repo view "$target" --json visibility -q .visibility) == "$(printf %s "$visibility" | tr a-z A-Z)" ]] || die "GitHub reports a different visibility than --$visibility"
ok "pushed https://github.com/$target ($visibility)"
