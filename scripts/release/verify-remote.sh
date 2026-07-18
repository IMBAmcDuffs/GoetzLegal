#!/usr/bin/env bash
set -euo pipefail

unset SSH_KEY_PW
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly GOETZ_RELEASE_ROOT="$root/scripts/release"
readonly GOETZ_LOCAL_BACKUP_ROOT="$root/__dev/kinsta-backups"
GOETZ_COMMAND_NAME='verify-remote'
# shellcheck source=scripts/release/common.sh
source "$GOETZ_RELEASE_ROOT/common.sh"

release_dir=''
origin=''
release_seen=0
origin_seen=0
for argument in "$@"; do
  case "$argument" in
    --release-dir=*)
      (( release_seen == 0 )) || goetz_fail '--release-dir was supplied more than once'
      release_dir="${argument#*=}"
      release_seen=1
      ;;
    --origin=*)
      (( origin_seen == 0 )) || goetz_fail '--origin was supplied more than once'
      origin="${argument#*=}"
      origin_seen=1
      ;;
    *) goetz_fail "unknown argument: $argument" ;;
  esac
done
[[ -n "$release_dir" && -n "$origin" ]] ||
  goetz_fail 'usage: verify-remote.sh --release-dir=<release> --origin=<approved-origin>'
case "$origin" in
  "$GOETZ_STAGING_ORIGIN"|"$GOETZ_PRODUCTION_ORIGIN") ;;
  *) goetz_fail 'remote verification origin must be the exact staging or production origin' ;;
esac
[[ -d "$release_dir" && ! -L "$release_dir" ]] || goetz_fail 'release directory must be a normal directory'
goetz_release_identity "$release_dir"
goetz_require_kinsta

