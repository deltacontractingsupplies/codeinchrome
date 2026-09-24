#!/usr/bin/env bash
#
# The last steps, in order, once the owner has done theirs:
#
#   infra/go-public.sh
#
# Owner first (none of these can be done for them):
#   1. GOOGLE_CLIENT_SECRET=... in .env (Google Cloud console, the codeinchrome
#      project, client "codeinchrome web sign-in": the newest secret)
#   2. a free GitHub organization named codeinchrome
#   3. gh auth login, then gh auth refresh -s admin:org
#
# Then this script:
#   - deploys the control plane and proves Google sign-in answers with the
#     right client and callback
#   - publishes the repository PUBLIC as codeinchrome/codeinchrome through
#     infra/publish-repo.sh, which refuses on any finding (scrub, .env values,
#     keys, deny list, gitleaks, artifacts)
#   - applies infra/github-setup.sh (reviewed pull requests only, CI and the
#     contributor agreement required, secret scanning, maintainers team)
#   - reads the settings back from GitHub and checks them
#
# Stops at the first missing prerequisite and says which.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
repo=codeinchrome/codeinchrome

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

# ── prerequisites ────────────────────────────────────────────────────────────
grep -qE '^GOOGLE_CLIENT_SECRET=.+' .env || die "step 1: GOOGLE_CLIENT_SECRET is not in .env"
gh auth status >/dev/null 2>&1 || die "step 3: run gh auth login"
gh auth status 2>&1 | grep -q 'admin:org' || die "step 3: run gh auth refresh -s admin:org"
gh api orgs/codeinchrome --silent 2>/dev/null || die "step 2: the codeinchrome organization does not exist (or you cannot see it)"
[[ -z $(git status --porcelain) ]] || die "commit or stash first"
ok "prerequisites: Google secret, GitHub sign-in with admin:org, the organization"

# ── Google sign-in, live ─────────────────────────────────────────────────────
bash infra/deploy-control.sh >/dev/null 2>&1 || die "deploy-control.sh failed - run it on its own to see why"
client=$(grep -E '^GOOGLE_CLIENT_ID=' .env | cut -d= -f2-)
location=$(curl -s -o /dev/null -w '%{redirect_url}' https://app.codeinchrome.com/auth/google/redirect)
[[ $location == https://accounts.google.com/* ]] || die "Google sign-in does not redirect to Google (got: ${location:-no redirect})"
[[ $location == *"client_id=$client"* ]] || die "Google sign-in redirects with a different client id"
[[ $location == *"redirect_uri=https%3A%2F%2Fapp.codeinchrome.com%2Fauth%2Fgoogle%2Fcallback"* ]] || die "Google sign-in has the wrong callback address"
ok "Google sign-in answers: our client, our callback (finish one real sign-in in a browser to be sure)"

# ── publish, then lock it down ───────────────────────────────────────────────
gh repo view "$repo" --silent 2>/dev/null && die "$repo already exists - publish-repo.sh creates it; nothing pushed"
bash infra/publish-repo.sh --public "$repo"
bash infra/github-setup.sh "$repo"

# ── read it back ─────────────────────────────────────────────────────────────
[[ $(gh repo view "$repo" --json visibility -q .visibility) == PUBLIC ]] || die "$repo is not public"
rules=$(gh api "repos/$repo/rulesets" -q '.[].name')
[[ $rules == *"reviewed pull requests only"* ]] || die "the main ruleset is not in place"
[[ $(gh api "repos/$repo" -q .security_and_analysis.secret_scanning_push_protection.status) == enabled ]] || die "push protection is off"
gh api "repos/$repo/branches/cla-signatures" --silent || die "branch cla-signatures is missing"
ok "https://github.com/$repo is public, reviewed-PR-only, scanned, with the contributor agreement"
echo
echo "Last, by hand: open https://github.com/$repo/settings/rules and read the ruleset once;"
echo "then open a small pull request yourself and sign the contributor agreement with the comment it asks for."
