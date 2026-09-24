#!/usr/bin/env bash
#
# Replace the public GitHub repository with a rewritten copy of its history -
# for something that must leave history entirely (2026-09-24: a personal email
# address the owner does not want published, on 130 commits):
#
#   infra/recreate-public-repo.sh OWNER/NAME REWRITTEN_CLONE [--dry-run]
#
# REWRITTEN_CLONE is a git repository whose `main` (and refs/notes/*) is the
# new history, already rewritten. Refuses unless:
#   - its files are exactly those of GitHub's current main (only metadata
#     changed), and it passes infra/check-outgoing.sh (the publish checks)
#   - not one object in it holds anything from infra/publish-deny.local
#   - gh can delete repositories (gh auth refresh -h github.com -s delete_repo)
#
# Then: deletes the repository (its pull requests, issues, stars and alerts go
# with it), creates it again - public, same description and homepage - pushes
# the new history, puts every setting back (infra/github-setup.sh, topics,
# CodeQL), and reads everything back from GitHub: the web's .patch view, the
# API and a fresh clone must not hold a denied pattern. --dry-run stops before
# the first change on GitHub.

set -Eeuo pipefail
cd "$(dirname "$0")/.."
root=$PWD
repo=${1:?usage: $0 OWNER/NAME REWRITTEN_CLONE [--dry-run]}
src=$(cd "${2:?usage: $0 OWNER/NAME REWRITTEN_CLONE [--dry-run]}" && pwd)
dry=${3:-}

ok()  { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
gh_git() { git -c credential.helper= -c 'credential.helper=!gh auth git-credential' "$@"; }

deny=$root/infra/publish-deny.local
[[ -f $deny ]] || die "infra/publish-deny.local is missing"
# One extended regex of every denied pattern - never empty (an empty pattern
# matches everything, or, worse, nothing).
denied=$(grep -vE '^\s*(#|$)' "$deny" | paste -sd'|' -)
[[ -n $denied ]] || die "infra/publish-deny.local has no patterns"

# Any object of a repository holding a denied pattern: prints how many.
denied_objects() {
  local dir=$1 n=0 o
  while read -r o _; do
    if git -C "$dir" cat-file -p "$o" 2>/dev/null | grep -qiE -- "$denied"; then n=$((n + 1)); fi
  done < <(git -C "$dir" rev-list --all --objects)
  echo "$n"
}

# ── the new history ──────────────────────────────────────────────────────────
git -C "$src" rev-parse -q --verify refs/heads/main >/dev/null || die "$src has no main"
gh_git fetch -q "https://github.com/$repo.git" '+refs/heads/main:refs/recreate/current'
[[ $(git rev-parse 'refs/recreate/current^{tree}') == $(git -C "$src" rev-parse 'main^{tree}') ]] \
  || die "the rewritten main's files differ from GitHub's main: only metadata may change"
ok "the rewritten history holds exactly GitHub's current files"
git fetch -q "$src" '+refs/heads/main:refs/recreate/main'
bash infra/check-outgoing.sh refs/recreate/main >/dev/null || die "infra/check-outgoing.sh refuses the rewritten history (run it for details)"
ok "publish checks pass on every rewritten commit"
n=$(denied_objects "$src")
(( n == 0 )) || die "$n object(s) in $src still hold a denied pattern"
ok "no object in the rewritten history holds a denied pattern"

description=$(gh repo view "$repo" --json description -q .description)
homepage=$(gh repo view "$repo" --json homepageUrl -q .homepageUrl)
topics=$(gh api "repos/$repo/topics" -q '.names | join(" ")')
ok "kept: description, homepage ${homepage:-(none)}, topics: ${topics:-(none)}"

if [[ $dry == --dry-run ]]; then
  echo "dry run: nothing changed on GitHub"
  exit 0
fi
gh auth status 2>&1 | grep -q "'delete_repo'" || die "gh cannot delete repositories: gh auth refresh -h github.com -s delete_repo"

# ── replace it ───────────────────────────────────────────────────────────────
gh repo delete "$repo" --yes
ok "deleted $repo"
for _ in 1 2 3 4 5 6; do gh repo view "$repo" --silent 2>/dev/null || break; sleep 5; done
gh repo create "$repo" --public --description "$description" ${homepage:+--homepage "$homepage"} >/dev/null
ok "created $repo (public)"
(
  cd "$src"
  gh_git push -q "https://github.com/$repo.git" main 'refs/notes/*:refs/notes/*'
)
ok "pushed main and its notes"
if [[ -n $topics ]]; then
  args=(); for t in $topics; do args+=(-f "names[]=$t"); done
  gh api -X PUT "repos/$repo/topics" --silent "${args[@]}"
fi
bash infra/github-setup.sh "$repo"
# A new repository's languages are detected a little after the push; until
# then CodeQL refuses ("languages not present"). Retried, never ignored.
for attempt in $(seq 1 12); do
  gh api -X PATCH "repos/$repo/code-scanning/default-setup" --silent -f state=configured -f query_suite=extended \
    -f 'languages[]=go' -f 'languages[]=javascript-typescript' -f 'languages[]=actions' 2>/dev/null && break
  (( attempt < 12 )) || die "CodeQL could not be switched on"
  sleep 10
done
ok "settings, topics and CodeQL restored"

# ── read it back, from outside ───────────────────────────────────────────────
[[ $(gh repo view "$repo" --json visibility -q .visibility) == PUBLIC ]] || die "$repo is not public"
[[ $(gh api "repos/$repo/commits/main" -q .sha) == $(git -C "$src" rev-parse main) ]] || die "GitHub's main is not the rewritten main"
# Each commit's patch as GitHub serves it (the .patch view), through the API:
# the web view rate-limits (403) and a fetch that failed must never count as
# clean. Binary patches are left out: their base85 can spell a short pattern
# by chance (build.gif does).
hits=0
while read -r sha; do
  patch=$(gh api "repos/$repo/commits/$sha" -H 'Accept: application/vnd.github.patch') || die "could not read the patch of $sha"
  [[ -n $patch ]] || die "GitHub returned an empty patch for $sha"
  if awk '/^diff --git/ {bin = 0} /^GIT binary patch/ {bin = 1} !bin' <<<"$patch" | grep -qiE -- "$denied"; then hits=$((hits + 1)); fi
done < <(git -C "$src" rev-list main)
(( hits == 0 )) || die "$hits commit patch(es) on GitHub still show a denied pattern"
ok "no commit's patch on GitHub shows a denied pattern (binary data aside)"
api=$(gh api --paginate "repos/$repo/commits?per_page=100" -q '.[] | "\(.commit.author.email) \(.commit.committer.email) \(.author.login // "") \(.commit.message)"')
grep -qiE -- "$denied" <<<"$api" && die "GitHub's API still shows a denied pattern"
ok "GitHub's API shows no denied pattern in any commit"
fresh=$root/.publish/recreate-verify
rm -rf "$fresh"
gh_git clone -q --mirror "https://github.com/$repo.git" "$fresh"
n=$(denied_objects "$fresh")
rm -rf "$fresh"
(( n == 0 )) || die "a fresh clone holds $n object(s) with a denied pattern"
ok "a fresh clone of $repo holds no denied pattern"
git update-ref -d refs/recreate/current
git update-ref -d refs/recreate/main
echo "done: https://github.com/$repo - now: git fetch public && git reset --keep public/main"
