#!/usr/bin/env bash
set -euo pipefail

TAG_PREFIX=${TAG_PREFIX:-v}
PRIMARY_BRANCH=$(git remote show origin | sed -n '/HEAD branch/s/.*: //p')
PRIMARY_BRANCH=${PRIMARY_BRANCH:-main}

get_tags() {
  git ls-remote --tags origin \
    | grep -E 'refs/tags/(v|qa)' \
    | sed -E 's#.+refs/tags/##' \
    | sed 's/\^{}//'
}

get_max_version() {
  get_tags \
    | grep -E '^(v|qa)(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)[^0-9]*$' \
    | sed -E 's/([^0-9]+)(.+)/\2 \1\2/' \
    | sort -Vr \
    | awk '{print $2}' \
    | head -n1
}

CURRENT_MAX_VERSION=$(get_max_version)
if [[ -z "$CURRENT_MAX_VERSION" ]]; then
  CURRENT_MAX_VERSION="${TAG_PREFIX}1.0.0"
  MAJOR=1
  MINOR=0
  PATCH=0
else
  RE='[^0-9]*\([0-9]*\)[.]\([0-9]*\)[.]\([0-9]*\)\([0-9A-Za-z-]*\)'
  MAJOR=$(echo "$CURRENT_MAX_VERSION" | sed -e "s#$RE#\1#")
  MINOR=$(echo "$CURRENT_MAX_VERSION" | sed -e "s#$RE#\2#")
  PATCH=$(echo "$CURRENT_MAX_VERSION" | sed -e "s#$RE#\3#")
  PATCH=$((PATCH + 1))
fi

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
NEW_VERSION_PREFIX="${TAG_PREFIX}${NEW_VERSION}"

printf 'Setting tag from: %s to %s\n\n' "$CURRENT_MAX_VERSION" "$NEW_VERSION_PREFIX"

git fetch --all >/dev/null
git fetch --tags --force >/dev/null

if [[ -n $(git status --porcelain) ]]; then
  printf 'There are uncommitted changes.\n'
  exit 1
fi

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "HEAD")

if [[ "$CURRENT_BRANCH" == "HEAD" ]]; then
  # Detached HEAD (common with jj colocated repos) — verify HEAD matches origin/main
  if [[ "$(git rev-parse HEAD)" != "$(git rev-parse "origin/$PRIMARY_BRANCH")" ]]; then
    printf 'Detached HEAD does not match origin/%s. Please update to the latest %s.\n' "$PRIMARY_BRANCH" "$PRIMARY_BRANCH"
    exit 1
  fi
  CURRENT_BRANCH="$PRIMARY_BRANCH"
else
  MERGE_BASE=$(git merge-base "origin/$CURRENT_BRANCH" HEAD)
  if [[ "$MERGE_BASE" != "$(git rev-parse "$CURRENT_BRANCH")" ]]; then
    printf 'Please push all changes.\n'
    exit 1
  fi
fi

if [[ "$NEW_VERSION_PREFIX" == v* && "$CURRENT_BRANCH" != "$PRIMARY_BRANCH" ]]; then
  printf 'You are not on the primary branch (%s).\n' "$PRIMARY_BRANCH"
  exit 1
fi

printf 'Current Version\n%s\n\n' "$CURRENT_MAX_VERSION"
echo 'Matches'
git tag | grep "$CURRENT_MAX_VERSION" || true
printf '\nSetting the following tags\n%s\n' "$NEW_VERSION_PREFIX"

git tag "$NEW_VERSION_PREFIX"
git push origin "$NEW_VERSION_PREFIX"
print_release() {
  printf '\nGenerating github release\n\n'
  gh release create "$1" --generate-notes
  printf '\n'
}
if [[ "$NEW_VERSION_PREFIX" == v* ]]; then
  print_release "$NEW_VERSION_PREFIX"
fi

echo 'Done'
