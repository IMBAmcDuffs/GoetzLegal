#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() {
  printf 'release-payload: %s\n' "$1" >&2
  exit 1
}

assert_contains() {
  local path="$1"
  local expected="$2"
  grep -Fq -- "$expected" "$path" || fail "$path is missing: $expected"
}

assert_not_contains() {
  local path="$1"
  local forbidden="$2"
  ! grep -Fq -- "$forbidden" "$path" || fail "$path contains forbidden text: $forbidden"
}

readonly -a RELEASE_SCRIPTS=(
  build.sh
  verify.sh
  remote-backup.sh
  remote-apply.sh
  verify-remote.sh
  cutover.sh
  rollback.sh
)

for script in "${RELEASE_SCRIPTS[@]}"; do
  [[ -x "scripts/release/$script" ]] || fail "missing executable release script: scripts/release/$script"
  bash -n "scripts/release/$script"
done
bash -n scripts/release/common.sh

assert_release_ssh_options_parse() (
  local array_config parse_fixture real_ssh rsync_config
  local -a rsync_ssh=()

  parse_fixture="$(mktemp -d "${TMPDIR:-/tmp}/goetz-release-ssh-parse.XXXXXX")"
  trap 'rm -rf "$parse_fixture"' EXIT
  real_ssh="$(command -v ssh)"
  [[ -n "$real_ssh" ]] || fail 'OpenSSH client is required for release transport contract tests'

  mkdir -p "$parse_fixture/bin"
  cat > "$parse_fixture/bin/ssh-add" <<'SH'
#!/usr/bin/env bash
exit 0
SH
  chmod +x "$parse_fixture/bin/ssh-add"
  printf '[163.192.209.112]:43854 ssh-ed25519 contract-placeholder\n' > "$parse_fixture/known_hosts"
  touch "$parse_fixture/agent.sock"

  PATH="$parse_fixture/bin:$PATH"
  KINSTA_SSH_USER='goetzgoetz'
  KINSTA_SSH_HOST='163.192.209.112'
  KINSTA_SSH_PORT='43854'
  KINSTA_SITE_PATH='/www/goetzgoetz_755/public'
  KINSTA_KNOWN_HOSTS_FILE="$parse_fixture/known_hosts"
  SSH_AUTH_SOCK="$parse_fixture/agent.sock"
  # shellcheck source=scripts/release/common.sh
  source scripts/release/common.sh
  goetz_require_kinsta

  array_config="$("$real_ssh" -G "${GOETZ_SSH_OPTIONS[@]}" "$GOETZ_REMOTE")" ||
    fail 'OpenSSH rejected the release SSH option array'
  grep -Fxq 'identityfile none' <<< "$array_config" || fail 'parsed release SSH options may read identity files'
  grep -Fxq 'identitiesonly no' <<< "$array_config" || fail 'parsed release SSH options may suppress the isolated agent identity'
  grep -Fxq 'globalknownhostsfile /dev/null' <<< "$array_config" || fail 'parsed release SSH options may trust global known-host files'
  grep -Fxq "userknownhostsfile $KINSTA_KNOWN_HOSTS_FILE" <<< "$array_config" ||
    fail 'parsed release SSH options do not pin the approved user known-host file'
  read -r -a rsync_ssh <<< "$GOETZ_RSYNC_SHELL"
  [[ "${rsync_ssh[0]:-}" == ssh ]] || fail 'release rsync shell does not invoke ssh'
  rsync_ssh[0]="$real_ssh"
  rsync_config="$("${rsync_ssh[@]}" -G "$GOETZ_REMOTE")" ||
    fail 'OpenSSH rejected the release rsync SSH shell'
  grep -Fxq 'identityfile none' <<< "$rsync_config" || fail 'parsed release rsync shell may read identity files'
  grep -Fxq 'identitiesonly no' <<< "$rsync_config" || fail 'parsed release rsync shell may suppress the isolated agent identity'
  grep -Fxq 'globalknownhostsfile /dev/null' <<< "$rsync_config" || fail 'parsed release rsync shell may trust global known-host files'
  grep -Fxq "userknownhostsfile $KINSTA_KNOWN_HOSTS_FILE" <<< "$rsync_config" ||
    fail 'parsed release rsync shell does not pin the approved user known-host file'
)

assert_release_ssh_options_parse

for transport_guard in \
  '-F /dev/null' \
  'ForwardAgent=no' \
  'ClearAllForwardings=yes' \
  'IdentityFile=none' \
  'IdentitiesOnly=no' \
  'GlobalKnownHostsFile=/dev/null' \
  'ProxyCommand=none' \
  'ConnectTimeout=15'; do
  assert_contains scripts/release/common.sh "$transport_guard"
done
assert_not_contains scripts/release/common.sh 'SendEnv='
assert_contains scripts/release/remote-backup.sh 'BACKUP-METADATA'
assert_contains scripts/release/remote-backup.sh 'purpose='
assert_contains scripts/release/remote-backup.sh 'release_manifest_sha256='
for remote_script in remote-backup.sh remote-apply.sh cutover.sh rollback.sh; do
  assert_contains "scripts/release/$remote_script" 'wp --path='
done
for route_script in remote-apply.sh verify-remote.sh rollback.sh; do
  assert_contains "scripts/release/$route_script" "--proto-redir '=https'"
  assert_contains "scripts/release/$route_script" "--write-out '%{url_effective}'"
  assert_contains "scripts/release/$route_script" '--connect-timeout 10'
  assert_contains "scripts/release/$route_script" '--max-time 30'
  assert_contains "scripts/release/$route_script" 'effective URL escaped the exact requested route'
done
for manager_command in release:build release:verify remote:backup remote:deploy remote:cutover remote:rollback verify:remote; do
  grep -Fq "${manager_command})" manager.sh || fail "manager is missing release command: $manager_command"
done
assert_contains manager.sh 'release_clean_exec'
assert_contains manager.sh '/usr/bin/env -i'

# The builder must operate on an immutable pushed commit, never on the current
# checkout, and consume only dependency locks.
assert_contains scripts/release/build.sh 'git status --porcelain'
assert_contains scripts/release/build.sh 'refs/remotes/origin/main'
assert_contains scripts/release/build.sh 'git archive'
assert_contains scripts/release/build.sh 'SOURCE_DATE_EPOCH'
assert_contains scripts/release/build.sh 'composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress'
assert_contains scripts/release/build.sh 'npm ci'
assert_not_contains scripts/release/build.sh 'npm install'
assert_not_contains scripts/release/build.sh 'composer update'
assert_not_contains scripts/release/build.sh 'composer require'

fixture="$(mktemp -d "${TMPDIR:-/tmp}/goetz-release-payload.XXXXXX")"
agent_pid=''
cleanup() {
  if [[ -n "$agent_pid" ]]; then
    kill "$agent_pid" >/dev/null 2>&1 || true
  fi
  if [[ "${GOETZ_KEEP_RELEASE_FIXTURE:-0}" == 1 ]]; then
    printf 'release-payload fixture retained: %s\n' "$fixture" >&2
  else
    rm -rf "$fixture"
  fi
}
trap cleanup EXIT

build_repo="$fixture/build-repo"
mkdir -p \
  "$build_repo/scripts/release" \
  "$build_repo/wp-content/themes/goetz-legal/resources" \
  "$build_repo/wp-content/plugins/goetz-site/src" \
  "$build_repo/wp-content/plugins/goetz-migration" \
  "$build_repo/wp-content/plugins/wordpress-seo" \
  "$build_repo/wp-content/plugins/wpforms-lite" \
  "$build_repo/tests/e2e" \
  "$fixture/build-bin"
cp scripts/release/build.sh scripts/release/verify.sh "$build_repo/scripts/release/"
printf '/__dev/\n' > "$build_repo/.gitignore"

cat > "$build_repo/composer.json" <<'JSON'
{"config":{"platform":{"php":"8.3.0"}}}
JSON
printf '{"content-hash":"root-lock"}\n' > "$build_repo/composer.lock"
cat > "$build_repo/wp-content/themes/goetz-legal/composer.json" <<'JSON'
{"config":{"platform":{"php":"8.3.0"}}}
JSON
printf '{"content-hash":"theme-lock"}\n' > "$build_repo/wp-content/themes/goetz-legal/composer.lock"
printf '{"name":"goetz-theme"}\n' > "$build_repo/wp-content/themes/goetz-legal/package.json"
printf '{"lockfileVersion":3}\n' > "$build_repo/wp-content/themes/goetz-legal/package-lock.json"
cat > "$build_repo/wp-content/themes/goetz-legal/style.css" <<'CSS'
/*
Theme Name: Goetz Legal
Version: 1.0.0
Requires at least: 6.9
Requires PHP: 8.0
*/
CSS
printf 'runtime theme source\n' > "$build_repo/wp-content/themes/goetz-legal/functions.php"

printf '{"name":"goetz-site"}\n' > "$build_repo/wp-content/plugins/goetz-site/package.json"
printf '{"lockfileVersion":3}\n' > "$build_repo/wp-content/plugins/goetz-site/package-lock.json"
printf 'runtime editor source\n' > "$build_repo/wp-content/plugins/goetz-site/src/index.js"
printf 'must not ship beside runtime source\n' > "$build_repo/wp-content/plugins/goetz-site/src/stable-blocks.test.js"
printf 'must not ship beside runtime source\n' > "$build_repo/wp-content/plugins/goetz-site/src/editor.spec.php"
cat > "$build_repo/wp-content/plugins/goetz-site/goetz-site.php" <<'PHP'
<?php
/*
Plugin Name: Goetz Site
Version: 1.0.0
Requires at least: 6.9
Requires PHP: 8.0
*/
PHP
cat > "$build_repo/wp-content/plugins/goetz-migration/goetz-migration.php" <<'PHP'
<?php
/*
Plugin Name: Goetz Legal Migration Tool
Version: 1.1.0
Requires PHP: 8.0
*/
PHP
cat > "$build_repo/wp-content/plugins/wordpress-seo/wp-seo.php" <<'PHP'
<?php
/*
Plugin Name: Yoast SEO
Version: 28.0
*/
PHP
cat > "$build_repo/wp-content/plugins/wpforms-lite/wpforms.php" <<'PHP'
<?php
/*
Plugin Name: WPForms Lite
Version: 1.10.0.4
*/
PHP
printf '{"lockfileVersion":3}\n' > "$build_repo/tests/e2e/package-lock.json"

cat > "$fixture/build-bin/composer" <<'COMPOSER'
#!/usr/bin/env bash
set -euo pipefail
fake_root="$(cd "${0%/*}/.." && pwd)"
printf '%s\n' "$*" >> "$fake_root/fake-build.log"
/usr/bin/env | /usr/bin/sort >> "$fake_root/fake-build-env.log"
if [[ "$PWD" == */wp-content/themes/goetz-legal ]]; then
  mkdir -p vendor
  printf '<?php // deterministic theme autoloader\n' > vendor/autoload.php
else
  mkdir -p vendor
  printf 'root dev vendor must not ship\n' > vendor/root-only.txt
fi
COMPOSER
cat > "$fixture/build-bin/npm" <<'NPM'
#!/usr/bin/env bash
set -euo pipefail
fake_root="$(cd "${0%/*}/.." && pwd)"
printf '%s\n' "$*" >> "$fake_root/fake-build.log"
/usr/bin/env | /usr/bin/sort >> "$fake_root/fake-build-env.log"
if [[ "$1" == 'ci' ]]; then
  mkdir -p node_modules
  printf 'must not ship\n' > node_modules/local-only.txt
  exit 0
fi
if [[ "$PWD" == */wp-content/themes/goetz-legal ]]; then
  mkdir -p dist/.vite dist/assets tests screenshots
  printf '{"resources/ts/app.ts":{"file":"assets/app.js","css":["assets/app.css"]}}\n' > dist/.vite/manifest.json
  printf 'console.log("theme");\n' > dist/assets/app.js
  printf 'body{}\n' > dist/assets/app.css
  printf '{}\n' > dist/assets/app.js.map
  printf 'must not ship\n' > tests/runtime.test.js
  printf 'must not ship\n' > screenshots/current.png
else
  mkdir -p build tests
  printf 'console.log("blocks");\n' > build/index.js
  printf '<?php return ["dependencies" => [], "version" => "contract"];\n' > build/index.asset.php
  printf '{}\n' > build/index.js.map
  printf 'must not ship\n' > tests/runtime.test.js
fi
NPM
chmod 700 "$fixture/build-bin/composer" "$fixture/build-bin/npm"
ln -s "$(command -v node)" "$fixture/build-bin/node"

git -C "$build_repo" init -q -b main
git -C "$build_repo" config user.name 'Release Contract'
git -C "$build_repo" config user.email 'release-contract@example.invalid'
git -C "$build_repo" add .
GIT_AUTHOR_DATE='2026-07-17T12:00:00Z' \
GIT_COMMITTER_DATE='2026-07-17T12:00:00Z' \
  git -C "$build_repo" commit -qm 'contract release'