remote_release="$GOETZ_REMOTE_PRIVATE/releases/$GOETZ_RELEASE_SHA"
goetz_ssh bash -s -- "$remote_release" "$GOETZ_RELEASE_SHA" "$GOETZ_RELEASE_DIGEST" "$origin" <<'REMOTE'
# GOETZ_REMOTE_VERIFY
set -euo pipefail
release="$1"
release_sha="$2"
release_digest="$3"
origin="$4"
site='/www/goetzgoetz_755/public'
private='/www/goetzgoetz_755/private'
die() { printf 'remote verify: %s\n' "$1" >&2; exit 1; }
assert_dir() {
  local path="$1" expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" && "$(readlink -f -- "$path")" == "$expected" ]] ||
    die "unsafe or redirected directory: $expected"
}
scan_public_dumps() {
  ! find "$site" -xdev -type f \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' -o -name '*.bak' \
    -o -name 'release.json' -o -name 'RELEASE-MANIFEST.sha256' -o -name '.env*' \) -print -quit | grep -q .
}
verify_runtime_root() {
  local relative="$1"
  local expected actual
  case "$relative" in
    wp-content/themes/goetz-legal|wp-content/plugins/goetz-site|wp-content/plugins/goetz-migration|\
    wp-content/plugins/wordpress-seo|wp-content/plugins/wpforms-lite) ;;
    *) die "runtime root is not allowlisted: $relative" ;;
  esac
  expected="$release/payload/$relative"
  actual="$site/$relative"
  assert_dir "$expected" "$expected"
  assert_dir "$actual" "$actual"
  ! find "$actual" -xdev ! -type f ! -type d -print -quit | grep -q . ||
    die "managed runtime root contains a non-file entry: $relative"
  cmp -s \
    <(cd "$expected" && find . -mindepth 1 -printf '%y\t%P\n' | LC_ALL=C sort) \
    <(cd "$actual" && find . -mindepth 1 -printf '%y\t%P\n' | LC_ALL=C sort) ||
    die "managed runtime file tree differs from the release: $relative"
  cmp -s \
    <(cd "$expected" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum) \
    <(cd "$actual" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum) ||
    die "managed runtime hashes differ from the release: $relative"
}
validate_json_status() {
  local document="$1" allowed="$2"
  printf '%s' "$document" | php -r '
    $allowed = explode(",", $argv[1]);
    $raw = stream_get_contents(STDIN);
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data) || !isset($data["status"]) || !is_string($data["status"]) || !in_array($data["status"], $allowed, true)) { exit(1); }
  ' "$allowed"
}
smoke_exact_route() {
  local route="$1" effective
  effective="$(curl --fail --silent --show-error --location --max-redirs 3 \
    --connect-timeout 10 --max-time 30 \
    --proto '=https' --proto-redir '=https' --output /dev/null \
    --write-out '%{url_effective}' "$origin$route")" ||
    die "route smoke failed: $route"
  [[ "$effective" == "$origin$route" ]] ||
    die "effective URL escaped the exact requested route: $route"
}
verify_debug_checkpoint() {
  local current_inode current_size current_prefix_hash final_inode final_size final_prefix_hash
  [[ -f "$debug_file" && ! -L "$debug_file" ]] ||
    die 'debug-log checkpoint is missing or redirected'
  current_inode="$(stat -c %i "$debug_file")"
  current_size="$(stat -c %s "$debug_file")"
  [[ "$current_inode" == "$recorded_inode" ]] ||
    die 'debug-log checkpoint inode differs from the deployed release receipt'
  (( current_size >= recorded_offset )) ||
    die 'debug-log checkpoint size regressed after deployment'
  current_prefix_hash="$(head -c "$recorded_offset" "$debug_file" | sha256sum | cut -d' ' -f1)"
  [[ "$current_prefix_hash" == "$recorded_prefix_hash" ]] ||
    die 'debug-log checkpoint prefix differs from the deployed release receipt'
  if (( current_size > recorded_offset )) &&
    tail -c "+$((recorded_offset + 1))" "$debug_file" | grep -Eq 'PHP (Fatal|Parse) error'; then
    die 'a PHP fatal or parse error was written after deployment began'
  fi
  [[ -f "$debug_file" && ! -L "$debug_file" ]] ||
    die 'debug-log checkpoint disappeared while it was scanned'
  final_inode="$(stat -c %i "$debug_file")"
  final_size="$(stat -c %s "$debug_file")"
  [[ "$final_inode" == "$recorded_inode" ]] ||
    die 'debug-log checkpoint inode changed while it was scanned'
  [[ "$final_size" == "$current_size" ]] ||
    die 'debug-log checkpoint size changed while it was scanned'
  final_prefix_hash="$(head -c "$recorded_offset" "$debug_file" | sha256sum | cut -d' ' -f1)"
  [[ "$final_prefix_hash" == "$recorded_prefix_hash" ]] ||
    die 'debug-log checkpoint prefix changed while it was scanned'
}
[[ "$release" == "/www/goetzgoetz_755/private/releases/$release_sha" ]]
[[ "$release_sha" =~ ^[0-9a-f]{40}$ && "$release_digest" =~ ^[0-9a-f]{64}$ ]]
case "$origin" in 'https://goetzgoetz.kinsta.cloud'|'https://goetzlegal.com') ;; *) die 'origin is not approved' ;; esac
for command_name in wp php sha256sum readlink flock find grep awk sort stat head tail curl cmp xargs; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command unavailable: $command_name"
done
assert_dir "$site" '/www/goetzgoetz_755/public'
assert_dir "$private" '/www/goetzgoetz_755/private'
assert_dir "$release" "$release"
assert_dir "$release/payload" "$release/payload"
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]]
[[ "$(sha256sum "$release/payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)" == "$release_digest" ]]
[[ "$(find "$release/payload" -type f ! -name RELEASE-MANIFEST.sha256 -printf './%P\n' | LC_ALL=C sort)" == \
    "$(awk '{print $2}' "$release/payload/RELEASE-MANIFEST.sha256" | LC_ALL=C sort)" ]]
(cd "$release/payload" && sha256sum --check --strict RELEASE-MANIFEST.sha256 >/dev/null)

lock_file="$private/locks/release-mutation.lock"
[[ -f "$lock_file" && ! -L "$lock_file" ]] || die 'shared mutation lock is missing or redirected'
exec 9<"$lock_file"
flock -s -n 9 || die 'a release mutation is in progress'

current="$private/state/current-release"
[[ -f "$current" && ! -L "$current" ]]
mapfile -t current_lines < "$current"
(( ${#current_lines[@]} == 8 )) || die 'current release receipt schema is invalid'
[[ "${current_lines[0]}" == 'schema_version=1' ]]
[[ "${current_lines[1]}" == "release_commit=$release_sha" ]]
[[ "${current_lines[2]}" == "release_manifest_sha256=$release_digest" ]]
[[ "${current_lines[3]}" =~ ^backup_id=[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
[[ "${current_lines[4]}" =~ ^deployed_utc=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]
recorded_inode="${current_lines[5]#debug_log_inode=}"
recorded_offset="${current_lines[6]#debug_log_offset=}"
recorded_prefix_hash="${current_lines[7]#debug_log_prefix_sha256=}"
[[ "${current_lines[5]}" == "debug_log_inode=$recorded_inode" && "$recorded_inode" =~ ^[0-9]+$ ]]
[[ "${current_lines[6]}" == "debug_log_offset=$recorded_offset" && "$recorded_offset" =~ ^[0-9]+$ ]]
[[ "${current_lines[7]}" == "debug_log_prefix_sha256=$recorded_prefix_hash" && "$recorded_prefix_hash" =~ ^[0-9a-f]{64}$ ]]

for runtime_root in \
  wp-content/themes/goetz-legal \
  wp-content/plugins/goetz-site \
  wp-content/plugins/goetz-migration \
  wp-content/plugins/wordpress-seo \
  wp-content/plugins/wpforms-lite; do
  verify_runtime_root "$runtime_root"
done

[[ "$(wp --path="$site" eval 'echo is_multisite() ? "yes" : "no";')" == 'no' ]] || die 'multisite is not supported'
[[ "$(wp --path="$site" option get home)" == "$origin" ]]
[[ "$(wp --path="$site" option get siteurl)" == "$origin" ]]
[[ "$(wp --path="$site" theme list --status=active --field=name)" == 'goetz-legal' ]]
active_plugins="$(wp --path="$site" plugin list --status=active --field=name)"
for required_plugin in goetz-site goetz-migration wordpress-seo wpforms-lite; do
  grep -Fqx "$required_plugin" <<< "$active_plugins"
done
[[ "$(wp --path="$site" plugin get goetz-site --field=version)" == '1.0.0' ]]
[[ "$(wp --path="$site" plugin get goetz-migration --field=version)" == '1.1.0' ]]
[[ "$(wp --path="$site" plugin get wordpress-seo --field=version)" == '28.0' ]]
[[ "$(wp --path="$site" plugin get wpforms-lite --field=version)" == '1.10.0.4' ]]
test -s "$site/wp-content/themes/goetz-legal/dist/.vite/manifest.json"
test -s "$site/wp-content/themes/goetz-legal/vendor/autoload.php"
test -s "$site/wp-content/plugins/goetz-site/build/index.js"
test -s "$site/wp-content/plugins/goetz-site/build/index.asset.php"
for attorney_slug in james-l-goetz gregory-w-goetz; do
  attorney_verification="$(wp --path="$site" goetz-site attorney-profile --slug="$attorney_slug" --verify)"
  validate_json_status "$attorney_verification" 'verified,managed_modified'
done
scan_public_dumps

debug_file="$site/wp-content/debug.log"
verify_debug_checkpoint
for route in '/' '/james-l-goetz/' '/gregory-w-goetz/' '/staff/' '/questions/' '/links/' '/contact/'; do
  smoke_exact_route "$route"
done
verify_debug_checkpoint
printf 'remote_verification=passed\nrelease_commit=%s\nrelease_manifest_sha256=%s\norigin=%s\n' \
  "$release_sha" "$release_digest" "$origin"
REMOTE
