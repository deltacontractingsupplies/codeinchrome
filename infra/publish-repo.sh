#!/usr/bin/env bash
#
# Publish this repository publicly - from a CLEANED COPY, never from here.
#
#   infra/publish-repo.sh                    build the clean copy and scan it
#   infra/publish-repo.sh --publish OWNER/NAME   ...then create the public repo and push
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
#
# Rotate the Lemon Squeezy key before publishing anyway: it has lived on a
# laptop, and a published repository is forever.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
root=$PWD
work=$root/.publish
target=""

case "${1:-}" in
  "") ;;
  --publish) target=${2:?usage: --publish OWNER/NAME} ;;
  *) echo "usage: $0 [--publish OWNER/NAME]" >&2; exit 2 ;;
esac

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

REMOVE_PATHS=(tests/e2e/report)
# .env settings that are public by design and may appear in the code.
PUBLIC_KEYS='^(APP_NAME|APP_ENV|APP_URL|APP_DEBUG|CLOUDFLARE_ZONE_NAME|MAIL_(MAILER|HOST|PORT|SCHEME|FROM_ADDRESS|FROM_NAME)|LOG_.*|DB_CONNECTION|SESSION_.*|CACHE_STORE|QUEUE_CONNECTION)$'

[[ -z $(git status --porcelain) ]] || die "commit or stash first: the copy is made from committed history only"

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
git reflog expire --expire=now --all
git gc -q --prune=now --aggressive

for i in "${!note_keys[@]}"; do
  new=$(git log --format='%H%x09%ad%x09%s' --date=raw | awk -F'\t' -v k="${note_keys[$i]}" '$2"\t"$3==k {print $1; exit}')
  [[ -n $new ]] || die "could not re-attach a git note (commit: ${note_keys[$i]})"
  git notes add -f -F "${note_files[$i]}" "$new"
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

artifacts=$(git log --all --format= --name-only | sort -u | grep -iE '\.(zip|webm|har|sqlite|sqlite3|db|pem|key|p12)$' || true)
[[ -z $artifacts ]] || die "binary artifacts in history: $artifacts"
ok "no binary artifacts in history"
rm -f "$hist" "$work"/note.*

# ── publish ──────────────────────────────────────────────────────────────────
if [[ -z $target ]]; then
  echo "clean copy ready; nothing published. Publish with: $0 --publish OWNER/NAME"
  exit 0
fi
gh auth status >/dev/null 2>&1 || die "not signed in to GitHub: run gh auth login"
gh repo create "$target" --public --source . --push --description "Laravel hosting driven by an AI agent in the browser"
git push -q origin 'refs/notes/*'
ok "published https://github.com/$target"