release_sha="$(git -C "$build_repo" rev-parse HEAD)"
git -C "$build_repo" update-ref refs/remotes/origin/main "$release_sha"

SSH_KEY_PW=never-forward-build-secret GOETZ_BUILD_SENTINEL=never-forward-build-sentinel \
PATH="$fixture/build-bin:/usr/bin:/bin" \
  "$build_repo/scripts/release/build.sh" "$release_sha" >/dev/null
release_dir="$build_repo/__dev/releases/$release_sha"
payload="$release_dir/payload"
[[ -d "$payload" ]] || fail 'clean builder did not create payload/'
first_manifest_hash="$(sha256sum "$payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)"
find "$payload" -type f -printf '%P\n' | LC_ALL=C sort > "$fixture/first-files"
find "$payload" -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > "$fixture/first-hashes"

SSH_KEY_PW=never-forward-build-secret GOETZ_BUILD_SENTINEL=never-forward-build-sentinel \
PATH="$fixture/build-bin:/usr/bin:/bin" \
  "$build_repo/scripts/release/build.sh" "$release_sha" >/dev/null
second_manifest_hash="$(sha256sum "$payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)"
find "$payload" -type f -printf '%P\n' | LC_ALL=C sort > "$fixture/second-files"
find "$payload" -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > "$fixture/second-hashes"
cmp -s "$fixture/first-files" "$fixture/second-files" || fail 'repeat build changed the payload file list'
cmp -s "$fixture/first-hashes" "$fixture/second-hashes" || fail 'repeat build changed payload file hashes'
[[ "$first_manifest_hash" == "$second_manifest_hash" ]] || fail 'repeat build changed aggregate manifest hash'

