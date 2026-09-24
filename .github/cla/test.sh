#!/usr/bin/env bash
#
# .github/cla/cla.sh against a stand-in for GitHub (fake-gh.py): who must
# sign, who may sign, what is recorded, the status the ruleset reads, and the
# one comment. Run in CI (infra scripts) and by hand: bash .github/cla/test.sh

set -Eeuo pipefail
here=$(cd "$(dirname "$0")" && pwd)
# Beside the repository, never the system temp dir (the developer's internal disk).
work=$(mktemp -d "$here/../../.cla-test.XXXX")
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin"
cat > "$work/bin/gh" <<SH
#!/usr/bin/env bash
exec python3 "$here/fake-gh.py" "\$@"
SH
chmod +x "$work/bin/gh"
export PATH="$work/bin:$PATH" REPO=owner/repo PR=7
failures=0
pass() { printf '  ok %s\n' "$*"; }
fail() { printf '  FAIL %s\n' "$*"; failures=$((failures + 1)); }
fresh() { export FAKE_GH="$work/$1"; mkdir -p "$FAKE_GH"; }
pr() { printf '{"head":{"sha":"abc123"},"user":{"login":"%s","id":%s}}' "$1" "$2" > "$FAKE_GH/pr.json"; }
commits() { printf '%s' "$1" > "$FAKE_GH/commits.json"; }
run() { EVENT=$1 COMMENT_BODY=${2:-} COMMENTER=${3:-} COMMENTER_ID=${4:-0} COMMENT_ID=${5:-0} bash "$here/cla.sh" 2>/dev/null; }
status() { jq -r .state "$FAKE_GH/status.json"; }
signed_ids() { jq -r .content "$FAKE_GH/file.json" | base64 -d | jq -r '[.signedContributors[].id] | join(",")'; }
comment() { jq -r '.[0].body // ""' "$FAKE_GH/comments.json" 2>/dev/null || true; }
PHRASE='I have read the CLA Document and I hereby sign the CLA'

fresh owner; pr deltacontractingsupplies 1; commits '[{"author":null}]'
run pull_request_target
[[ $(status) == success ]] && pass "the owner's pull request passes, unsigned" || fail "owner: $(status)"
[[ ! -f $FAKE_GH/comments.json ]] && pass "and gets no comment" || fail "owner was asked to sign"

fresh outsider; pr alice 11; commits '[{"author":{"login":"alice","id":11}}]'
run pull_request_target
[[ $(status) == failure ]] && pass "an outsider's pull request waits for the agreement" || fail "outsider: $(status)"
[[ $(jq -r .context "$FAKE_GH/status.json") == "contributor agreement" && $(jq -r .sha "$FAKE_GH/status.json") == abc123 ]] \
  && pass "on the head commit, under the context the ruleset requires" || fail "status context/sha"
[[ $(comment) == *"@alice"* && $(comment) == *"$PHRASE"* ]] && pass "and a comment says who and how" || fail "comment: $(comment)"

run issue_comment "Looks good to me" mallory 99 5
[[ ! -f $FAKE_GH/file.json && $(status) == failure ]] && pass "any other comment signs nothing" || fail "a stray comment changed something"

run issue_comment "$PHRASE" mallory 99 6
[[ ! -f $FAKE_GH/file.json ]] && pass "someone who is not an author cannot sign for the pull request" || fail "mallory's signature was recorded"

run issue_comment "  $PHRASE  " alice 11 7
[[ $(signed_ids) == 11 ]] && pass "the author's signature is recorded (surrounding spaces forgiven)" || fail "signed: $(signed_ids)"
[[ $(status) == success ]] && pass "and the pull request passes" || fail "after signing: $(status)"
[[ $(jq length "$FAKE_GH/comments.json") == 1 && $(comment) == *"Everyone in this pull request has signed"* ]] \
  && pass "the same comment is updated, not a second one added" || fail "comments: $(jq length "$FAKE_GH/comments.json")"

run issue_comment "$PHRASE" alice 11 8
[[ $(signed_ids) == 11 ]] && pass "signing twice records once" || fail "signed: $(signed_ids)"

fresh coauthor; pr alice 11; commits '[{"author":{"login":"alice","id":11}},{"author":{"login":"bob","id":12}},{"author":null}]'
printf '{"sha":"sha0","content":"%s"}' "$(printf '{"signedContributors":[{"name":"alice","id":11}]}' | base64 | tr -d '\n')" > "$FAKE_GH/file.json"
run pull_request_target
[[ $(status) == failure && $(jq -r .description "$FAKE_GH/status.json") == "Waiting for: bob" ]] \
  && pass "every commit author signs; one who already signed is not asked again" || fail "coauthor: $(jq -c . "$FAKE_GH/status.json")"
run issue_comment "recheck" alice 11 9
[[ $(status) == failure ]] && pass "recheck recomputes, it signs nothing" || fail "recheck: $(status)"
run issue_comment "$PHRASE" bob 12 10
[[ $(signed_ids) == 11,12 && $(status) == success ]] && pass "the co-author signs; earlier signatures are kept" || fail "signed: $(signed_ids) $(status)"

fresh bots; pr 'dependabot[bot]' 49699333; commits '[{"author":{"login":"dependabot[bot]","id":49699333}}]'
run pull_request_target
[[ $(status) == success ]] && pass "Dependabot's updates pass" || fail "dependabot: $(status)"

fresh injection; pr alice 11; commits '[]'
run issue_comment "\$(touch $work/pwned) \`touch $work/pwned\`" alice 11 11
[[ ! -e $work/pwned ]] && pass "what a commenter types is never run" || fail "a comment ran a command"

(( failures == 0 )) || { echo "$failures failure(s)"; exit 1; }
echo "cla.sh: all checks passed"
