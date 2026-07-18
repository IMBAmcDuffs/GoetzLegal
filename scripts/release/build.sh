#!/usr/bin/env bash
set -euo pipefail

unset SSH_KEY_PW

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() {
  printf 'release-build: %s\n' "$1" >&2
  exit 1
}

assert_physical_directory() {
  local path="$1"
  local expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" ]] ||
    fail 'release parent is unsafe or redirected'
  [[ "$(readlink -f -- "$path")" == "$expected" ]] ||
    fail 'release parent is unsafe or redirected'
}

validate_existing_release_ancestors() {
  local dev_root="$root/__dev"
  local release_root="$dev_root/releases"
  assert_physical_directory "$root" "$root"
  if [[ -e "$dev_root" || -L "$dev_root" ]]; then
    assert_physical_directory "$dev_root" "$dev_root"
  fi
  if [[ -e "$release_root" || -L "$release_root" ]]; then
    assert_physical_directory "$release_root" "$release_root"
  fi
}

prepare_release_root() {
  local dev_root="$root/__dev"
  local release_root="$dev_root/releases"
  validate_existing_release_ancestors
  if [[ ! -e "$dev_root" ]]; then mkdir -m 0755 -- "$dev_root"; fi
  assert_physical_directory "$dev_root" "$dev_root"
  if [[ ! -e "$release_root" ]]; then mkdir -m 0755 -- "$release_root"; fi
  assert_physical_directory "$release_root" "$release_root"
}