mapfile -t payload_roots < <(find "$payload" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${payload_roots[*]}" == 'RELEASE-MANIFEST.sha256 release.json wp-content' ]] ||
  fail "unexpected payload roots: ${payload_roots[*]}"
mapfile -t theme_roots < <(find "$payload/wp-content/themes" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${theme_roots[*]}" == 'goetz-legal' ]] || fail 'payload contains a non-allowlisted theme'
mapfile -t plugin_roots < <(find "$payload/wp-content/plugins" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${plugin_roots[*]}" == 'goetz-migration goetz-site wordpress-seo wpforms-lite' ]] ||
  fail "payload contains non-allowlisted plugin roots: ${plugin_roots[*]}"
[[ -s "$payload/wp-content/themes/goetz-legal/dist/.vite/manifest.json" ]] || fail 'theme Vite manifest is missing'
[[ -s "$payload/wp-content/themes/goetz-legal/vendor/autoload.php" ]] || fail 'theme production vendor is missing'
[[ -s "$payload/wp-content/plugins/goetz-site/build/index.js" ]] || fail 'site block build is missing'
[[ -s "$payload/wp-content/plugins/goetz-site/build/index.asset.php" ]] || fail 'site block asset metadata is missing'
! find "$payload" \( -name node_modules -o -name tests -o -name screenshots -o -name '*.map' -o -name '*.sql' -o -name '.env*' \) -print -quit | grep -q . ||
  fail 'payload contains a forbidden development, secret, SQL, or source-map entry'
! find "$payload" -type f \( -name '*.test.*' -o -name '*.spec.*' \) -print -quit | grep -q . ||
  fail 'payload contains a colocated test or specification file'
! grep -Fq 'aggregate' "$payload/release.json" || fail 'release.json must not contain its own aggregate hash'
for release_marker in \
  '"commit"' '"branch"' '"commit_time_utc"' '"wordpress_compatibility"' \
  '"php"' '"plugin_versions"' '"lock_hashes"'; do
  grep -Fq "$release_marker" "$payload/release.json" || fail "release.json is missing $release_marker"
done
grep -Fq "$release_sha" "$payload/release.json" || fail 'release.json commit does not match the built commit'
grep -Fq 'release.json' "$payload/RELEASE-MANIFEST.sha256" || fail 'manifest does not hash release.json'
! grep -Fq 'RELEASE-MANIFEST.sha256' "$payload/RELEASE-MANIFEST.sha256" || fail 'manifest hashes itself'
"$build_repo/scripts/release/verify.sh" "$release_dir" "$release_sha" >/dev/null
cp scripts/release/common.sh "$build_repo/scripts/release/common.sh"
(
  cd "$build_repo"
  GOETZ_RELEASE_ROOT="$build_repo/scripts/release"
  GOETZ_COMMAND_NAME='relative-release-contract'
  # shellcheck source=scripts/release/common.sh
  source "$GOETZ_RELEASE_ROOT/common.sh"
  goetz_release_identity "__dev/releases/$release_sha"
  [[ "$GOETZ_RELEASE_SHA" == "$release_sha" ]] ||
    fail 'relative release directory resolved the wrong commit identity'
)

invalid_schema="$fixture/invalid-schema"
cp -a "$payload" "$invalid_schema"
sed -i 's/"schema_version": 1/"schema_version": "1"/' "$invalid_schema/release.json"
(
  cd "$invalid_schema"
  find . -type f ! -name RELEASE-MANIFEST.sha256 -print0 |
    LC_ALL=C sort -z |
    xargs -0 sha256sum > RELEASE-MANIFEST.sha256
)
if "$build_repo/scripts/release/verify.sh" "$invalid_schema" "$release_sha" >/dev/null 2>&1; then
  fail 'release verifier accepted a string schema_version instead of integer 1'
fi

printf 'dirty\n' > "$build_repo/untracked-contract-file"
if PATH="$fixture/build-bin:/usr/bin:/bin" "$build_repo/scripts/release/build.sh" "$release_sha" >/dev/null 2>&1; then
  fail 'builder accepted a dirty working tree'
fi
rm -f "$build_repo/untracked-contract-file"
GIT_AUTHOR_DATE='2026-07-17T12:01:00Z' \
GIT_COMMITTER_DATE='2026-07-17T12:01:00Z' \
  git -C "$build_repo" commit --allow-empty -qm 'unpushed contract commit'
unpushed_sha="$(git -C "$build_repo" rev-parse HEAD)"
if PATH="$fixture/build-bin:/usr/bin:/bin" "$build_repo/scripts/release/build.sh" "$unpushed_sha" >/dev/null 2>&1; then
  fail 'builder accepted a commit that did not equal origin/main'
fi

grep -Fq 'install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress' "$fixture/fake-build.log" ||
  fail 'builder did not invoke locked production Composer installs'
[[ "$(grep -c '^ci$' "$fixture/fake-build.log")" -ge 4 ]] || fail 'builder did not invoke npm ci for both builds'
! grep -Fq 'SSH_KEY_PW' "$fixture/fake-build-env.log" || fail 'SSH_KEY_PW reached npm or Composer'
! grep -Fq 'never-forward-build-secret' "$fixture/fake-build-env.log" || fail 'synthetic SSH secret reached npm or Composer'
! grep -Fq 'GOETZ_BUILD_SENTINEL' "$fixture/fake-build-env.log" || fail 'non-allowlisted build environment reached npm or Composer'
! grep -Fq '/.env' "$fixture/fake-build-env.log" || fail 'an environment-file path reached npm or Composer'

# A release parent can be ignored by Git and still be redirected on disk. The
# builder must validate every physical ancestor before mkdir, mktemp, cleanup,
# or replacement can write outside the repository.
git -C "$build_repo" update-ref refs/remotes/origin/main "$unpushed_sha"
mv "$build_repo/__dev" "$fixture/build-repo-dev-real"
mkdir -p "$fixture/redirected-build-parent"
printf 'build parent sentinel\n' > "$fixture/redirected-build-parent/sentinel"
ln -s "$fixture/redirected-build-parent" "$build_repo/__dev"
build_parent_error="$fixture/build-parent.err"
if PATH="$fixture/build-bin:/usr/bin:/bin" \
  "$build_repo/scripts/release/build.sh" "$unpushed_sha" >/dev/null 2>"$build_parent_error"; then
  fail 'builder accepted a symlinked __dev release parent'
fi
assert_contains "$build_parent_error" 'release parent is unsafe or redirected'
[[ "$(cat "$fixture/redirected-build-parent/sentinel")" == 'build parent sentinel' ]] ||
  fail 'redirected build parent sentinel was modified'
[[ ! -e "$fixture/redirected-build-parent/releases" ]] ||
  fail 'builder wrote a releases directory through a symlinked parent'
unlink "$build_repo/__dev"
mv "$fixture/build-repo-dev-real" "$build_repo/__dev"

# Exercise the actual remote heredoc bodies against a mapped disposable Kinsta
# filesystem. The fake SSH transport rewrites only the fixed /www site prefix,
# then executes the exact script it received.
remote_repo="$fixture/remote-repo"
record_root="$fixture/fake-remote-records"
remote_root="$fixture/fake-remote-root"
remote_bin="$fixture/remote-bin"
remote_site="$remote_root/www/goetzgoetz_755/public"
remote_private="$remote_root/www/goetzgoetz_755/private"
remote_state="$fixture/fake-wp-state"
mkdir -p "$remote_repo/scripts/release" "$remote_repo/__dev" "$record_root" "$remote_bin" \
  "$remote_site/wp-content/plugins" "$remote_site/wp-content/themes" "$remote_site/wp-content/uploads" \
  "$remote_private" "$remote_state"
cp scripts/release/*.sh "$remote_repo/scripts/release/"
# Only the disposable copy points the fixed logical Kinsta prefix at the fake
# filesystem. Production sources retain the literal /www target.
sed -i "s|/www/goetzgoetz_755|$remote_root/www/goetzgoetz_755|g" \
  "$remote_repo/scripts/release/common.sh"
known_hosts="$fixture/known_hosts"
printf '[163.192.209.112]:43854 ssh-ed25519 CONTRACT-ONLY\n' > "$known_hosts"
printf '<?php // contract WordPress bootstrap\n' > "$remote_site/wp-load.php"
printf 'historical PHP Fatal error: this predates deployment\n' > "$remote_site/wp-content/debug.log"
printf 'original upload\n' > "$remote_site/wp-content/uploads/original.txt"
for relative in \
  wp-content/themes/goetz-legal \
  wp-content/plugins/goetz-site \
  wp-content/plugins/goetz-migration \
  wp-content/plugins/wordpress-seo \
  wp-content/plugins/wpforms-lite; do
  mkdir -p "$remote_site/$relative"
  printf 'old runtime for %s\n' "$relative" > "$remote_site/$relative/old.txt"
done
printf '%s\n' 'https://goetzgoetz.kinsta.cloud' > "$remote_state/home"
printf '%s\n' 'https://goetzgoetz.kinsta.cloud' > "$remote_state/siteurl"
printf '%s\n' 'goetz-legal' > "$remote_state/active-theme"
printf '%s\n' 'goetz-site' > "$remote_state/active-plugins"
printf '%s\n' 'kinsta-mu-plugins' > "$remote_state/must-use-plugins"
printf '0\n' > "$remote_state/homepage-applied"
printf '0\n' > "$remote_state/seo-applied"
printf '1\n' > "$remote_state/james-profile-state"
printf '0\n' > "$remote_state/gregory-profile-state"
printf 'original-db-state\n' > "$remote_state/db-marker"

cat > "$remote_bin/ssh-add" <<'SSHADD'
#!/usr/bin/env bash
set -euo pipefail
/usr/bin/env | /usr/bin/sort >> '__RECORD_ROOT__/transport.env'
printf '<%s>\n' "$@" >> '__RECORD_ROOT__/ssh-add.argv'
exit 0
SSHADD

cat > "$remote_bin/ssh" <<'SSH'
#!/usr/bin/env bash
set -euo pipefail
record_root='__RECORD_ROOT__'
remote_root='__REMOTE_ROOT__'
remote_bin='__REMOTE_BIN__'
count_file="$record_root/ssh-count"
count=0
[[ ! -s "$count_file" ]] || read -r count < "$count_file"
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
printf '<%s>\n' "$@" > "$record_root/ssh.$count.argv"
/usr/bin/env | /usr/bin/sort > "$record_root/ssh.$count.env"
input="$(cat)"
printf '%s\n' "$input" > "$record_root/ssh.$count.stdin"
arguments=("$@")
separator=-1
for index in "${!arguments[@]}"; do
  if [[ "${arguments[$index]}" == '--' ]]; then separator="$index"; break; fi
done
(( separator >= 0 )) || exit 88
remote_arguments=()
for (( index=separator + 1; index<${#arguments[@]}; index++ )); do
  remote_argument="${arguments[$index]}"
  if [[ "$remote_argument" == /www/goetzgoetz_755* ]]; then
    remote_argument="$remote_root$remote_argument"
  fi
  remote_arguments+=("$remote_argument")
done
mapped="${input//"$remote_root/www/goetzgoetz_755"/__GOETZ_ALREADY_MAPPED__}"
mapped="${mapped//\/www\/goetzgoetz_755/$remote_root\/www\/goetzgoetz_755}"
mapped="${mapped//__GOETZ_ALREADY_MAPPED__/$remote_root\/www\/goetzgoetz_755}"
set +e
/usr/bin/env -i HOME='__FAKE_HOME__' PATH="$remote_bin:/usr/bin:/bin" \
  /bin/bash -s -- "${remote_arguments[@]}" <<< "$mapped"
remote_status=$?
set -e
if [[ -f "$record_root/disconnect-after-apply" && "$input" == *'GOETZ_REMOTE_RELEASE_APPLY'* ]]; then
  find "$record_root/disconnect-after-apply" -delete
  exit 255
fi
if [[ -f "$record_root/disconnect-after-cutover" && "$input" == *'GOETZ_REMOTE_CUTOVER'* ]]; then
  find "$record_root/disconnect-after-cutover" -delete
  exit 255
fi
exit "$remote_status"
SSH

cat > "$remote_bin/rsync" <<'RSYNC'
#!/usr/bin/env bash
set -euo pipefail
record_root='__RECORD_ROOT__'
remote_root='__REMOTE_ROOT__'
count_file="$record_root/rsync-count"
count=0
[[ ! -s "$count_file" ]] || read -r count < "$count_file"
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
printf '<%s>\n' "$@" > "$record_root/rsync.$count.argv"
/usr/bin/env | /usr/bin/sort > "$record_root/rsync.$count.env"
arguments=("$@")
source_arg="${arguments[$((${#arguments[@]} - 2))]}"
destination_arg="${arguments[$((${#arguments[@]} - 1))]}"
if [[ -f "$record_root/fail-source-once" ]]; then
  read -r fail_source < "$record_root/fail-source-once"
  if [[ "$source_arg" == *"$fail_source"* ]]; then
    find "$record_root/fail-source-once" -delete
    exit 42
  fi
fi
if [[ -f "$record_root/fail-deploy-recovery" && "$source_arg" == *'/deploy-recovery-'* ]]; then
  exit 45
fi
if [[ "$source_arg" == *':/'* ]]; then
  remote_path="${source_arg#*:}"
  if [[ "$remote_path" == /www/goetzgoetz_755* ]]; then source_arg="$remote_root$remote_path"; else source_arg="$remote_path"; fi
fi
if [[ "$destination_arg" == *':/'* ]]; then
  remote_path="${destination_arg#*:}"
  if [[ "$remote_path" == /www/goetzgoetz_755* ]]; then destination_arg="$remote_root$remote_path"; else destination_arg="$remote_path"; fi
fi
[[ "$source_arg" == /* && "$destination_arg" == /* ]]
mkdir -p "$destination_arg"
if printf '%s\n' "$@" | grep -Eq '^--delete($|-)'; then
  find "$destination_arg" -xdev -depth -mindepth 1 -delete
fi
cp -a "${source_arg%/}/." "$destination_arg/"
RSYNC

cat > "$remote_bin/wp" <<'WP'
#!/usr/bin/env bash
set -euo pipefail
state='__REMOTE_STATE__'
record='__RECORD_ROOT__/wp.log'
printf '<%s>' "$@" >> "$record"
printf '\n' >> "$record"
[[ "${1:-}" == --path=* ]] || { printf 'missing explicit --path\n' >&2; exit 80; }
shift
command_name="${1:-}"
shift || true
case "$command_name" in
  eval)
    if [[ -f '__RECORD_ROOT__/multisite' ]]; then printf 'yes'; else printf 'no'; fi
    ;;
  option)
    action="$1"; name="$2"
    if [[ "$action" == get ]]; then cat "$state/$name"
    elif [[ "$action" == update ]]; then printf '%s\n' "$3" > "$state/$name"
    else exit 81; fi
    ;;
  core)
    [[ "$1" == version ]] && printf '6.9.4\n'
    ;;
  db)
    action="$1"; path="$2"
    if [[ "$action" == export ]]; then
      {
        printf 'home=%s\n' "$(cat "$state/home")"
        printf 'siteurl=%s\n' "$(cat "$state/siteurl")"
        printf 'theme=%s\n' "$(cat "$state/active-theme")"
        printf 'plugins=%s\n' "$(base64 -w0 "$state/active-plugins")"
        printf 'homepage=%s\n' "$(cat "$state/homepage-applied")"
        printf 'seo=%s\n' "$(cat "$state/seo-applied")"
        printf 'james_profile=%s\n' "$(cat "$state/james-profile-state")"
        printf 'gregory_profile=%s\n' "$(cat "$state/gregory-profile-state")"
        printf 'marker=%s\n' "$(cat "$state/db-marker")"
      } > "$path"
    elif [[ "$action" == import ]]; then
      sed -n 's/^home=//p' "$path" > "$state/home"
      sed -n 's/^siteurl=//p' "$path" > "$state/siteurl"
      sed -n 's/^theme=//p' "$path" > "$state/active-theme"
      sed -n 's/^plugins=//p' "$path" | base64 -d > "$state/active-plugins"
      sed -n 's/^homepage=//p' "$path" > "$state/homepage-applied"
      sed -n 's/^seo=//p' "$path" > "$state/seo-applied"
      sed -n 's/^james_profile=//p' "$path" > "$state/james-profile-state"
      sed -n 's/^gregory_profile=//p' "$path" > "$state/gregory-profile-state"
      sed -n 's/^marker=//p' "$path" > "$state/db-marker"
      if [[ -f '__RECORD_ROOT__/signal-next-rollback-db-import' ]]; then
        find '__RECORD_ROOT__/signal-next-rollback-db-import' -delete
        kill -TERM "$PPID"
        sleep 0.05
      fi
    else exit 82; fi
    ;;
  theme)
    action="$1"; shift
    if [[ "$action" == list ]]; then cat "$state/active-theme"
    elif [[ "$action" == activate ]]; then printf '%s\n' "$1" > "$state/active-theme"
    else exit 83; fi
    ;;
  plugin)
    action="$1"; shift
    case "$action" in
      list)
        if [[ " $* " == *' --status=must-use '* ]]; then cat "$state/must-use-plugins"; else cat "$state/active-plugins"; fi
        ;;
      get)
        plugin="$1"
        case "$plugin" in
          goetz-site) printf '1.0.0\n' ;;
          goetz-migration) printf '1.1.0\n' ;;
          wordpress-seo) printf '28.0\n' ;;
          wpforms-lite) printf '1.10.0.4\n' ;;
          *) exit 84 ;;
        esac
        ;;
      activate)
        temporary="$state/.plugins.$$"
        cp "$state/active-plugins" "$temporary"
        for plugin in "$@"; do printf '%s\n' "$plugin" >> "$temporary"; done
        awk 'NF && !seen[$0]++' "$temporary" | LC_ALL=C sort > "$state/active-plugins"
        find "$temporary" -delete
        if [[ -f '__RECORD_ROOT__/signal-next-deploy-plugin-activate' ]]; then
          find '__RECORD_ROOT__/signal-next-deploy-plugin-activate' -delete
          kill -TERM "$PPID"
          sleep 0.05
        fi
        ;;
      is-active)
        grep -Fqx "$1" "$state/active-plugins"
        ;;
      *) exit 85 ;;
    esac
    ;;
  goetz-site)
    group="$1"; shift
    action="${1:-}"
    if [[ "$group" == migrate && "$action" == homepage ]]; then
      shift
      if [[ " $* " == *' --dry-run '* ]]; then
        if [[ "$(cat "$state/homepage-applied")" == 1 ]]; then printf '{"status":"noop"}\n'; else printf '{"status":"ready"}\n'; fi
      else
        if [[ -f '__RECORD_ROOT__/fail-next-migration' ]]; then find '__RECORD_ROOT__/fail-next-migration' -delete; exit 43; fi
        if [[ "$(cat "$state/homepage-applied")" == 1 ]]; then printf '{"status":"noop"}\n'; else printf '1\n' > "$state/homepage-applied"; printf '{"status":"updated"}\n'; fi
      fi
    elif [[ "$group" == attorney-profile ]]; then
      slug=''
      apply=0
      verify=0
      for argument in "$@"; do
        case "$argument" in
          --slug=james-l-goetz|--slug=gregory-w-goetz) slug="${argument#*=}" ;;
          --apply) apply=1 ;;
          --verify) verify=1 ;;
          *) exit 86 ;;
        esac
      done
      [[ -n "$slug" && ! ( "$apply" == 1 && "$verify" == 1 ) ]] || exit 86
      if [[ "$slug" == james-l-goetz ]]; then
        profile_state="$state/james-profile-state"
      else
        profile_state="$state/gregory-profile-state"
      fi
      read -r current_profile_state < "$profile_state"
      [[ "$current_profile_state" == 0 || "$current_profile_state" == 1 || "$current_profile_state" == 2 ||
        "$current_profile_state" == 3 || "$current_profile_state" == 4 || "$current_profile_state" == 5 ]] || exit 86
      if (( verify == 1 )); then
        if [[ "$slug" == gregory-w-goetz && -f '__RECORD_ROOT__/fail-next-gregory-profile-verify' ]]; then
          find '__RECORD_ROOT__/fail-next-gregory-profile-verify' -delete
          exit 48
        fi
        case "$current_profile_state" in
          1) printf '{"slug":"%s","status":"verified"}\n' "$slug" ;;
          2) printf '{"slug":"%s","status":"managed_modified"}\n' "$slug" ;;
          *) exit 48 ;;
        esac
      elif (( apply == 1 )); then
        [[ "$current_profile_state" == 0 ]] || exit 48
        printf '1\n' > "$profile_state"
        printf 'Success: Migrated attorney profile %s.\n' "$slug"
      else
        case "$current_profile_state" in
          0) printf '{"slug":"%s","status":"ready"}\n' "$slug" ;;
          1) printf '{"slug":"%s","status":"noop"}\n' "$slug" ;;
          2) printf '{"slug":"%s","status":"managed_modified"}\n' "$slug" ;;
          3) printf '{"slug":"%s","status":"conflict"}\n' "$slug" ;;
          4) printf '{"slug":"%s","status":"migration_evidence_mismatch"}\n' "$slug" ;;
          5) printf '{"slug":"%s","status":"version_conflict"}\n' "$slug" ;;
        esac
      fi
    elif [[ "$group" == seo && "$action" == configure ]]; then
      shift
      if [[ -f '__RECORD_ROOT__/force-cutover-seo-second-configured' ]]; then
        seo_count=0
        [[ ! -s '__RECORD_ROOT__/cutover-seo-count' ]] || read -r seo_count < '__RECORD_ROOT__/cutover-seo-count'
        if (( seo_count == 0 )); then
          printf '1\n' > '__RECORD_ROOT__/cutover-seo-count'
        else
          find '__RECORD_ROOT__/force-cutover-seo-second-configured' '__RECORD_ROOT__/cutover-seo-count' -delete
        fi
        printf '{"status":"configured"}\n'
        exit 0
      fi
      if [[ -f '__RECORD_ROOT__/fail-next-cutover-seo' ]]; then
        find '__RECORD_ROOT__/fail-next-cutover-seo' -delete
        exit 47
      fi
      if [[ "$(cat "$state/seo-applied")" == 1 ]]; then printf '{"status":"noop"}\n'; else printf '1\n' > "$state/seo-applied"; printf '{"status":"configured"}\n'; fi
    else exit 86; fi
    ;;
  search-replace)
    from="$1"; to="$2"; shift 2
    if [[ " $* " == *' --dry-run '* ]]; then printf 'Success: dry run\n'; else
      if [[ -f '__RECORD_ROOT__/fail-next-cutover' ]]; then
        printf '%s\n' "$to" > "$state/home"
        find '__RECORD_ROOT__/fail-next-cutover' -delete
        exit 44
      fi
      [[ "$(cat "$state/home")" == "$from" ]] && printf '%s\n' "$to" > "$state/home"
      [[ "$(cat "$state/siteurl")" == "$from" ]] && printf '%s\n' "$to" > "$state/siteurl"
      if [[ -f '__RECORD_ROOT__/signal-next-cutover-search-replace' ]]; then
        find '__RECORD_ROOT__/signal-next-cutover-search-replace' -delete
        kill -TERM "$PPID"
        sleep 0.05
      fi
    fi
    ;;
  yoast) ;;
  rewrite|cache|kinsta)
    failure_marker="__RECORD_ROOT__/fail-next-$command_name"
    if [[ -f "$failure_marker" ]]; then
      find "$failure_marker" -delete
      exit 46
    fi
    if [[ "$command_name" == kinsta && -f '__RECORD_ROOT__/rotate-debug-log' ]]; then
      mv '__REMOTE_SITE__/wp-content/debug.log' '__RECORD_ROOT__/debug-log-before-rotation'
      printf 'PHP Fatal error: replacement log must be scanned\n' > '__REMOTE_SITE__/wp-content/debug.log'
      truncate -s "$(stat -c %s '__RECORD_ROOT__/debug-log-before-rotation')" '__REMOTE_SITE__/wp-content/debug.log'
      find '__RECORD_ROOT__/rotate-debug-log' -delete
    fi
    if [[ "$command_name" == kinsta && -f '__RECORD_ROOT__/rewrite-debug-log-prefix' ]]; then
      cp '__REMOTE_SITE__/wp-content/debug.log' '__RECORD_ROOT__/debug-log-before-prefix-rewrite'
      original_size="$(stat -c %s '__REMOTE_SITE__/wp-content/debug.log')"
      printf 'PHP Fatal error: rewritten prefix must be scanned\n' > '__REMOTE_SITE__/wp-content/debug.log'
      truncate -s "$original_size" '__REMOTE_SITE__/wp-content/debug.log'
      find '__RECORD_ROOT__/rewrite-debug-log-prefix' -delete
    fi
    if [[ "$command_name" == kinsta && -f '__RECORD_ROOT__/rotate-debug-log-benign' ]]; then
      mv '__REMOTE_SITE__/wp-content/debug.log' '__RECORD_ROOT__/debug-log-before-benign-rotation'
      cp '__RECORD_ROOT__/debug-log-before-benign-rotation' '__REMOTE_SITE__/wp-content/debug.log'
      find '__RECORD_ROOT__/rotate-debug-log-benign' -delete
    fi
    if [[ "$command_name" == kinsta && -f '__RECORD_ROOT__/remove-debug-log' ]]; then
      mv '__REMOTE_SITE__/wp-content/debug.log' '__RECORD_ROOT__/debug-log-before-removal'
      find '__RECORD_ROOT__/remove-debug-log' -delete
    fi
    if [[ "$command_name" == kinsta && -f '__RECORD_ROOT__/symlink-debug-log' ]]; then
      mv '__REMOTE_SITE__/wp-content/debug.log' '__RECORD_ROOT__/debug-log-before-symlink'
      ln -s '__RECORD_ROOT__/debug-log-before-symlink' '__REMOTE_SITE__/wp-content/debug.log'
      find '__RECORD_ROOT__/symlink-debug-log' -delete
    fi
    ;;
  *) printf 'unsupported fake wp command: %s\n' "$command_name" >&2; exit 87 ;;
esac
WP

cat > "$remote_bin/php" <<'PHP'
#!/usr/bin/env bash
set -euo pipefail
emit=0
allowed="${@: -1}"
if [[ "$allowed" == emit ]]; then
  emit=1
  arguments=("$@")
  allowed="${arguments[$((${#arguments[@]} - 2))]}"
fi
document="$(cat)"
status="$(printf '%s' "$document" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
[[ -n "$status" && ",$allowed," == *",$status,"* ]]
if (( emit == 1 )); then printf '%s' "$status"; fi
PHP

cat > "$remote_bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
printf '<%s>\n' "$@" >> '__RECORD_ROOT__/curl.log'
url="${@: -1}"
write_effective=0
connect_timeout=0
max_time=0
arguments=("$@")
for (( index=0; index < ${#arguments[@]}; index++ )); do
  argument="${arguments[$index]}"
  case "$argument" in --write-out|-w|--write-out=*) write_effective=1 ;; esac
  if [[ "$argument" == '--connect-timeout' && "${arguments[$((index + 1))]:-}" == 10 ]]; then
    connect_timeout=1
  fi
  if [[ "$argument" == '--max-time' && "${arguments[$((index + 1))]:-}" == 30 ]]; then
    max_time=1
  fi
done
(( connect_timeout == 1 )) || { printf 'curl contract: missing exact --connect-timeout 10\n' >&2; exit 64; }
(( max_time == 1 )) || { printf 'curl contract: missing exact --max-time 30\n' >&2; exit 64; }
if [[ -f '__RECORD_ROOT__/append-debug-fatal-once' ]]; then
  printf 'PHP Fatal error: route smoke generated this failure\n' >> '__REMOTE_SITE__/wp-content/debug.log'
  find '__RECORD_ROOT__/append-debug-fatal-once' -delete
fi
if (( write_effective == 1 )); then
  effective="$url"
  if [[ -f '__RECORD_ROOT__/curl-effective-once' ]]; then
    read -r effective < '__RECORD_ROOT__/curl-effective-once'
    find '__RECORD_ROOT__/curl-effective-once' -delete
  fi
  printf '%s' "$effective"
fi
CURL

cat > "$remote_bin/tail" <<'TAIL'
#!/usr/bin/env bash
set -euo pipefail
status=0
/usr/bin/tail "$@" || status=$?
marker='__RECORD_ROOT__/append-debug-fatal-after-tail-count'
if [[ -f "$marker" ]]; then
  read -r remaining < "$marker"
  [[ "$remaining" =~ ^[1-9][0-9]*$ ]]
  remaining=$((remaining - 1))
  if (( remaining == 0 )); then
    printf 'PHP Fatal error: appended after the final tail read\n' >> '__REMOTE_SITE__/wp-content/debug.log'
    find "$marker" -delete
  else
    printf '%s\n' "$remaining" > "$marker"
  fi
fi
exit "$status"
TAIL

cat > "$remote_bin/flock" <<'FLOCK'
#!/usr/bin/env bash
set -euo pipefail
printf '<%s>\n' "$@" >> '__RECORD_ROOT__/flock.log'
if [[ -f '__RECORD_ROOT__/mutate-origin-on-lock' ]]; then
  read -r raced_origin < '__RECORD_ROOT__/mutate-origin-on-lock'
  printf '%s\n' "$raced_origin" > '__REMOTE_STATE__/home'
  printf '%s\n' "$raced_origin" > '__REMOTE_STATE__/siteurl'
  find '__RECORD_ROOT__/mutate-origin-on-lock' -delete
fi
exec /usr/bin/flock "$@"
FLOCK

for generated in ssh-add ssh rsync wp php curl tail flock; do
  sed -i \
    -e "s|__RECORD_ROOT__|$record_root|g" \
    -e "s|__REMOTE_ROOT__|$remote_root|g" \
    -e "s|__REMOTE_BIN__|$remote_bin|g" \
    -e "s|__REMOTE_STATE__|$remote_state|g" \
    -e "s|__REMOTE_SITE__|$remote_site|g" \
    -e "s|__FAKE_HOME__|$fixture/home|g" \
    "$remote_bin/$generated"
  chmod 700 "$remote_bin/$generated"
done

SSH_AUTH_SOCK="$fixture/agent.sock"
touch "$SSH_AUTH_SOCK"
remote_env=(
  HOME="$fixture/home"
  PATH="$remote_bin:$(dirname "$(command -v node)"):/usr/bin:/bin"
  KINSTA_SSH_USER=goetzgoetz
  KINSTA_SSH_HOST=163.192.209.112
  KINSTA_SSH_PORT=43854
  KINSTA_SITE_PATH="$remote_site"
  KINSTA_KNOWN_HOSTS_FILE="$known_hosts"
  SSH_AUTH_SOCK="$SSH_AUTH_SOCK"
  SSH_KEY_PW=never-forward-release-secret
)

run_remote() {
  local -a command=("$@")
  if [[ "${command[0]:-}" == "$remote_repo/scripts/release/remote-apply.sh" ]]; then
    command+=(--write-freeze-confirmed)
  fi
  /usr/bin/env -i "${remote_env[@]}" "${command[@]}"
}

ssh_before_missing_freeze="$(cat "$record_root/ssh-count" 2>/dev/null || printf '0\n')"
if /usr/bin/env -i "${remote_env[@]}" "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-missing-freeze >/dev/null 2>&1; then
  fail 'deployment accepted no operator write-freeze confirmation'
fi
ssh_after_missing_freeze="$(cat "$record_root/ssh-count" 2>/dev/null || printf '0\n')"
[[ "$ssh_after_missing_freeze" == "$ssh_before_missing_freeze" ]] ||
  fail 'deployment contacted Kinsta before rejecting a missing write-freeze confirmation'

# Local backup packets have the same containment requirement as build output.
# A redirected __dev ancestor must fail before transport or any external write.
mv "$remote_repo/__dev" "$fixture/remote-repo-dev-real"
mkdir -p "$fixture/redirected-local-backup-parent"
printf 'backup parent sentinel\n' > "$fixture/redirected-local-backup-parent/sentinel"
ln -s "$fixture/redirected-local-backup-parent" "$remote_repo/__dev"
ssh_before_local_parent="$(cat "$record_root/ssh-count" 2>/dev/null || printf '0\n')"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-local-parent --purpose=pre-deployment --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'remote backup accepted a symlinked local __dev parent'
fi
ssh_after_local_parent="$(cat "$record_root/ssh-count" 2>/dev/null || printf '0\n')"
[[ "$ssh_after_local_parent" == "$ssh_before_local_parent" ]] ||
  fail 'remote backup contacted the server before rejecting a redirected local parent'
[[ "$(cat "$fixture/redirected-local-backup-parent/sentinel")" == 'backup parent sentinel' ]] ||
  fail 'redirected local backup parent sentinel was modified'
[[ ! -e "$fixture/redirected-local-backup-parent/kinsta-backups" ]] ||
  fail 'remote backup created storage through a symlinked local parent'
unlink "$remote_repo/__dev"
mv "$fixture/remote-repo-dev-real" "$remote_repo/__dev"

# Deployments created before the append-only debug-prefix checkpoint have a
# strictly validated seven-field current-release receipt. A pre-deployment
# backup must accept and preserve that legacy state so automatic recovery can
# restore it and the operator can retry. The stronger pre-domain-cutover gate,
# however, must require the new eight-field receipt.
mkdir -p "$remote_private/state"
legacy_current_release="$fixture/legacy-current-release"
cat > "$legacy_current_release" <<LEGACY_CURRENT_RELEASE
schema_version=1
release_commit=$release_sha
release_manifest_sha256=$first_manifest_hash
backup_id=legacy-contract
deployed_utc=2026-07-17T12:00:00Z
debug_log_inode=none
debug_log_offset=0
LEGACY_CURRENT_RELEASE
cp "$legacy_current_release" "$remote_private/state/current-release"
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-legacy-recovery --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
legacy_recovery_backup="$remote_repo/__dev/kinsta-backups/contract-legacy-recovery"
cmp -s "$legacy_current_release" "$legacy_recovery_backup/previous-current-release" ||
  fail 'pre-deployment backup did not preserve a valid legacy current-release receipt'
printf 'wp-content/themes/goetz-legal\n' > "$record_root/fail-source-once"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-legacy-recovery >/dev/null 2>&1; then
  fail 'legacy receipt recovery fixture did not inject a deployment failure'
fi
cmp -s "$legacy_current_release" "$remote_private/state/current-release" ||
  fail 'automatic recovery did not restore the legacy current-release receipt'
assert_contains "$remote_private/operations/deploy-$release_sha-contract-legacy-recovery.status" \
  'phase=auto_rollback_succeeded'
sed -i 's/^debug_log_offset=0$/debug_log_offset=unsafe/' "$remote_private/state/current-release"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-invalid-legacy --purpose=pre-deployment --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'pre-deployment backup accepted a malformed legacy current-release receipt'
fi
cp "$legacy_current_release" "$remote_private/state/current-release"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-legacy-cutover --purpose=pre-domain-cutover --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'pre-domain-cutover backup accepted a legacy current-release receipt'
fi

# The deployment packet records the already-active goetz-site runtime required
# for the pre-mutation attorney-profile planner, and couples the intended
# release, staging origin, purpose, and timestamp.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-deploy --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
local_backup="$remote_repo/__dev/kinsta-backups/contract-deploy"
[[ "$(cat "$local_backup/active-plugins.txt")" == 'goetz-site' ]] ||
  fail 'backup did not preserve the required active goetz-site runtime'
for required_backup_entry in database.sql uploads.tar.gz code-state.tsv active-theme.txt home-url.txt site-url.txt \
  BACKUP-METADATA release-state.tsv SHA256SUMS LOCAL-VERIFICATION; do
  [[ -s "$local_backup/$required_backup_entry" ]] || fail "backup packet is missing $required_backup_entry"
done
assert_contains "$local_backup/BACKUP-METADATA" 'purpose=pre-deployment'
assert_contains "$local_backup/BACKUP-METADATA" "release_commit=$release_sha"
assert_contains "$local_backup/BACKUP-METADATA" "release_manifest_sha256=$first_manifest_hash"
(cd "$local_backup" && sha256sum -c SHA256SUMS >/dev/null) || fail 'downloaded backup hashes do not verify'

# A profile conflict created after the backup must be detected by the newly
# synced, already-active goetz-site code before any database write. Recovery
# restores code only: the client edit and post-backup upload remain intact and
# no older database or uploads packet is imported. The two migration-evidence
# conflict states have the same fail-closed boundary.
printf 'post-backup client upload\n' > "$remote_site/wp-content/uploads/post-backup-client-edit.txt"
preflight_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
for blocked_profile_state in 3 4 5; do
  printf '%s\n' "$blocked_profile_state" > "$remote_state/gregory-profile-state"
  if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
    --release-dir="$release_dir" --backup-id=contract-deploy >/dev/null 2>&1; then
    fail "deployment accepted blocked Gregory preflight state $blocked_profile_state"
  fi
  assert_contains "$remote_private/operations/deploy-$release_sha-contract-deploy.status" \
    'phase=auto_code_rollback_succeeded'
  [[ "$(cat "$remote_state/gregory-profile-state")" == "$blocked_profile_state" ]] ||
    fail "code-only recovery erased Gregory preflight state $blocked_profile_state"
  [[ "$(cat "$remote_site/wp-content/uploads/post-backup-client-edit.txt")" == 'post-backup client upload' ]] ||
    fail 'code-only recovery restored uploads over a post-backup client write'
  [[ -f "$remote_site/wp-content/plugins/goetz-site/old.txt" ]] ||
    fail 'code-only recovery did not restore the prior goetz-site runtime'
done
preflight_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( preflight_imports_after == preflight_imports_before )) ||
  fail 'attorney-profile preflight failure imported the older backup database'
find "$remote_site/wp-content/uploads/post-backup-client-edit.txt" -delete
printf '0\n' > "$remote_state/gregory-profile-state"

# Attorney migrations run after the read-only ownership preflight and before
# homepage writes. A failed post-migration verification must use full packet
# recovery because Gregory's profile and portrait may already have changed.
post_apply_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
touch "$record_root/fail-next-gregory-profile-verify"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy >/dev/null 2>&1; then
  fail 'deployment ignored a failed Gregory attorney-profile verification'
fi
assert_contains "$remote_private/operations/deploy-$release_sha-contract-deploy.status" \
  'phase=auto_rollback_succeeded'
post_apply_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( post_apply_imports_after == post_apply_imports_before + 1 )) ||
  fail 'post-apply attorney verification failure ran zero or multiple database restores'
[[ "$(cat "$remote_state/james-profile-state")" == 1 ]] ||
  fail 'attorney-profile recovery changed the pre-existing James profile'
[[ "$(cat "$remote_state/gregory-profile-state")" == 0 ]] ||
  fail 'attorney-profile recovery did not restore Gregory legacy content'

deploy_wp_start="$(wc -l < "$record_root/wp.log")"
run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy >/dev/null
deploy_wp_log="$fixture/deploy-success.wp.log"
tail -n "+$((deploy_wp_start + 1))" "$record_root/wp.log" > "$deploy_wp_log"
deploy_receipt="$remote_private/operations/deploy-$release_sha-contract-deploy.status"
assert_contains "$deploy_receipt" 'phase=complete'
assert_contains "$remote_private/state/current-release" "release_commit=$release_sha"
[[ -f "$remote_site/wp-content/debug.log" ]] || fail 'deployment removed the historical debug log'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] || fail 'deployment changed the staging origin'
[[ "$(cat "$remote_state/james-profile-state")" == 1 ]] || fail 'deployment regressed the current James profile'
[[ "$(cat "$remote_state/gregory-profile-state")" == 1 ]] || fail 'deployment did not migrate the Gregory profile'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=james-l-goetz>' "$deploy_wp_log")" -eq 3 ]] ||
  fail 'deployment did not preflight, preview, and post-preview James exactly once each'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=james-l-goetz><--apply>' "$deploy_wp_log" || true)" -eq 0 ]] ||
  fail 'deployment rewrote the already-current James profile'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=james-l-goetz><--verify>' "$deploy_wp_log")" -eq 1 ]] ||
  fail 'deployment did not verify James exactly once'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=gregory-w-goetz>' "$deploy_wp_log")" -eq 3 ]] ||
  fail 'deployment did not preflight, preview, and post-preview Gregory exactly once each'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=gregory-w-goetz><--apply>' "$deploy_wp_log")" -eq 1 ]] ||
  fail 'deployment did not apply the guarded Gregory migration exactly once'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=gregory-w-goetz><--verify>' "$deploy_wp_log")" -eq 1 ]] ||
  fail 'deployment did not verify Gregory exactly once'
awk '
  /<goetz-site><migrate><homepage><--apply><--format=json>/ { homepage_apply++; if (homepage_apply == 2) homepage_noop = NR }
  /<goetz-site><attorney-profile><--slug=james-l-goetz>$/ {
    james_preview++
    if (james_preview == 1) james_preflight = NR
    else if (james_preview == 2) james_first = NR
    else james_post = NR
  }
  /<goetz-site><attorney-profile><--slug=james-l-goetz><--verify>/ { james_verify = NR }
  /<goetz-site><attorney-profile><--slug=gregory-w-goetz>$/ {
    gregory_preview++
    if (gregory_preview == 1) gregory_preflight = NR
    else if (gregory_preview == 2) gregory_first = NR
    else gregory_post = NR
  }
  /<goetz-site><attorney-profile><--slug=gregory-w-goetz><--apply>/ { gregory_apply = NR }
  /<goetz-site><attorney-profile><--slug=gregory-w-goetz><--verify>/ { gregory_verify = NR }
  /<goetz-site><seo><configure><--strict><--format=json>/ { if (!seo) seo = NR }
  END {
    exit !(homepage_apply == 2 && james_preview == 3 && gregory_preview == 3 &&
      james_preflight < gregory_preflight && gregory_preflight < james_first &&
      james_first < james_post && james_post < james_verify && james_verify < gregory_first &&
      gregory_first < gregory_apply && gregory_apply < gregory_post && gregory_post < gregory_verify &&
      gregory_verify < homepage_noop && homepage_noop < seo)
  }
' "$deploy_wp_log" || fail 'attorney preflight, migrations, homepage, and SEO ran out of fail-closed order'
remote_verify_output="$fixture/remote-verify.out"
remote_verify_wp_start="$(wc -l < "$record_root/wp.log")"
run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud > "$remote_verify_output"
remote_verify_wp_log="$fixture/remote-verify.wp.log"
tail -n "+$((remote_verify_wp_start + 1))" "$record_root/wp.log" > "$remote_verify_wp_log"
assert_contains "$remote_verify_output" 'remote_verification=passed'
assert_contains "$remote_verify_output" "release_commit=$release_sha"
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=james-l-goetz><--verify>' "$remote_verify_wp_log")" -eq 1 ]] ||
  fail 'remote verifier did not verify James exactly once'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=gregory-w-goetz><--verify>' "$remote_verify_wp_log")" -eq 1 ]] ||
  fail 'remote verifier did not verify Gregory exactly once'

# Remote verification fails closed for an unmigrated profile, but accepts a
# versioned profile that a client has legitimately edited after migration.
printf '0\n' > "$remote_state/gregory-profile-state"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted an unmigrated Gregory profile'
fi
printf '1\n' > "$remote_state/gregory-profile-state"

printf '2\n' > "$remote_state/james-profile-state"
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-attorney-managed --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
managed_wp_start="$(wc -l < "$record_root/wp.log")"
run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-attorney-managed >/dev/null
managed_wp_log="$fixture/deploy-attorney-managed.wp.log"
tail -n "+$((managed_wp_start + 1))" "$record_root/wp.log" > "$managed_wp_log"
[[ "$(cat "$remote_state/james-profile-state")" == 2 ]] ||
  fail 'deployment overwrote a managed James profile editor change'
[[ "$(grep -Fxc '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=james-l-goetz><--apply>' "$managed_wp_log" || true)" -eq 0 ]] ||
  fail 'deployment applied over a managed James profile editor change'
grep -Fq '<--path='"$remote_site"'><goetz-site><attorney-profile><--slug=james-l-goetz><--verify>' "$managed_wp_log" ||
  fail 'deployment did not verify the managed James profile'
run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null
printf '1\n' > "$remote_state/james-profile-state"

# A dangling managed root is neither safely present nor safely absent. Backup
# discovery must reject it and must never publish a complete success receipt.
dangling_root="$remote_site/wp-content/plugins/goetz-site"
mv "$dangling_root" "$dangling_root.saved-for-dangling-backup"
ln -s "$fixture/missing-managed-root" "$dangling_root"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-dangling-backup --purpose=pre-deployment --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'remote backup treated a dangling managed code root as absent'
fi
dangling_backup_receipt="$remote_private/operations/backup-contract-dangling-backup.status"
if [[ -f "$dangling_backup_receipt" ]] && grep -Fq 'phase=complete' "$dangling_backup_receipt"; then
  fail 'remote backup wrote a complete receipt after encountering a dangling managed root'
fi
unlink "$dangling_root"
mv "$dangling_root.saved-for-dangling-backup" "$dangling_root"

mv "$remote_site/wp-content/uploads" "$remote_site/wp-content/uploads.saved-for-dangling-backup"
ln -s "$fixture/missing-uploads-root" "$remote_site/wp-content/uploads"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-dangling-uploads --purpose=pre-deployment --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'remote backup treated a dangling uploads root as absent'
fi
dangling_uploads_receipt="$remote_private/operations/backup-contract-dangling-uploads.status"
if [[ -f "$dangling_uploads_receipt" ]] && grep -Fq 'phase=complete' "$dangling_uploads_receipt"; then
  fail 'remote backup wrote a complete receipt after encountering dangling uploads'
fi
unlink "$remote_site/wp-content/uploads"
mv "$remote_site/wp-content/uploads.saved-for-dangling-backup" "$remote_site/wp-content/uploads"

# A packet that cannot be restored must never be certified. Symlinks and other
# special entries are unsupported in both uploads and managed runtime roots.
mkdir -p "$fixture/backup-symlink-target"
printf 'symlink target sentinel\n' > "$fixture/backup-symlink-target/sentinel"
ln -s "$fixture/backup-symlink-target" "$remote_site/wp-content/uploads/redirected-upload"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-upload-symlink --purpose=pre-deployment --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'remote backup certified uploads containing a symbolic link'
fi
upload_symlink_receipt="$remote_private/operations/backup-contract-upload-symlink.status"
if [[ -f "$upload_symlink_receipt" ]] && grep -Fq 'phase=complete' "$upload_symlink_receipt"; then
  fail 'remote backup wrote a complete receipt for symlinked uploads'
fi
unlink "$remote_site/wp-content/uploads/redirected-upload"

ln -s "$fixture/backup-symlink-target" "$remote_site/wp-content/plugins/goetz-site/redirected-code"
if run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-code-symlink --purpose=pre-deployment --release-dir="$release_dir" >/dev/null 2>&1; then
  fail 'remote backup certified a managed code root containing a symbolic link'
fi
code_symlink_receipt="$remote_private/operations/backup-contract-code-symlink.status"
if [[ -f "$code_symlink_receipt" ]] && grep -Fq 'phase=complete' "$code_symlink_receipt"; then
  fail 'remote backup wrote a complete receipt for a symlinked managed code root'
fi
unlink "$remote_site/wp-content/plugins/goetz-site/redirected-code"

# HTTPS status alone is insufficient: every smoke must prove that redirects
# remained on the exact approved origin and exact requested route.
printf 'https://redirected.example.invalid/\n' > "$record_root/curl-effective-once"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a smoke redirect to a different origin'
fi
printf 'https://goetzgoetz.kinsta.cloud/wrong-route/\n' > "$record_root/curl-effective-once"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a smoke redirect to a different route'
fi

# Verification compares every deployed byte in all five managed runtime roots,
# not just version strings or a few sentinel build artifacts.
runtime_php="$remote_site/wp-content/plugins/goetz-site/goetz-site.php"
published_payload="$remote_private/releases/$release_sha/payload"
printf '\n<?php // unapproved runtime drift\n' >> "$runtime_php"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted changed PHP in a managed runtime root'
fi
cp "$published_payload/wp-content/plugins/goetz-site/goetz-site.php" "$runtime_php"

runtime_extra="$remote_site/wp-content/plugins/goetz-site/unexpected-runtime.php"
printf '<?php // unexpected managed runtime file\n' > "$runtime_extra"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted an unexpected file in a managed runtime root'
fi
find "$runtime_extra" -delete

runtime_css="$remote_site/wp-content/themes/goetz-legal/dist/assets/app.css"
mv "$runtime_css" "$fixture/missing-runtime-app.css"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a missing generated CSS file'
fi
mv "$fixture/missing-runtime-app.css" "$runtime_css"

debug_size_before="$(stat -c %s "$remote_site/wp-content/debug.log")"
printf 'new PHP Fatal error: contract must reject this\n' >> "$remote_site/wp-content/debug.log"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a fatal error appended after deployment'
fi
truncate -s "$debug_size_before" "$remote_site/wp-content/debug.log"

debug_verify_original="$fixture/debug-log-before-verify-prefix-rewrite"
cp "$remote_site/wp-content/debug.log" "$debug_verify_original"
debug_verify_inode="$(stat -c %i "$remote_site/wp-content/debug.log")"
printf 'PHP Fatal error: verifier must reject rewritten prefix\n' > "$remote_site/wp-content/debug.log"
truncate -s "$debug_size_before" "$remote_site/wp-content/debug.log"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a fatal error hidden by same-inode debug-log prefix rewrite'
fi
[[ "$(stat -c %i "$remote_site/wp-content/debug.log")" == "$debug_verify_inode" ]] ||
  fail 'remote verifier prefix fixture did not preserve the debug-log inode'
cp "$debug_verify_original" "$remote_site/wp-content/debug.log"

# The append-only checkpoint is an identity-and-prefix promise, not merely an
# offset hint. Even a byte-identical rotation, removal, or symlink replacement
# destroys continuity and must fail closed.
debug_verify_rotation_original="$fixture/debug-log-before-verify-benign-rotation"
mv "$remote_site/wp-content/debug.log" "$debug_verify_rotation_original"
cp "$debug_verify_rotation_original" "$remote_site/wp-content/debug.log"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a byte-identical rotated debug log'
fi
find "$remote_site/wp-content/debug.log" -delete
mv "$debug_verify_rotation_original" "$remote_site/wp-content/debug.log"

debug_verify_missing_original="$fixture/debug-log-before-verify-removal"
mv "$remote_site/wp-content/debug.log" "$debug_verify_missing_original"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a missing debug-log checkpoint'
fi
mv "$debug_verify_missing_original" "$remote_site/wp-content/debug.log"

debug_verify_symlink_original="$fixture/debug-log-before-verify-symlink"
mv "$remote_site/wp-content/debug.log" "$debug_verify_symlink_original"
ln -s "$debug_verify_symlink_original" "$remote_site/wp-content/debug.log"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a symlinked debug-log checkpoint'
fi
unlink "$remote_site/wp-content/debug.log"
mv "$debug_verify_symlink_original" "$remote_site/wp-content/debug.log"

# A request can emit a fatal after the pre-smoke scan. Verification must scan
# the same checkpoint again after every route has been exercised.
debug_size_before_route_smoke="$(stat -c %s "$remote_site/wp-content/debug.log")"
touch "$record_root/append-debug-fatal-once"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier ignored a PHP fatal generated by a route smoke'
fi
truncate -s "$debug_size_before_route_smoke" "$remote_site/wp-content/debug.log"

# The final checkpoint scan must be stable through its last size check. A
# fatal appended after tail reaches EOF but before the final stat must fail
# closed instead of escaping the last post-smoke scan.
debug_size_before_scan_race="$(stat -c %s "$remote_site/wp-content/debug.log")"
printf 'benign byte before the scan race\n' >> "$remote_site/wp-content/debug.log"
printf '2\n' > "$record_root/append-debug-fatal-after-tail-count"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted debug-log growth during its final checkpoint scan'
fi
truncate -s "$debug_size_before_scan_race" "$remote_site/wp-content/debug.log"
find "$record_root/append-debug-fatal-after-tail-count" -delete 2>/dev/null || true

nested_dump="$remote_site/wp-content/plugins/goetz-site/runtime/cache/forgotten.sql"
mkdir -p "${nested_dump%/*}"
printf 'forbidden nested dump\n' > "$nested_dump"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a nested public SQL dump'
fi
find "$nested_dump" -delete
touch "$record_root/multisite"
if run_remote "$remote_repo/scripts/release/verify-remote.sh" \
  --release-dir="$release_dir" --origin=https://goetzgoetz.kinsta.cloud >/dev/null 2>&1; then
  fail 'remote verifier accepted a multisite installation'
fi
find "$record_root/multisite" -delete
printf 'unmanifested remote payload entry\n' > "$remote_private/releases/$release_sha/payload/unexpected.txt"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy >/dev/null 2>&1; then
  fail 'deployment reused a remote release containing an unmanifested file'
fi
find "$remote_private/releases/$release_sha/payload/unexpected.txt" -delete

# Simulate an interrupted private upload with a stale extra file. A retry must
# resume into that exact incoming directory, remove the stale file, verify it,
# and atomically publish the release.
published_release="$remote_private/releases/$release_sha"
find "$published_release" -xdev -depth -mindepth 1 -delete
rmdir "$published_release"
incoming_release="$remote_private/releases/.incoming-$release_sha-contract-deploy"

# The resumable upload destination itself is untrusted state. A redirected
# payload directory must be rejected before rsync receives a destination, so
# --delete-delay can never erase data through that link.
mkdir -p "$incoming_release" "$fixture/redirected-upload"
printf 'incoming upload sentinel\n' > "$fixture/redirected-upload/sentinel"
ln -s "$fixture/redirected-upload" "$incoming_release/payload"
rsync_before_redirect="$(cat "$record_root/rsync-count")"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy >/dev/null 2>&1; then
  fail 'deployment accepted a redirected incoming payload directory'
fi
rsync_after_redirect="$(cat "$record_root/rsync-count")"
[[ "$rsync_after_redirect" == "$rsync_before_redirect" ]] ||
  fail 'deployment invoked rsync before physically validating the incoming payload directory'
[[ "$(cat "$fixture/redirected-upload/sentinel")" == 'incoming upload sentinel' ]] ||
  fail 'redirected incoming payload data was modified'
unlink "$incoming_release/payload"
rmdir "$incoming_release"

mkdir -p "$incoming_release/payload"
printf 'stale interrupted upload\n' > "$incoming_release/payload/stale.txt"
run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy >/dev/null
[[ -d "$published_release" && ! -e "$published_release/payload/stale.txt" && ! -e "$incoming_release" ]] ||
  fail 'interrupted release upload was not safely resumed and atomically published'

# A concurrent state change at lock acquisition must invalidate the deployment
# preflight before the first runtime rsync. Checking the origin before the lock
# and then mutating from stale state is forbidden.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-apply-lock-race --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
rsync_before_apply_race="$(cat "$record_root/rsync-count")"
printf 'https://goetzlegal.com\n' > "$record_root/mutate-origin-on-lock"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-apply-lock-race >/dev/null 2>&1; then
  fail 'deployment accepted origin state that changed while acquiring the mutation lock'
fi
rsync_after_apply_race="$(cat "$record_root/rsync-count")"
[[ "$rsync_after_apply_race" == "$rsync_before_apply_race" ]] ||
  fail 'deployment began runtime mutation from a stale pre-lock origin check'
printf '%s\n' 'https://goetzgoetz.kinsta.cloud' > "$remote_state/home"
printf '%s\n' 'https://goetzgoetz.kinsta.cloud' > "$remote_state/siteurl"

# Deployment must not reuse a byte offset from a replaced debug log. Rotate the
# file after the preflight capture and write a shorter fatal-only replacement.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-rotation --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
printf 'historical debug bytes that are deliberately longer than the replacement fatal line; ignore these old bytes only\n' \
  > "$remote_site/wp-content/debug.log"
touch "$record_root/rotate-debug-log"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-rotation >/dev/null 2>&1; then
  fail 'deployment accepted a fatal error in a rotated replacement debug log'
fi
debug_rotation_receipt="$remote_private/operations/deploy-$release_sha-contract-debug-rotation.status"
assert_not_contains "$debug_rotation_receipt" 'phase=complete'
assert_contains "$debug_rotation_receipt" 'phase=auto_rollback_succeeded'
mv "$record_root/debug-log-before-rotation" "$remote_site/wp-content/debug.log"

# copytruncate can preserve the inode and regrow to the old offset. A prefix
# checkpoint must detect that the previously trusted bytes were rewritten.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-prefix --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
printf 'historical append-only prefix that is longer than the replacement fatal and must remain byte-for-byte stable\n' \
  > "$remote_site/wp-content/debug.log"
debug_prefix_inode="$(stat -c %i "$remote_site/wp-content/debug.log")"
touch "$record_root/rewrite-debug-log-prefix"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-prefix >/dev/null 2>&1; then
  fail 'deployment accepted a fatal error hidden by same-inode debug-log prefix rewrite'
fi
[[ "$(stat -c %i "$remote_site/wp-content/debug.log")" == "$debug_prefix_inode" ]] ||
  fail 'debug prefix fixture did not preserve the debug-log inode'
debug_prefix_receipt="$remote_private/operations/deploy-$release_sha-contract-debug-prefix.status"
assert_not_contains "$debug_prefix_receipt" 'phase=complete'
assert_contains "$debug_prefix_receipt" 'phase=auto_rollback_succeeded'
cp "$record_root/debug-log-before-prefix-rewrite" "$remote_site/wp-content/debug.log"

# An installation without debug.log still receives an auditable regular-file
# checkpoint before deployment mutation begins. Re-establish the historical
# fixture afterward so the remaining append-only tests retain a non-empty
# prefix and original inode.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-absent --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
debug_before_absent="$fixture/debug-log-before-absent-deploy"
mv "$remote_site/wp-content/debug.log" "$debug_before_absent"
run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-absent >/dev/null
[[ -f "$remote_site/wp-content/debug.log" && ! -L "$remote_site/wp-content/debug.log" ]] ||
  fail 'deployment did not create a regular debug-log checkpoint when the file was initially absent'
[[ "$(stat -c %a "$remote_site/wp-content/debug.log")" == '600' ]] ||
  fail 'deployment created debug.log with permissions broader than 0600'
grep -Eq '^debug_log_inode=[0-9]+$' "$remote_private/state/current-release" ||
  fail 'deployment recorded no numeric inode for its created debug-log checkpoint'
grep -Eq '^debug_log_prefix_sha256=[0-9a-f]{64}$' "$remote_private/state/current-release" ||
  fail 'deployment recorded no prefix hash for its created debug-log checkpoint'
find "$remote_site/wp-content/debug.log" -delete
mv "$debug_before_absent" "$remote_site/wp-content/debug.log"
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-recheckpoint --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-recheckpoint >/dev/null

# Continuity drift is itself release-blocking, even when the replacement log
# contains no fatal. Recovery restores runtime state; the fixture restores the
# original checkpoint inode before moving to the next case.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-benign-rotation --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
touch "$record_root/rotate-debug-log-benign"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-benign-rotation >/dev/null 2>&1; then
  fail 'deployment accepted a byte-identical rotated debug log'
fi
assert_contains "$remote_private/operations/deploy-$release_sha-contract-debug-benign-rotation.status" \
  'phase=auto_rollback_succeeded'
find "$remote_site/wp-content/debug.log" -delete
mv "$record_root/debug-log-before-benign-rotation" "$remote_site/wp-content/debug.log"

run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-removal --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
touch "$record_root/remove-debug-log"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-removal >/dev/null 2>&1; then
  fail 'deployment accepted removal of its debug-log checkpoint'
fi
assert_contains "$remote_private/operations/deploy-$release_sha-contract-debug-removal.status" \
  'phase=auto_rollback_succeeded'
mv "$record_root/debug-log-before-removal" "$remote_site/wp-content/debug.log"

run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-symlink --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
touch "$record_root/symlink-debug-log"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-symlink >/dev/null 2>&1; then
  fail 'deployment accepted a symlinked debug-log checkpoint'
fi
assert_contains "$remote_private/operations/deploy-$release_sha-contract-debug-symlink.status" \
  'phase=auto_rollback_succeeded'
unlink "$remote_site/wp-content/debug.log"
mv "$record_root/debug-log-before-symlink" "$remote_site/wp-content/debug.log"

run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-debug-route-fatal --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
debug_size_before_deploy_smoke="$(stat -c %s "$remote_site/wp-content/debug.log")"
touch "$record_root/append-debug-fatal-once"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-debug-route-fatal >/dev/null 2>&1; then
  fail 'deployment ignored a PHP fatal generated by a route smoke'
fi
assert_contains "$remote_private/operations/deploy-$release_sha-contract-debug-route-fatal.status" \
  'phase=auto_rollback_succeeded'
truncate -s "$debug_size_before_deploy_smoke" "$remote_site/wp-content/debug.log"

# Inject a mid-sync failure. The same remote process must restore while still
# holding the shared lock and leave a durable recovery receipt.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-failure --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
mid_sync_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
printf 'wp-content/themes/goetz-legal\n' > "$record_root/fail-source-once"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-failure >/dev/null 2>&1; then
  fail 'injected deployment failure unexpectedly succeeded'
fi
failure_receipt="$remote_private/operations/deploy-$release_sha-contract-failure.status"
assert_contains "$failure_receipt" 'phase=auto_code_rollback_succeeded'
mid_sync_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( mid_sync_imports_after == mid_sync_imports_before )) ||
  fail 'mid-sync code-only recovery imported the database'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] || fail 'deployment recovery did not restore the database origin'

# ERR must be handled only by the parent remote shell. A WP-CLI failure inside
# an assignment/command substitution must run packet recovery exactly once.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-substitution-failure --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
substitution_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
touch "$record_root/fail-next-migration"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-substitution-failure >/dev/null 2>&1; then
  fail 'deployment ignored an assignment/command-substitution failure'
fi
substitution_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( substitution_imports_after == substitution_imports_before + 1 )) ||
  fail 'deployment assignment failure ran recovery zero or multiple times'
assert_contains "$remote_private/operations/deploy-$release_sha-contract-substitution-failure.status" \
  'phase=auto_rollback_succeeded'

# TERM after mutation begins must use the same in-lock recovery path exactly
# once, leave a durable receipt, and remove its private work packet.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-deploy-signal --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
deploy_signal_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
touch "$record_root/signal-next-deploy-plugin-activate"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy-signal >/dev/null 2>&1; then
  fail 'deployment unexpectedly succeeded after an injected TERM'
fi
deploy_signal_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( deploy_signal_imports_after == deploy_signal_imports_before + 1 )) ||
  fail 'deployment TERM ran recovery zero or multiple times'
assert_contains "$remote_private/operations/deploy-$release_sha-contract-deploy-signal.status" \
  'phase=auto_rollback_succeeded'
! find "$remote_private" -maxdepth 1 -type d -name "deploy-recovery-$release_sha-contract-deploy-signal-*" -print -quit | grep -q . ||
  fail 'deployment TERM left a private recovery work directory'
! find "$remote_private/operations" -maxdepth 1 -type f -name ".deploy-$release_sha-contract-deploy-signal.preflight.*" -print -quit | grep -q . ||
  fail 'deployment TERM left a private preflight file'

# A recovery is not complete until the fixed Kinsta target confirms its page
# cache was purged. A purge failure must block the recovery receipt even when
# the packet database and files were otherwise restored successfully.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-recovery-purge --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
touch "$record_root/fail-next-migration" "$record_root/fail-next-kinsta"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-recovery-purge >/dev/null 2>&1; then
  fail 'deployment with an injected Kinsta purge failure during recovery unexpectedly succeeded'
fi
recovery_purge_receipt="$remote_private/operations/deploy-$release_sha-contract-recovery-purge.status"
assert_not_contains "$recovery_purge_receipt" 'phase=auto_rollback_succeeded'
assert_contains "$recovery_purge_receipt" 'phase=auto_rollback_failed_manual_intervention_required'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'deployment purge recovery failure did not at least restore the backup database'

# The normal deploy path has the same required purge gate. If that command
# fails, deployment must return non-zero and must not publish a complete
# receipt; the already-prepared recovery packet may still recover the site.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-deploy-purge --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
touch "$record_root/fail-next-kinsta"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-deploy-purge >/dev/null 2>&1; then
  fail 'deployment ignored an injected Kinsta purge failure on its normal path'
fi
deploy_purge_receipt="$remote_private/operations/deploy-$release_sha-contract-deploy-purge.status"
assert_not_contains "$deploy_purge_receipt" 'phase=complete'
assert_contains "$deploy_purge_receipt" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'deployment purge failure recovery did not restore the staging origin'

# If SSH disconnects after the remote process has already recovered, the local
# side reports ambiguity but never launches a second racing rollback process.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-disconnect --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
touch "$record_root/fail-next-migration" "$record_root/disconnect-after-apply"
deploy_disconnect_error="$fixture/deploy-disconnect.err"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-disconnect >/dev/null 2>"$deploy_disconnect_error"; then
  fail 'ambiguous transport-disconnect deployment unexpectedly succeeded locally'
fi
assert_contains "$deploy_disconnect_error" 'Recovery status is unknown'
assert_not_contains "$deploy_disconnect_error" 'Remote recovery ran while holding'
assert_contains "$remote_private/operations/deploy-$release_sha-contract-disconnect.status" 'phase=auto_rollback_succeeded'
imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( imports_after == imports_before + 1 )) || fail 'ambiguous disconnect caused zero or multiple database restores'
! grep -l 'GOETZ_REMOTE_ROLLBACK' "$record_root"/ssh.*.stdin >/dev/null 2>&1 ||
  fail 'deployment ambiguity launched a separate local rollback SSH process'

# A reviewed manual rollback must also fail closed when Kinsta does not accept
# the purge. It cannot claim completion merely because files, uploads, and the
# database were restored before the required cache operation failed.
touch "$record_root/fail-next-kinsta"
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-deploy-purge --apply >/dev/null 2>&1; then
  fail 'manual rollback ignored an injected Kinsta purge failure'
fi
rollback_purge_receipt="$remote_private/operations/rollback-contract-deploy-purge.status"
assert_not_contains "$rollback_purge_receipt" 'phase=complete'
assert_contains "$rollback_purge_receipt" 'phase=rollback_failed_manual_intervention_required'
# The one-shot failure has been consumed; a reviewed retry proves the packet
# remains usable and leaves subsequent contract scenarios on a known state.
run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-deploy-purge --apply >/dev/null

# A failure after activation/migration begins still restores the complete
# packet. If that recovery itself is injected to fail, the durable receipt is
# explicitly blocked and no later deploy phase runs; a reviewed rollback can
# then recover successfully.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-blocked --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
touch "$record_root/fail-next-migration" "$record_root/fail-deploy-recovery"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-blocked >/dev/null 2>&1; then
  fail 'deployment with an injected recovery failure unexpectedly succeeded'
fi
blocked_receipt="$remote_private/operations/deploy-$release_sha-contract-blocked.status"
assert_contains "$blocked_receipt" 'phase=auto_rollback_failed_manual_intervention_required'
find "$record_root/fail-deploy-recovery" -delete
run_remote "$remote_repo/scripts/release/rollback.sh" --backup-id=contract-blocked --apply >/dev/null
assert_contains "$remote_private/operations/rollback-contract-blocked.status" 'phase=complete'

# Dangling named roots must be rejected during the read-only preflight, before
# a deployment receipt or mutation exists. Rollback dry-run must reject the
# same ambiguous target instead of reporting that it is safely absent.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-dangling-target --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
mv "$remote_site/wp-content/plugins/goetz-site" "$remote_site/wp-content/plugins/goetz-site.saved-for-dangling-target"
ln -s "$fixture/missing-runtime-target" "$remote_site/wp-content/plugins/goetz-site"
rsync_before_dangling_target="$(cat "$record_root/rsync-count")"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-dangling-target >/dev/null 2>&1; then
  fail 'deployment accepted a dangling allowlisted target'
fi
rsync_after_dangling_target="$(cat "$record_root/rsync-count")"
[[ "$rsync_after_dangling_target" == "$rsync_before_dangling_target" ]] ||
  fail 'deployment reached rsync before rejecting a dangling allowlisted target'
[[ ! -e "$remote_private/operations/deploy-$release_sha-contract-dangling-target.status" ]] ||
  fail 'deployment wrote a mutation receipt before rejecting a dangling allowlisted target'
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-dangling-target --dry-run >/dev/null 2>&1; then
  fail 'rollback dry-run treated a dangling allowlisted target as absent'
fi
[[ ! -e "$remote_private/operations/rollback-contract-dangling-target.status" ]] ||
  fail 'rollback wrote a receipt while rejecting a dangling allowlisted target'
unlink "$remote_site/wp-content/plugins/goetz-site"
mv "$remote_site/wp-content/plugins/goetz-site.saved-for-dangling-target" "$remote_site/wp-content/plugins/goetz-site"

# A symlinked named target must fail during preflight before rsync can follow it
# or delete content in the redirected directory.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-symlink --purpose=pre-deployment --release-dir="$release_dir" >/dev/null
mv "$remote_site/wp-content/plugins/goetz-site" "$remote_site/wp-content/plugins/goetz-site.saved"
mkdir -p "$fixture/redirected-target"
printf 'must survive\n' > "$fixture/redirected-target/sentinel"
ln -s "$fixture/redirected-target" "$remote_site/wp-content/plugins/goetz-site"
if run_remote "$remote_repo/scripts/release/remote-apply.sh" \
  --release-dir="$release_dir" --backup-id=contract-symlink >/dev/null 2>&1; then
  fail 'deployment accepted a symlinked allowlisted target'
fi
[[ "$(cat "$fixture/redirected-target/sentinel")" == 'must survive' ]] || fail 'symlink containment failure modified redirected data'
unlink "$remote_site/wp-content/plugins/goetz-site"
mv "$remote_site/wp-content/plugins/goetz-site.saved" "$remote_site/wp-content/plugins/goetz-site"

# A pre-domain-cutover packet is accepted only while the exact deployed release
# receipt and exact staging URL remain coupled.
run_remote "$remote_repo/scripts/release/remote-backup.sh" \
  --backup-id=contract-cutover --purpose=pre-domain-cutover --release-dir="$release_dir" >/dev/null

# Rollback dry-run must independently reject a checksum-consistent packet whose
# code archive contains a non-regular entry; a name-only tar listing is not a
# sufficient restore rehearsal.
cutover_remote_backup="$remote_private/backups/contract-cutover"
cutover_local_backup="$remote_repo/__dev/kinsta-backups/contract-cutover"
cutover_backup_originals="$fixture/contract-cutover-originals"
mkdir -p "$cutover_backup_originals/remote" "$cutover_backup_originals/local"
for packet_file in code-plugin-goetz-site.tar.gz uploads.tar.gz SHA256SUMS; do
  cp -a "$cutover_remote_backup/$packet_file" "$cutover_backup_originals/remote/$packet_file"
  cp -a "$cutover_local_backup/$packet_file" "$cutover_backup_originals/local/$packet_file"
done
cp -a "$cutover_local_backup/LOCAL-VERIFICATION" "$cutover_backup_originals/local/LOCAL-VERIFICATION"

refresh_cutover_packet_checksums() {
  local manifest_hash
  (
    cd "$cutover_remote_backup"
    find . -maxdepth 1 -type f ! -name SHA256SUMS -printf '%P\0' |
      LC_ALL=C sort -z |
      xargs -0 sha256sum > SHA256SUMS
  )
  cp "$cutover_remote_backup/SHA256SUMS" "$cutover_local_backup/SHA256SUMS"
  manifest_hash="$(sha256sum "$cutover_remote_backup/SHA256SUMS" | cut -d' ' -f1)"
  sed -i "s/^manifest_sha256=.*/manifest_sha256=$manifest_hash/" "$cutover_local_backup/LOCAL-VERIFICATION"
}

unsafe_code_archive="$fixture/unsafe-code-archive"
mkdir -p "$unsafe_code_archive/wp-content/plugins/goetz-site"
ln -s "$fixture/backup-symlink-target" "$unsafe_code_archive/wp-content/plugins/goetz-site/redirected"
tar -czf "$cutover_remote_backup/code-plugin-goetz-site.tar.gz" \
  -C "$unsafe_code_archive" 'wp-content/plugins/goetz-site'
cp "$cutover_remote_backup/code-plugin-goetz-site.tar.gz" "$cutover_local_backup/code-plugin-goetz-site.tar.gz"
refresh_cutover_packet_checksums
ssh_before_unsafe_code="$(cat "$record_root/ssh-count")"
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-cutover --dry-run >/dev/null 2>&1; then
  fail 'rollback dry-run certified a code archive containing a symbolic link'
fi
ssh_after_unsafe_code="$(cat "$record_root/ssh-count")"
[[ "$ssh_after_unsafe_code" == "$ssh_before_unsafe_code" ]] ||
  fail 'local backup verification contacted SSH before rejecting an unsafe code archive'
for packet_file in code-plugin-goetz-site.tar.gz SHA256SUMS; do
  cp -a "$cutover_backup_originals/remote/$packet_file" "$cutover_remote_backup/$packet_file"
  cp -a "$cutover_backup_originals/local/$packet_file" "$cutover_local_backup/$packet_file"
done
cp -a "$cutover_backup_originals/local/LOCAL-VERIFICATION" "$cutover_local_backup/LOCAL-VERIFICATION"

unsafe_upload_archive="$fixture/unsafe-upload-archive"
mkdir -p "$unsafe_upload_archive/wp-content/uploads"
ln -s "$fixture/backup-symlink-target" "$unsafe_upload_archive/wp-content/uploads/redirected"
tar -czf "$cutover_remote_backup/uploads.tar.gz" \
  -C "$unsafe_upload_archive" 'wp-content/uploads'
cp "$cutover_remote_backup/uploads.tar.gz" "$cutover_local_backup/uploads.tar.gz"
refresh_cutover_packet_checksums
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-cutover --dry-run >/dev/null 2>&1; then
  fail 'rollback dry-run certified an uploads archive containing a symbolic link'
fi
for packet_file in uploads.tar.gz SHA256SUMS; do
  cp -a "$cutover_backup_originals/remote/$packet_file" "$cutover_remote_backup/$packet_file"
  cp -a "$cutover_backup_originals/local/$packet_file" "$cutover_local_backup/$packet_file"
done
cp -a "$cutover_backup_originals/local/LOCAL-VERIFICATION" "$cutover_local_backup/LOCAL-VERIFICATION"

printf 'https://goetzlegal.com\n' > "$record_root/mutate-origin-on-lock"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover >/dev/null 2>&1; then
  fail 'cutover accepted origin state that changed while acquiring the mutation lock'
fi
printf '%s\n' 'https://goetzgoetz.kinsta.cloud' > "$remote_state/home"
printf '%s\n' 'https://goetzgoetz.kinsta.cloud' > "$remote_state/siteurl"

cutover_dry="$fixture/cutover-dry.out"
run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover > "$cutover_dry"
assert_contains "$cutover_dry" 'manager_apply_command=./manager.sh remote:cutover'
assert_contains "$cutover_dry" 'manager_rollback_command=./manager.sh remote:rollback'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] || fail 'cutover dry-run mutated home'

# A partial URL change must auto-restore the packet database under the lock.
touch "$record_root/fail-next-cutover"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'injected cutover failure unexpectedly succeeded'
fi
assert_contains "$remote_private/operations/cutover-contract-cutover.status" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] || fail 'cutover recovery did not restore staging home'

