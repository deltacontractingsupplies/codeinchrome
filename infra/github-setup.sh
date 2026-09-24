#!/usr/bin/env bash
#
# Make the public GitHub repository enterprise-grade, through the API, so the
# settings are reviewable and can be re-applied:
#
#   infra/github-setup.sh OWNER/NAME
#
#   - main takes changes ONLY through pull requests: a code owner approves
#     (.github/CODEOWNERS), every conversation is resolved, the last push is
#     re-approved, and CI passes - tests, static checks, the secret scan.
#     No force-push, no deletion. Admins may merge their own pull requests
#     (a sole maintainer is never locked out), never push around review.
#   - secret scanning with push protection, Dependabot alerts and security
#     updates, private vulnerability reporting (SECURITY.md)
#   - workflows get a read-only token and cannot approve pull requests
#   - squash merges only, branches deleted after merge, no wiki
#
# Idempotent: re-running replaces the ruleset of the same name.
# Needs: gh auth login (as a repository admin).

set -Eeuo pipefail
repo=${1:?usage: $0 OWNER/NAME}

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }

gh auth status >/dev/null 2>&1 || die "not signed in to GitHub: run gh auth login"
[[ $(gh repo view "$repo" --json viewerPermission -q .viewerPermission) == ADMIN ]] || die "you are not an admin of $repo"

# ── the maintainers team (.github/CODEOWNERS names it) ────────────────────────
# CODEOWNERS entries for a team that does not exist are ignored, silently.
org=${repo%%/*}
if gh api "orgs/$org" --silent 2>/dev/null; then
  gh auth status 2>&1 | grep -q "admin:org" || die "creating the team needs: gh auth refresh -s admin:org"
  if ! gh api "orgs/$org/teams/maintainers" --silent 2>/dev/null; then
    gh api -X POST "orgs/$org/teams" --silent -f name=maintainers -f privacy=closed \
      -f description="Review and merge every change to codeinchrome"
  fi
  me=$(gh api user -q .login)
  gh api -X PUT "orgs/$org/teams/maintainers/memberships/$me" --silent -f role=maintainer
  gh api -X PUT "orgs/$org/teams/maintainers/repos/$repo" --silent -f permission=maintain
  ok "team $org/maintainers (with $me) maintains $repo - CODEOWNERS points at it"
else
  # A personal account: its owner is the code owner (.github/CODEOWNERS names them).
  grep -q "@$org\b" .github/CODEOWNERS || die ".github/CODEOWNERS does not name @$org, the repository's owner"
  ok "code owner: @$org (a personal account - no team)"
fi

# ── repository settings ──────────────────────────────────────────────────────
gh api -X PATCH "repos/$repo" --silent \
  -F allow_squash_merge=true -F allow_merge_commit=false -F allow_rebase_merge=false \
  -F delete_branch_on_merge=true -F has_wiki=false \
  -F 'security_and_analysis[secret_scanning][status]=enabled' \
  -F 'security_and_analysis[secret_scanning_push_protection][status]=enabled'
ok "squash merges only, branches deleted after merge; secret scanning with push protection"

gh api -X PUT "repos/$repo/vulnerability-alerts" --silent
gh api -X PUT "repos/$repo/automated-security-fixes" --silent
ok "Dependabot alerts and security updates"

gh api -X PUT "repos/$repo/private-vulnerability-reporting" --silent
ok "private vulnerability reporting (SECURITY.md)"

gh api -X PUT "repos/$repo/actions/permissions/workflow" --silent \
  -f default_workflow_permissions=read -F can_approve_pull_request_reviews=false
ok "workflows: read-only token, cannot approve pull requests"

# ── where contributor-agreement signatures are kept ──────────────────────────
# .github/workflows/cla.yml commits signatures/cla.json to this branch; main
# only takes reviewed pull requests, so they cannot live there. An orphan
# branch from the empty tree: no code of its own.
if ! gh api "repos/$repo/branches/cla-signatures" --silent 2>/dev/null; then
  empty_tree=4b825dc642cb6eb9a060e54bf8d69288fbee4904
  commit=$(gh api -X POST "repos/$repo/git/commits" -f message="Contributor agreement signatures" -f tree=$empty_tree -q .sha)
  gh api -X POST "repos/$repo/git/refs" --silent -f ref=refs/heads/cla-signatures -f sha="$commit"
fi
ok "branch cla-signatures for contributor-agreement signatures"

# ── the ruleset on main ──────────────────────────────────────────────────────
# The required checks are the CI job names in .github/workflows/ci.yml, and
# the status .github/cla/cla.sh sets - accepted from GitHub Actions (app
# 15368) only, so no other token can mark a pull request as signed.
name="main: reviewed pull requests only"
existing=$(gh api "repos/$repo/rulesets" -q ".[] | select(.name == \"$name\") | .id")
ruleset=$(cat <<JSON
{
  "name": "$name",
  "target": "branch",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["~DEFAULT_BRANCH"], "exclude": [] } },
  "bypass_actors": [
    { "actor_id": 5, "actor_type": "RepositoryRole", "bypass_mode": "pull_request" }
  ],
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    { "type": "pull_request", "parameters": {
        "required_approving_review_count": 1,
        "require_code_owner_review": true,
        "dismiss_stale_reviews_on_push": true,
        "require_last_push_approval": true,
        "required_review_thread_resolution": true
    } },
    { "type": "required_status_checks", "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [
          { "context": "no secrets in the change (gitleaks)" },
          { "context": "agent (go)" },
          { "context": "control plane (laravel)" },
          { "context": "infra scripts" },
          { "context": "contributor agreement", "integration_id": 15368 }
        ]
    } }
  ]
}
JSON
)
if [[ -n $existing ]]; then
  gh api -X PUT "repos/$repo/rulesets/$existing" --input - --silent <<<"$ruleset"
else
  gh api -X POST "repos/$repo/rulesets" --input - --silent <<<"$ruleset"
fi
ok "ruleset on main: pull requests only, code-owner approval, CI required, no force-push or deletion"

echo "done: https://github.com/$repo/settings/rules"