[[ $# -eq 1 ]] || fail 'usage: build.sh <release-commit-sha>'
release_sha="$1"
[[ "$release_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'release commit must be a full lowercase SHA-1'

command -v git >/dev/null 2>&1 || fail 'required command is unavailable: git'
command -v readlink >/dev/null 2>&1 || fail 'required command is unavailable: readlink'
validate_existing_release_ancestors
[[ -z "$(git status --porcelain --untracked-files=all)" ]] || fail 'working tree is dirty; commit or remove every change before building'
[[ "$(git branch --show-current)" == 'main' ]] || fail 'release builds must run from branch main'
head_sha="$(git rev-parse --verify HEAD^{commit})" || fail 'cannot resolve HEAD'
origin_sha="$(git rev-parse --verify refs/remotes/origin/main^{commit})" || fail 'cannot resolve origin/main; fetch it before building'
[[ "$release_sha" == "$head_sha" ]] || fail 'requested release commit does not equal HEAD'
[[ "$release_sha" == "$origin_sha" ]] || fail 'requested release commit does not equal origin/main'
git cat-file -e "$release_sha^{commit}" 2>/dev/null || fail 'requested release commit does not exist'

for command_name in tar sha256sum find sort date; do
  command -v "$command_name" >/dev/null 2>&1 || fail "required command is unavailable: $command_name"
done
if ! command -v composer >/dev/null 2>&1 && ! command -v docker >/dev/null 2>&1; then
  fail 'Composer is unavailable and the pinned Docker fallback cannot run'
fi
if ! command -v npm >/dev/null 2>&1 && ! command -v docker >/dev/null 2>&1; then
  fail 'npm is unavailable and the pinned Docker fallback cannot run'
fi

export SOURCE_DATE_EPOCH
SOURCE_DATE_EPOCH="$(git show -s --format=%ct "$release_sha")"
[[ "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]] || fail 'release commit has an invalid timestamp'
export TZ=UTC
export LC_ALL=C

readonly build_exec_path="${PATH:-/usr/local/bin:/usr/bin:/bin}"
readonly build_exec_home="${HOME:-/tmp}"
build_clean_exec() {
  local -a clean_env=(
    "HOME=$build_exec_home"
    "PATH=$build_exec_path"
    "SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH"
    'TZ=UTC'
    'LC_ALL=C'
  )
  local variable
  for variable in DOCKER_API_VERSION DOCKER_CERT_PATH DOCKER_CONFIG DOCKER_CONTEXT DOCKER_HOST DOCKER_TLS_VERIFY; do
    if [[ -v "$variable" ]]; then clean_env+=("$variable=${!variable}"); fi
  done
  /usr/bin/env -i "${clean_env[@]}" "$@"
}

release_base="$root/__dev/releases"
release_dir="$release_base/$release_sha"
prepare_release_root
staging_dir="$(mktemp -d "$release_base/.${release_sha}.build.XXXXXX")"
cleanup_staging=1
cleanup() {
  if (( cleanup_staging == 1 )) && [[ -d "$staging_dir" && ! -L "$staging_dir" ]] &&
    [[ "$(readlink -f -- "$staging_dir")" == "$staging_dir" ]]; then
    find "$staging_dir" -depth -mindepth 1 -delete
    rmdir "$staging_dir"
  fi
}
trap cleanup EXIT

work="$staging_dir/work"
payload="$staging_dir/payload"
mkdir -p "$work" "$payload"

# The archive is the only source input. No runtime root is copied from the
# checkout, so ignored or uncommitted files cannot enter a release.
git archive --format=tar "$release_sha" | tar -xf - -C "$work"

for required_lock in \
  composer.lock \
  wp-content/themes/goetz-legal/composer.lock \
  wp-content/themes/goetz-legal/package-lock.json \
  wp-content/plugins/goetz-site/package-lock.json \
  tests/e2e/package-lock.json; do
  [[ -s "$work/$required_lock" ]] || fail "archived release is missing lockfile: $required_lock"
done

run_composer_install() {
  local working_directory="$1"
  local container_working_directory='/work'
  if [[ "$working_directory" != "$work" ]]; then
    container_working_directory="/work/${working_directory#"$work"/}"
  fi
  if command -v composer >/dev/null 2>&1; then
    (
      cd "$working_directory"
      build_clean_exec composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
    )
    return
  fi
  build_clean_exec docker run --rm --read-only \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp/composer-home \
    --env COMPOSER_HOME=/tmp/composer-home \
    --env COMPOSER_CACHE_DIR=/tmp/composer-cache \
    --env "SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH" \
    --tmpfs /tmp:rw,nosuid,nodev,size=512m \
    --volume "$work:/work" \
    --workdir "$container_working_directory" \
    'composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c' \
    composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
}

run_npm_build() {
  local working_directory="$1"
  local container_working_directory='/work'
  if [[ "$working_directory" != "$work" ]]; then
    container_working_directory="/work/${working_directory#"$work"/}"
  fi
  if command -v npm >/dev/null 2>&1; then
    (
      cd "$working_directory"
      build_clean_exec npm ci
      build_clean_exec npm run build
    )
    return
  fi
  build_clean_exec docker run --rm --read-only \
    --user "$(id -u):$(id -g)" \
    --env HOME=/tmp/node-home \
    --env npm_config_cache=/tmp/node-cache \
    --env "SOURCE_DATE_EPOCH=$SOURCE_DATE_EPOCH" \
    --tmpfs /tmp:rw,nosuid,nodev,size=512m \
    --volume "$work:/work" \
    --workdir "$container_working_directory" \
    'node:22.14.0-bookworm@sha256:e5ddf893cc6aeab0e5126e4edae35aa43893e2836d1d246140167ccc2616f5d7' \
    sh -ceu 'npm ci && npm run build'
}

run_composer_install "$work"
run_composer_install "$work/wp-content/themes/goetz-legal"
run_npm_build "$work/wp-content/themes/goetz-legal"
run_npm_build "$work/wp-content/plugins/goetz-site"

[[ -s "$work/wp-content/themes/goetz-legal/dist/.vite/manifest.json" ]] || fail 'theme build did not emit dist/.vite/manifest.json'
find "$work/wp-content/themes/goetz-legal/dist/assets" -maxdepth 1 -type f -name '*.css' -print -quit | grep -q . ||
  fail 'theme build did not emit CSS'
find "$work/wp-content/themes/goetz-legal/dist/assets" -maxdepth 1 -type f -name '*.js' -print -quit | grep -q . ||
  fail 'theme build did not emit JavaScript'
[[ -s "$work/wp-content/themes/goetz-legal/vendor/autoload.php" ]] || fail 'theme production Composer vendor is missing'
[[ -s "$work/wp-content/plugins/goetz-site/build/index.js" ]] || fail 'goetz-site block JavaScript is missing'
[[ -s "$work/wp-content/plugins/goetz-site/build/index.asset.php" ]] || fail 'goetz-site block asset metadata is missing'

copy_runtime_root() {
  local relative_root="$1"
  [[ "$relative_root" != /* && "$relative_root" != *'..'* ]] || fail "unsafe runtime root: $relative_root"
  (
    cd "$work"
    tar \
      --exclude='.git' \
      --exclude='.git/*' \
      --exclude='.env*' \
      --exclude='*/.env*' \
      --exclude='*/node_modules' \
      --exclude='*/node_modules/*' \
      --exclude='*/tests' \
      --exclude='*/tests/*' \
      --exclude='*/__tests__' \
      --exclude='*/__tests__/*' \
      --exclude='*.test.*' \
      --exclude='*.spec.*' \
      --exclude='*/screenshots' \
      --exclude='*/screenshots/*' \
      --exclude='*/artifacts' \
      --exclude='*/artifacts/*' \
      --exclude='*.map' \
      --exclude='*.sql' \
      -cf - "$relative_root"
  ) | tar -xf - -C "$payload"
}

copy_runtime_root 'wp-content/themes/goetz-legal'
copy_runtime_root 'wp-content/plugins/goetz-site'
copy_runtime_root 'wp-content/plugins/goetz-migration'
copy_runtime_root 'wp-content/plugins/wordpress-seo'
copy_runtime_root 'wp-content/plugins/wpforms-lite'

header_value() {
  local header="$1"
  local path="$2"
  awk -F: -v wanted="$header" '
    BEGIN { IGNORECASE = 1 }
    {
      key = $1
      gsub(/^[[:space:]/*#]+|[[:space:]]+$/, "", key)
      if (tolower(key) == tolower(wanted)) {
        value = substr($0, index($0, ":") + 1)
        gsub(/^[[:space:]]+|[[:space:]*/]+$/, "", value)
        print value
        exit
      }
    }
  ' "$path"
}

theme_version="$(header_value 'Version' "$payload/wp-content/themes/goetz-legal/style.css")"
site_version="$(header_value 'Version' "$payload/wp-content/plugins/goetz-site/goetz-site.php")"
migration_version="$(header_value 'Version' "$payload/wp-content/plugins/goetz-migration/goetz-migration.php")"
yoast_version="$(header_value 'Version' "$payload/wp-content/plugins/wordpress-seo/wp-seo.php")"
wpforms_version="$(header_value 'Version' "$payload/wp-content/plugins/wpforms-lite/wpforms.php")"
[[ "$theme_version" == '1.0.0' ]] || fail "unexpected goetz-legal version: $theme_version"
[[ "$site_version" == '1.0.0' ]] || fail "unexpected goetz-site version: $site_version"
[[ "$migration_version" == '1.1.0' ]] || fail "unexpected goetz-migration version: $migration_version"
[[ "$yoast_version" == '28.0' ]] || fail "unexpected Yoast version: $yoast_version"
[[ "$wpforms_version" == '1.10.0.4' ]] || fail "unexpected WPForms Lite version: $wpforms_version"

commit_time_utc="$(date -u -d "@$SOURCE_DATE_EPOCH" '+%Y-%m-%dT%H:%M:%SZ')"
root_composer_hash="$(sha256sum "$work/composer.lock" | cut -d' ' -f1)"
theme_composer_hash="$(sha256sum "$work/wp-content/themes/goetz-legal/composer.lock" | cut -d' ' -f1)"
theme_node_hash="$(sha256sum "$work/wp-content/themes/goetz-legal/package-lock.json" | cut -d' ' -f1)"
site_node_hash="$(sha256sum "$work/wp-content/plugins/goetz-site/package-lock.json" | cut -d' ' -f1)"
e2e_node_hash="$(sha256sum "$work/tests/e2e/package-lock.json" | cut -d' ' -f1)"

cat > "$payload/release.json" <<JSON
{
  "schema_version": 1,
  "commit": "$release_sha",
  "branch": "main",
  "commit_time_utc": "$commit_time_utc",
  "source_date_epoch": $SOURCE_DATE_EPOCH,
  "wordpress_compatibility": {
    "minimum": "6.9",
    "tested": ["6.9.4", "7.0.1"]
  },
  "php": "8.3",
  "plugin_versions": {
    "goetz-legal": "$theme_version",
    "goetz-site": "$site_version",
    "goetz-migration": "$migration_version",
    "wordpress-seo": "$yoast_version",
    "wpforms-lite": "$wpforms_version"
  },
  "lock_hashes": {
    "composer.lock": "$root_composer_hash",
    "wp-content/themes/goetz-legal/composer.lock": "$theme_composer_hash",
    "wp-content/themes/goetz-legal/package-lock.json": "$theme_node_hash",
    "wp-content/plugins/goetz-site/package-lock.json": "$site_node_hash",
    "tests/e2e/package-lock.json": "$e2e_node_hash"
  }
}
JSON

if find "$payload" -type l -print -quit | grep -q .; then
  fail 'release payload contains a symbolic link'
fi
find "$payload" -type d -exec chmod 0755 {} +
find "$payload" -type f -exec chmod 0644 {} +
find "$payload" -print0 | xargs -0 touch -h -d "@$SOURCE_DATE_EPOCH"

(
  cd "$payload"
  find . -type f ! -path './RELEASE-MANIFEST.sha256' -print0 |
    LC_ALL=C sort -z |
    xargs -0 sha256sum > RELEASE-MANIFEST.sha256
)
chmod 0644 "$payload/RELEASE-MANIFEST.sha256"
touch -d "@$SOURCE_DATE_EPOCH" "$payload/RELEASE-MANIFEST.sha256"

"$work/scripts/release/verify.sh" "$payload" "$release_sha" >/dev/null

if [[ -e "$release_dir" ]]; then
  [[ -d "$release_dir" && ! -L "$release_dir" ]] || fail 'existing release path is not a normal directory'
  [[ "$release_dir" == "$release_base/$release_sha" ]] || fail 'refusing to replace an unresolved release path'
  [[ "$(readlink -f -- "$release_dir")" == "$release_dir" ]] || fail 'existing release path is redirected'
  find "$release_dir" -depth -mindepth 1 -delete
  rmdir "$release_dir"
fi
mv "$staging_dir" "$release_dir"
cleanup_staging=0

manifest_hash="$(sha256sum "$release_dir/payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)"
printf 'release_commit=%s\n' "$release_sha"
printf 'release_directory=%s\n' "$release_dir"
printf 'manifest_sha256=%s\n' "$manifest_hash"