# Recovery is successful only if the database, URL checks, rewrite flush,
# object-cache flush, and Kinsta cache purge all complete. Each maintenance
# failure must leave an explicit manual-intervention phase, never success.
for recovery_step in rewrite cache kinsta; do
  touch "$record_root/fail-next-cutover" "$record_root/fail-next-$recovery_step"
  if run_remote "$remote_repo/scripts/release/cutover.sh" \
    --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
    --backup-id=contract-cutover --apply >/dev/null 2>&1; then
    fail "cutover with an injected $recovery_step recovery failure unexpectedly succeeded"
  fi
  assert_contains "$remote_private/operations/cutover-contract-cutover.status" \
    'phase=auto_rollback_failed_manual_intervention_required'
  [[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
    fail "cutover $recovery_step failure did not at least restore the backup database"
done

# A purge failure after the normal URL mutation must fail the cutover and
# recover the staging database. The operation may record successful automatic
# recovery, but it must never retain the normal complete receipt.
touch "$record_root/fail-next-kinsta"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'cutover ignored an injected Kinsta purge failure on its normal path'
fi
cutover_purge_receipt="$remote_private/operations/cutover-contract-cutover.status"
assert_not_contains "$cutover_purge_receipt" 'phase=complete'
assert_contains "$cutover_purge_receipt" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' && \
   "$(cat "$remote_state/siteurl")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'cutover purge failure recovery did not restore the staging origin'

# SEO reconfiguration is part of the URL mutation transaction. A failure after
# search-replace must restore the pre-cutover database before reporting failure.
touch "$record_root/force-cutover-seo-second-configured"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'cutover accepted configured instead of exact noop from its second strict SEO pass'
fi
assert_contains "$remote_private/operations/cutover-contract-cutover.status" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' && \
   "$(cat "$remote_state/siteurl")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'cutover SEO status validation failure did not restore the staging origin'

cutover_substitution_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
touch "$record_root/fail-next-cutover-seo"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'cutover ignored an injected strict SEO configuration failure'
fi
cutover_substitution_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( cutover_substitution_imports_after == cutover_substitution_imports_before + 1 )) ||
  fail 'cutover assignment failure ran database recovery zero or multiple times'
assert_contains "$remote_private/operations/cutover-contract-cutover.status" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' && \
   "$(cat "$remote_state/siteurl")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'cutover SEO failure recovery did not restore the staging origin'

# TERM after search-replace begins must recover the coupled database exactly
# once while the lock is held and leave a durable recovery receipt.
cutover_signal_imports_before="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
touch "$record_root/signal-next-cutover-search-replace"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'cutover unexpectedly succeeded after an injected TERM'
fi
cutover_signal_imports_after="$(grep -c '<db><import>' "$record_root/wp.log" || true)"
(( cutover_signal_imports_after == cutover_signal_imports_before + 1 )) ||
  fail 'cutover TERM ran database recovery zero or multiple times'
assert_contains "$remote_private/operations/cutover-contract-cutover.status" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' && \
   "$(cat "$remote_state/siteurl")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'cutover TERM recovery did not restore the staging origin'

# A lost SSH return path makes the remote outcome ambiguous. The local wrapper
# points to the receipt but never claims that recovery definitely ran.
touch "$record_root/fail-next-cutover" "$record_root/disconnect-after-cutover"
cutover_disconnect_error="$fixture/cutover-disconnect.err"
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null 2>"$cutover_disconnect_error"; then
  fail 'ambiguous transport-disconnect cutover unexpectedly succeeded locally'
fi
assert_contains "$cutover_disconnect_error" 'Recovery status is unknown'
assert_not_contains "$cutover_disconnect_error" 'Database recovery ran while holding'
assert_contains "$remote_private/operations/cutover-contract-cutover.status" 'phase=auto_rollback_succeeded'
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' && \
   "$(cat "$remote_state/siteurl")" == 'https://goetzgoetz.kinsta.cloud' ]] ||
  fail 'ambiguous cutover disconnect fixture did not recover the staging origin remotely'

cutover_wp_start="$(wc -l < "$record_root/wp.log")"
run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --to=https://goetzlegal.com \
  --backup-id=contract-cutover --apply >/dev/null
[[ "$(cat "$remote_state/home")" == 'https://goetzlegal.com' && "$(cat "$remote_state/siteurl")" == 'https://goetzlegal.com' ]] ||
  fail 'cutover apply did not set the exact production origin'
cutover_wp_log="$fixture/cutover-success.wp.log"
tail -n "+$((cutover_wp_start + 1))" "$record_root/wp.log" > "$cutover_wp_log"
[[ "$(grep -Fc '<--path='"$remote_site"'><goetz-site><seo><configure><--strict><--format=json>' "$cutover_wp_log")" -eq 2 ]] ||
  fail 'cutover did not run strict SEO configuration exactly twice'
grep -Fq '<--path='"$remote_site"'><yoast><index><--reindex><--skip-confirmation>' "$cutover_wp_log" ||
  fail 'cutover did not reindex Yoast'
awk '
  /<goetz-site><seo><configure><--strict><--format=json>/ { seo++; if (seo == 2) second_seo = NR }
  /<yoast><index><--reindex><--skip-confirmation>/ { yoast = NR }
  /<rewrite><flush><--hard>/ { if (!rewrite) rewrite = NR }
  END { exit !(seo == 2 && second_seo < yoast && yoast < rewrite) }
' "$cutover_wp_log" || fail 'cutover SEO configuration, Yoast index, and cache preparation ran out of order'

rollback_dry="$fixture/rollback-dry.out"
find "$remote_site" -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > "$fixture/rollback-before.sha"
run_remote "$remote_repo/scripts/release/rollback.sh" --backup-id=contract-cutover --dry-run > "$rollback_dry"
find "$remote_site" -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > "$fixture/rollback-after.sha"
cmp -s "$fixture/rollback-before.sha" "$fixture/rollback-after.sha" || fail 'rollback dry-run changed the public site'
[[ ! -e "$remote_private/operations/rollback-contract-cutover.status" ]] || fail 'rollback dry-run wrote an operation receipt'
for dry_marker in rollback_preflight would_restore_code would_restore_uploads would_restore_database \
  would_verify_state would_flush would_smoke_routes manager_apply_command; do
  assert_contains "$rollback_dry" "$dry_marker"
done

# Manual rollback cannot automatically undo an interruption of itself. TERM
# must therefore replace the in-progress phase with an explicit manual-
# intervention receipt and remove all private extraction/preflight work.
touch "$record_root/signal-next-rollback-db-import"
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'manual rollback unexpectedly succeeded after an injected TERM'
fi
rollback_signal_receipt="$remote_private/operations/rollback-contract-cutover.status"
assert_contains "$rollback_signal_receipt" 'phase=rollback_failed_manual_intervention_required'
! find "$remote_private" -maxdepth 1 -type d -name 'rollback-work-contract-cutover-*' -print -quit | grep -q . ||
  fail 'rollback TERM left a private extraction work directory'
! find "$remote_private/operations" -maxdepth 1 -type f -name '.rollback-contract-cutover.preflight.*' -print -quit | grep -q . ||
  fail 'rollback TERM left a private preflight file'

# Once rollback mutation begins, route-smoke failures must pass through the ERR
# handler and replace the in-progress smoke receipt with the durable manual-
# intervention phase. A direct exit inside die() would strand phase=smoke.
printf 'https://goetzgoetz.kinsta.cloud/wrong-route/\n' > "$record_root/curl-effective-once"
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-cutover --apply >/dev/null 2>&1; then
  fail 'manual rollback accepted an injected post-mutation route-smoke failure'
fi
rollback_smoke_receipt="$remote_private/operations/rollback-contract-cutover.status"
assert_contains "$rollback_smoke_receipt" 'phase=rollback_failed_manual_intervention_required'
assert_not_contains "$rollback_smoke_receipt" 'phase=smoke'

run_remote "$remote_repo/scripts/release/rollback.sh" --backup-id=contract-cutover --apply >/dev/null
[[ "$(cat "$remote_state/home")" == 'https://goetzgoetz.kinsta.cloud' ]] || fail 'rollback did not restore staging home'
assert_contains "$remote_private/operations/rollback-contract-cutover.status" 'phase=complete'

# Duplicate and almost-write flags are always rejected before transport.
if run_remote "$remote_repo/scripts/release/cutover.sh" \
  --from=https://goetzgoetz.kinsta.cloud --from=https://goetzgoetz.kinsta.cloud \
  --to=https://goetzlegal.com --backup-id=contract-cutover >/dev/null 2>&1; then
  fail 'cutover accepted a duplicate --from flag'
fi
if run_remote "$remote_repo/scripts/release/rollback.sh" \
  --backup-id=contract-cutover --apply-without-review >/dev/null 2>&1; then
  fail 'rollback accepted a write flag other than exact --apply'
fi

for mutation_script in remote-apply.sh cutover.sh rollback.sh; do
  for signal_name in HUP INT TERM; do
    grep -Eq "trap .* ${signal_name}($|')" "scripts/release/$mutation_script" ||
      fail "$mutation_script does not trap $signal_name during remote mutation"
  done
done

# All transport children receive only the sanitized environment and hardened
# SSH policy. Neither the synthetic passphrase nor its variable name survives.
for record in "$record_root"/ssh.*.argv; do
  assert_contains "$record" '-F'
  assert_contains "$record" '/dev/null'
  assert_contains "$record" 'StrictHostKeyChecking=yes'
  assert_contains "$record" "UserKnownHostsFile=$known_hosts"
  assert_contains "$record" 'GlobalKnownHostsFile=/dev/null'
  assert_contains "$record" 'IdentityFile=none'
  assert_contains "$record" 'IdentitiesOnly=no'
  assert_contains "$record" 'ForwardAgent=no'
  assert_contains "$record" 'ClearAllForwardings=yes'
  assert_not_contains "$record" 'SendEnv='
  assert_contains "$record" 'ConnectTimeout=15'
done
for record in "$record_root"/rsync.*.argv; do
  if grep -Fq 'goetzgoetz@163.192.209.112:' "$record"; then
    assert_contains "$record" '-F /dev/null'
    assert_contains "$record" 'StrictHostKeyChecking=yes'
    assert_contains "$record" "UserKnownHostsFile=$known_hosts"
    assert_contains "$record" 'GlobalKnownHostsFile=/dev/null'
    assert_contains "$record" 'IdentityFile=none'
    assert_contains "$record" 'IdentitiesOnly=no'
    assert_contains "$record" 'ForwardAgent=no'
    assert_contains "$record" 'ClearAllForwardings=yes'
    assert_not_contains "$record" 'SendEnv='
    assert_contains "$record" 'ConnectTimeout=15'
  fi
done
transport_evidence="$fixture/transport-evidence"
cat "$record_root"/*.argv "$record_root"/*.env "$record_root"/*.stdin > "$transport_evidence"
! grep -Fq 'SSH_KEY_PW' "$transport_evidence" || fail 'SSH_KEY_PW variable name reached a release child process'
! grep -Fq 'never-forward-release-secret' "$transport_evidence" || fail 'synthetic SSH secret reached a release child process'
! grep -Fq '/.env' "$transport_evidence" || fail 'an environment-file path reached a release child process'

# Because every fake SSH call executed its heredoc, these durable effects prove
# the contract is not satisfied by source greps or an always-zero transport.
[[ -s "$record_root/wp.log" && -s "$record_root/curl.log" ]] || fail 'fake remote did not execute WP-CLI and route smoke commands'
! grep -v '^<--path=' "$record_root/wp.log" | grep -q . || fail 'a remote WP-CLI command omitted explicit --path targeting'

printf 'release-payload: ok\n'
