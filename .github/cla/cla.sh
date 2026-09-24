#!/usr/bin/env bash
#
# The contributor agreement (CLA.md), checked on every pull request - our own,
# replacing contributor-assistant/github-action (archived: no more fixes, and
# it ran with a write token on pull_request_target).
#
# Run by .github/workflows/cla.yml from the BASE branch: no pull-request code is
# ever checked out or run, and what people type arrives in environment
# variables, compared, never evaluated.
#
#   Who signs: the pull request's author, and every GitHub account that
#   authored one of its commits. A commit whose email belongs to no account
#   is the author's to answer for - they are the one submitting it.
#   How: a comment on the pull request that is exactly SIGN_PHRASE, by
#   someone who must sign. Kept in signatures/cla.json on branch
#   cla-signatures (the format the old action used, so no signature is lost).
#   The answer: a commit status, CONTEXT, which main's ruleset requires; and
#   one comment on the pull request, updated in place, naming who is left.
#
# Needs: gh (signed in through GH_TOKEN), jq. Environment: REPO, PR, EVENT,
# and for comments COMMENT_BODY, COMMENTER, COMMENTER_ID, COMMENT_ID.

set -Eeuo pipefail
: "${REPO:?}" "${PR:?}" "${EVENT:?}"

SIGN_PHRASE='I have read the CLA Document and I hereby sign the CLA'
CONTEXT='contributor agreement'
FILE=signatures/cla.json
BRANCH=cla-signatures
MARK='<!-- codeinchrome-cla -->'
# Signs nothing: the Licensor's own account, and the bots that open updates.
ALLOW=" ${CLA_ALLOWLIST:-deltacontractingsupplies dependabot[bot] github-actions[bot]} "
DOC="https://github.com/$REPO/blob/main/CLA.md"

say() { printf '%s\n' "$*" >&2; }

body=""

if [[ $EVENT == issue_comment ]]; then
  body=$(printf '%s' "${COMMENT_BODY:-}" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
  if [[ $body != "$SIGN_PHRASE" && $body != recheck ]]; then
    say "a comment that is neither the agreement nor 'recheck': nothing to do"
    exit 0
  fi
fi

pr=$(gh api "repos/$REPO/pulls/$PR")
head=$(jq -r .head.sha <<<"$pr")
author=$(jq -r .user.login <<<"$pr")
author_id=$(jq -r .user.id <<<"$pr")

# login<TAB>id, one per line, sorted and unique.
signers=$( { printf '%s\t%s\n' "$author" "$author_id"
  gh api --paginate "repos/$REPO/pulls/$PR/commits" | jq -rs 'add // [] | .[] | select(.author != null and .author.login != null) | "\(.author.login)\t\(.author.id)"'
} | sort -u)

read_signatures() {
  local file
  if file=$(gh api "repos/$REPO/contents/$FILE?ref=$BRANCH" 2>/dev/null); then
    file_sha=$(jq -r .sha <<<"$file")
    signed=$(jq -r .content <<<"$file" | base64 -d)
  else
    file_sha=""
    signed='{"signedContributors":[]}'
  fi
}
read_signatures

is_signer() { cut -f2 <<<"$signers" | grep -qx -- "$1"; }
has_signed() { jq -e --argjson id "$1" 'any(.signedContributors[]; .id == $id)' <<<"$signed" >/dev/null; }

if [[ $EVENT == issue_comment && $body == "$SIGN_PHRASE" ]]; then
  if ! is_signer "$COMMENTER_ID"; then
    say "$COMMENTER is not an author of this pull request: not recorded"
  elif has_signed "$COMMENTER_ID"; then
    say "$COMMENTER has already signed"
  else
    # Retried on a conflict: two pull requests signed at once.
    for attempt in 1 2 3; do
      updated=$(jq --arg name "$COMMENTER" --argjson id "$COMMENTER_ID" --argjson comment "$COMMENT_ID" \
        --argjson pr "$PR" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        '.signedContributors += [{name: $name, id: $id, comment_id: $comment, created_at: $at, pullRequestNo: $pr}]' <<<"$signed")
      args=(-X PUT "repos/$REPO/contents/$FILE" -f "message=CLA signed by $COMMENTER (#$PR)" -f "branch=$BRANCH"
        -f "content=$(printf '%s\n' "$updated" | base64 | tr -d '\n')")
      [[ -n $file_sha ]] && args+=(-f "sha=$file_sha")
      if gh api "${args[@]}" >/dev/null; then
        signed=$updated
        say "recorded the signature of $COMMENTER"
        break
      fi
      (( attempt < 3 )) || { say "could not record the signature"; exit 1; }
      sleep 2
      read_signatures
    done
  fi
fi

missing=()
while IFS=$'\t' read -r login id; do
  [[ -z $login || $ALLOW == *" $login "* ]] && continue
  has_signed "$id" || missing+=("$login")
done <<<"$signers"

if (( ${#missing[@]} == 0 )); then
  state=success; description="Everyone in this pull request has signed the contributor agreement"
else
  state=failure; description="Waiting for: ${missing[*]}"
fi
gh api -X POST "repos/$REPO/statuses/$head" -f "state=$state" -f "context=$CONTEXT" \
  -f "description=${description:0:139}" -f "target_url=$DOC" >/dev/null
say "$CONTEXT: $state - $description"

# One comment, ours, kept up to date - only once someone has had to sign.
comment_id=$(gh api --paginate "repos/$REPO/issues/$PR/comments" \
  | jq -rs --arg mark "$MARK" 'add // [] | map(select(.user.login == "github-actions[bot]" and (.body | contains($mark)))) | first | .id // empty')
if (( ${#missing[@]} > 0 )); then
  who=$(printf '@%s, ' "${missing[@]}"); who=${who%, }
  text="$MARK
Thank you for your contribution. Before it can be merged, $who please read the [contributor agreement]($DOC) and sign it by commenting on this pull request with exactly:

    $SIGN_PHRASE

(A commit whose email is not linked to your GitHub account counts as the pull request author's; to be named for it yourself, [add that email to your account](https://docs.github.com/en/account-and-profile/setting-up-and-managing-your-personal-account-on-github/managing-email-preferences/adding-an-email-address-to-your-github-account).)"
elif [[ -n $comment_id ]]; then
  text="$MARK
Everyone in this pull request has signed the [contributor agreement]($DOC). Thank you."
else
  exit 0
fi
if [[ -n $comment_id ]]; then
  gh api -X PATCH "repos/$REPO/issues/comments/$comment_id" -f "body=$text" >/dev/null
else
  gh api -X POST "repos/$REPO/issues/$PR/comments" -f "body=$text" >/dev/null
fi
