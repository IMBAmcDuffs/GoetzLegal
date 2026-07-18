#!/usr/bin/env bash
set -euo pipefail

unset SSH_KEY_PW
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly GOETZ_RELEASE_ROOT="$root/scripts/release"
readonly GOETZ_LOCAL_BACKUP_ROOT="$root/__dev/kinsta-backups"
GOETZ_COMMAND_NAME='remote-apply'
# shellcheck source=scripts/release/common.sh
source "$GOETZ_RELEASE_ROOT/common.sh"

release_dir=''
backup_id=''
release_seen=0
backup_seen=0
for argument in "$@"; do
  case "$argument" in
    --release-dir=*)
      (( release_seen == 0 )) || goetz_fail '--release-dir was supplied more than once'
      release_dir="${argument#*=}"
      release_seen=1
      ;;
    --backup-id=*)
      (( backup_seen == 0 )) || goetz_fail '--backup-id was supplied more than once'
      backup_id="${argument#*=}"
      backup_seen=1
      ;;
    --*) goetz_fail "unknown argument: $argument" ;;
    *)
      (( release_seen == 0 )) || goetz_fail 'release directory was supplied more than once'
      release_dir="$argument"
      release_seen=1
      ;;
  esac
done
[[ -n "$release_dir" && -n "$backup_id" ]] ||
  goetz_fail 'usage: remote-apply.sh --release-dir=<release> --backup-id=<verified-pre-deployment-backup>'
goetz_validate_backup_id "$backup_id"
[[ -d "$release_dir" && ! -L "$release_dir" ]] || goetz_fail 'release directory must be a normal directory'
goetz_release_identity "$release_dir"
goetz_require_kinsta
goetz_verify_local_backup "$backup_id" pre-deployment
[[ "$GOETZ_BACKUP_HOME" == "$GOETZ_STAGING_ORIGIN" && "$GOETZ_BACKUP_SITEURL" == "$GOETZ_STAGING_ORIGIN" ]] ||
  goetz_fail 'deployment backup is not bound to the exact staging origin'
[[ "$GOETZ_BACKUP_RELEASE_SHA" == "$GOETZ_RELEASE_SHA" && "$GOETZ_BACKUP_RELEASE_DIGEST" == "$GOETZ_RELEASE_DIGEST" ]] ||
  goetz_fail 'deployment backup is not coupled to this exact release commit and digest'
goetz_remote_verify_backup_digest

remote_release="$GOETZ_REMOTE_PRIVATE/releases/$GOETZ_RELEASE_SHA"
incoming_release="$GOETZ_REMOTE_PRIVATE/releases/.incoming-$GOETZ_RELEASE_SHA-$backup_id"
prepare_result="$(goetz_ssh bash -s -- \
  "$remote_release" "$incoming_release" "$GOETZ_RELEASE_DIGEST" <<'REMOTE'
# GOETZ_REMOTE_RELEASE_PREPARE
set -euo pipefail
release="$1"
incoming="$2"
expected_digest="$3"
private='/www/goetzgoetz_755/private'
die() { printf 'remote release prepare: %s\n' "$1" >&2; exit 1; }
assert_dir() {
  local path="$1" expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" && "$(readlink -f -- "$path")" == "$expected" ]] || die "unsafe directory: $expected"
}
[[ "$release" == /www/goetzgoetz_755/private/releases/[0-9a-f]* && "$release" != '/www/goetzgoetz_755/private/releases/' ]]
[[ "$incoming" == /www/goetzgoetz_755/private/releases/.incoming-[0-9a-f]* && "$incoming" != '/www/goetzgoetz_755/private/releases/' ]]
[[ "$expected_digest" =~ ^[0-9a-f]{64}$ ]]
assert_dir "$private" '/www/goetzgoetz_755/private'
mkdir -m 0700 -p "$private/releases"
assert_dir "$private/releases" '/www/goetzgoetz_755/private/releases'
if [[ -e "$release" || -L "$release" ]]; then
  assert_dir "$release" "$release"
  assert_dir "$release/payload" "$release/payload"
  ! find "$release" -xdev -type l -print -quit | grep -q .
  [[ -f "$release/payload/RELEASE-MANIFEST.sha256" && ! -L "$release/payload/RELEASE-MANIFEST.sha256" ]]
  [[ "$(find "$release/payload" -type f ! -name RELEASE-MANIFEST.sha256 -printf './%P\n' | LC_ALL=C sort)" == \
      "$(awk '{print $2}' "$release/payload/RELEASE-MANIFEST.sha256" | LC_ALL=C sort)" ]]
  [[ "$(sha256sum "$release/payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)" == "$expected_digest" ]]
  (cd "$release/payload" && sha256sum --check --strict RELEASE-MANIFEST.sha256 >/dev/null)
  printf 'reuse\n'
  exit 0
fi
if [[ -e "$incoming" || -L "$incoming" ]]; then
  assert_dir "$incoming" "$incoming"
else
  mkdir -m 0700 "$incoming"
fi
assert_dir "$incoming" "$incoming"
if [[ -e "$incoming/payload" || -L "$incoming/payload" ]]; then
  assert_dir "$incoming/payload" "$incoming/payload"
else
  mkdir -m 0700 "$incoming/payload"
fi
assert_dir "$incoming/payload" "$incoming/payload"
! find "$incoming" -xdev -type l -print -quit | grep -q .
printf 'upload\n'
REMOTE
)" || goetz_fail 'remote release preparation failed'
case "$prepare_result" in
  reuse) ;;
  upload)
    # --delete-delay is scoped to the exact private incoming directory. This
    # makes an interrupted upload resumable without touching a runtime root.
    goetz_rsync --archive --delete-delay --checksum --protect-args -e "$GOETZ_RSYNC_SHELL" \
      "$GOETZ_RELEASE_PAYLOAD/" "$GOETZ_REMOTE:$incoming_release/payload/"
    goetz_ssh bash -s -- "$remote_release" "$incoming_release" "$GOETZ_RELEASE_SHA" "$GOETZ_RELEASE_DIGEST" <<'REMOTE'
# GOETZ_REMOTE_RELEASE_FINALIZE
set -euo pipefail
release="$1"
incoming="$2"
release_sha="$3"
expected_digest="$4"
die() { printf 'remote release finalize: %s\n' "$1" >&2; exit 1; }
assert_dir() {
  local path="$1" expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" && "$(readlink -f -- "$path")" == "$expected" ]] || die "unsafe directory: $expected"
}
[[ "$release" == "/www/goetzgoetz_755/private/releases/$release_sha" ]]
[[ "$incoming" == /www/goetzgoetz_755/private/releases/.incoming-"$release_sha"-* ]]
[[ "$release_sha" =~ ^[0-9a-f]{40}$ && "$expected_digest" =~ ^[0-9a-f]{64}$ ]]
assert_dir '/www/goetzgoetz_755/private/releases' '/www/goetzgoetz_755/private/releases'
assert_dir "$incoming" "$incoming"
assert_dir "$incoming/payload" "$incoming/payload"
! find "$incoming/payload" -type l -print -quit | grep -q .
[[ "$(find "$incoming/payload" -type f ! -name RELEASE-MANIFEST.sha256 -printf './%P\n' | LC_ALL=C sort)" == \
    "$(awk '{print $2}' "$incoming/payload/RELEASE-MANIFEST.sha256" | LC_ALL=C sort)" ]]
[[ "$(sha256sum "$incoming/payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)" == "$expected_digest" ]]
(cd "$incoming/payload" && sha256sum --check --strict RELEASE-MANIFEST.sha256 >/dev/null)
grep -Eq '^[[:space:]]*"commit": "'"$release_sha"'",?$' "$incoming/payload/release.json"
if [[ -e "$release" || -L "$release" ]]; then
  assert_dir "$release" "$release"
  [[ "$(sha256sum "$release/payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)" == "$expected_digest" ]]
  find "$incoming" -xdev -depth -mindepth 1 -delete
  rmdir "$incoming"
else
  mv -- "$incoming" "$release"
fi
assert_dir "$release" "$release"
REMOTE
    ;;
  *) goetz_fail 'remote release preparation returned an invalid state' ;;
esac

if ! goetz_ssh bash -s -- \
  "$remote_release" "$GOETZ_REMOTE_BACKUP" "$GOETZ_BACKUP_DIGEST" "$GOETZ_RELEASE_SHA" "$GOETZ_RELEASE_DIGEST" <<'REMOTE'
# GOETZ_REMOTE_RELEASE_APPLY
set -Eeuo pipefail
umask 077
release="$1"
backup="$2"
expected_backup_hash="$3"
release_sha="$4"
expected_release_hash="$5"
site='/www/goetzgoetz_755/public'
private='/www/goetzgoetz_755/private'
staging='https://goetzgoetz.kinsta.cloud'
mutation_started=0
restore_work=''
preflight_file=''
debug_offset=0
debug_inode='none'

die() { printf 'remote apply: %s\n' "$1" >&2; return 1; }
assert_physical_dir() {
  local path="$1" expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" ]] || die "unsafe directory: $expected"
  [[ "$(readlink -f -- "$path")" == "$expected" ]] || die "redirected directory: $expected"
}
assert_target() {
  local target="$1" expected="$2" parent="${target%/*}"
  [[ "$target" == "$expected" ]]
  assert_physical_dir "$parent" "$parent"
  if [[ -e "$target" || -L "$target" ]]; then assert_physical_dir "$target" "$expected"; fi
}
write_phase() {
  local phase="$1" receipt="$private/operations/deploy-$release_sha-$backup_id.status"
  local temporary
  [[ ! -L "$receipt" ]]
  temporary="$(mktemp "$private/operations/.deploy-$release_sha-$backup_id.status.XXXXXX")"
  printf 'schema_version=1\noperation=deploy\nrelease_commit=%s\nbackup_id=%s\nphase=%s\nupdated_utc=%s\n' \
    "$release_sha" "$backup_id" "$phase" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$temporary"
  chmod 0600 "$temporary"
  mv -f -- "$temporary" "$receipt"
}
require_single_site() {
  [[ "$(wp --path="$site" eval 'echo is_multisite() ? "yes" : "no";')" == 'no' ]] ||
    die 'multisite is not supported by this release toolchain'
}
state_for_root() {
  local relative="$1"
  awk -F '\t' -v wanted="$relative" '$1 == wanted { count++; value=$2 "\t" $3 } END { if (count == 1) print value; else exit 1 }' "$backup/code-state.tsv"
}
validate_archive_entry_prefix() {
  local archive="$1" prefix="$2" entry
  while IFS= read -r entry; do
    [[ -n "$entry" && "$entry" != /* && "$entry" != *'/../'* && "$entry" != '../'* && "$entry" != *'/..' ]]
    [[ "$entry" == "$prefix" || "$entry" == "$prefix/"* ]]
  done < <(tar -tzf "$archive")
}
preflight_backup_root() {
  local relative="$1" archive="$2" target="$3"
  local recorded state recorded_archive
  recorded="$(state_for_root "$relative")" || die "missing or duplicate backup code state: $relative"
  IFS=$'\t' read -r state recorded_archive <<< "$recorded"
  assert_target "$target" "$target"
  case "$state" in
    present)
      [[ "$recorded_archive" == "$archive" && -f "$backup/$archive" && ! -L "$backup/$archive" && -s "$backup/$archive" ]]
      gzip -t "$backup/$archive"
      validate_archive_entry_prefix "$backup/$archive" "$relative"
      ;;
    absent) [[ "$recorded_archive" == '-' ]] ;;
    *) die "invalid backup code state: $relative" ;;
  esac
  printf '%s\t%s\t%s\t%s\n' "$relative" "$state" "$archive" "$target"
}
restore_code_root() {
  local relative="$1" state="$2" archive="$3" target="$4"
  assert_target "$target" "$target" || return
  if [[ "$state" == 'present' ]]; then
    mkdir -p "$target" || return
    assert_physical_dir "$target" "$target" || return
    rsync --archive --delete-delay --checksum "$restore_work/$relative/" "$target/" || return
  elif [[ "$state" == 'absent' ]]; then
    if [[ -e "$target" || -L "$target" ]]; then
      assert_physical_dir "$target" "$target" || return
      find "$target" -xdev -depth -mindepth 1 -delete || return
      rmdir "$target" || return
    fi
  else
    return 93
  fi
}
restore_release_state() {
  case "$(cat "$backup/release-state.tsv")" in
    $'present\tprevious-current-release')
      state_tmp="$(mktemp "$private/state/.current-release.XXXXXX")" || return
      cp -- "$backup/previous-current-release" "$state_tmp" || return
      chmod 0600 "$state_tmp" || return
      mv -f -- "$state_tmp" "$private/state/current-release" || return
      ;;
    $'absent\t-')
      if [[ -e "$private/state/current-release" || -L "$private/state/current-release" ]]; then
        [[ -f "$private/state/current-release" && ! -L "$private/state/current-release" ]]
        find "$private/state/current-release" -delete || return
      fi
      ;;
    *) return 94 ;;
  esac
}
restore_packet() {
  local relative state archive target
  while IFS=$'\t' read -r relative state archive target; do
    restore_code_root "$relative" "$state" "$archive" "$target" || return
  done < "$preflight_file"
  mkdir -p "$site/wp-content/uploads" || return
  assert_physical_dir "$site/wp-content/uploads" '/www/goetzgoetz_755/public/wp-content/uploads' || return
  rsync --archive --delete-delay --checksum "$restore_work/uploads-packet/wp-content/uploads/" "$site/wp-content/uploads/" || return
  wp --path="$site" db import "$backup/database.sql" || return
  restore_release_state || return
  wp --path="$site" rewrite flush --hard || return
  wp --path="$site" cache flush || return
  wp --path="$site" kinsta cache purge --all 2>/dev/null || return
  [[ "$(wp --path="$site" option get home)" == "$staging" ]] || return
  [[ "$(wp --path="$site" option get siteurl)" == "$staging" ]] || return
}
cleanup_work() {
  if [[ -n "$restore_work" && -d "$restore_work" && ! -L "$restore_work" ]]; then
    find "$restore_work" -xdev -depth -mindepth 1 -delete
    rmdir "$restore_work"
  fi
  if [[ -n "$preflight_file" && -f "$preflight_file" && ! -L "$preflight_file" ]]; then
    find "$preflight_file" -delete
  fi
}
apply_failed() {
  local status=$?
  trap - ERR
  set +e
  write_phase deploy_failed
  if (( mutation_started == 1 )); then
    if restore_packet; then
      write_phase auto_rollback_succeeded
    else
      write_phase auto_rollback_failed_manual_intervention_required
      status=97
    fi
  fi
  cleanup_work
  exit "$status"
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
scan_public_dumps() {
  ! find "$site" -xdev -type f \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' -o -name '*.bak' \
    -o -name 'release.json' -o -name 'RELEASE-MANIFEST.sha256' -o -name '.env*' \) -print -quit | grep -q .
}
smoke_exact_route() {
  local route="$1" effective
  effective="$(curl --fail --silent --show-error --location --max-redirs 3 \
    --connect-timeout 10 --max-time 30 \
    --proto '=https' --proto-redir '=https' --output /dev/null \
    --write-out '%{url_effective}' "$staging$route")" ||
    die "route smoke failed: $route"
  [[ "$effective" == "$staging$route" ]] ||
    die "effective URL escaped the exact requested route: $route"
}

[[ "$release" == "/www/goetzgoetz_755/private/releases/$release_sha" ]]
[[ "$backup" == /www/goetzgoetz_755/private/backups/* && "$backup" != '/www/goetzgoetz_755/private/backups/' ]]
backup_id="${backup##*/}"
[[ "$backup_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
[[ "$release_sha" =~ ^[0-9a-f]{40}$ && "$expected_backup_hash" =~ ^[0-9a-f]{64}$ && "$expected_release_hash" =~ ^[0-9a-f]{64}$ ]]
for command_name in wp php rsync tar gzip sha256sum find readlink flock curl awk sort stat tail grep mktemp; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command unavailable: $command_name"
done
assert_physical_dir "$site" '/www/goetzgoetz_755/public'
assert_physical_dir "$private" '/www/goetzgoetz_755/private'
assert_physical_dir "$release" "$release"
assert_physical_dir "$release/payload" "$release/payload"
assert_physical_dir "$backup" "$backup"
assert_physical_dir "$site/wp-content" '/www/goetzgoetz_755/public/wp-content'
assert_physical_dir "$site/wp-content/plugins" '/www/goetzgoetz_755/public/wp-content/plugins'
assert_physical_dir "$site/wp-content/themes" '/www/goetzgoetz_755/public/wp-content/themes'
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]]

mkdir -m 0700 -p "$private/operations" "$private/locks" "$private/state"
assert_physical_dir "$private/operations" '/www/goetzgoetz_755/private/operations'
assert_physical_dir "$private/locks" '/www/goetzgoetz_755/private/locks'
assert_physical_dir "$private/state" '/www/goetzgoetz_755/private/state'
lock_file="$private/locks/release-mutation.lock"
[[ -f "$lock_file" && ! -L "$lock_file" ]] || die 'shared mutation lock is missing or redirected'
exec 9>>"$lock_file"
flock -n 9 || die 'another release mutation is in progress'

# Every state-dependent deployment preflight is evaluated from the same locked
# snapshot that will be mutated. Re-assert physical roots after acquiring the
# lock so a stale pre-lock observation can never authorize runtime rsync.
assert_physical_dir "$site" '/www/goetzgoetz_755/public'
assert_physical_dir "$release" "$release"
assert_physical_dir "$release/payload" "$release/payload"
assert_physical_dir "$backup" "$backup"
assert_physical_dir "$site/wp-content" '/www/goetzgoetz_755/public/wp-content'
assert_physical_dir "$site/wp-content/plugins" '/www/goetzgoetz_755/public/wp-content/plugins'
assert_physical_dir "$site/wp-content/themes" '/www/goetzgoetz_755/public/wp-content/themes'
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]]
! find "$release/payload" -type l -print -quit | grep -q .
[[ "$(find "$release/payload" -type f ! -name RELEASE-MANIFEST.sha256 -printf './%P\n' | LC_ALL=C sort)" == \
    "$(awk '{print $2}' "$release/payload/RELEASE-MANIFEST.sha256" | LC_ALL=C sort)" ]]
[[ "$(sha256sum "$release/payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)" == "$expected_release_hash" ]]
(cd "$release/payload" && sha256sum --check --strict RELEASE-MANIFEST.sha256 >/dev/null)
[[ "$(sha256sum "$backup/SHA256SUMS" | cut -d' ' -f1)" == "$expected_backup_hash" ]]
(cd "$backup" && sha256sum --check --strict SHA256SUMS >/dev/null)
mapfile -t metadata < "$backup/BACKUP-METADATA"
(( ${#metadata[@]} == 9 ))
[[ "${metadata[0]}" == 'schema_version=1' && "${metadata[1]}" == "backup_id=$backup_id" ]]
[[ "${metadata[2]}" == 'purpose=pre-deployment' && "${metadata[4]}" == "site_path=$site" ]]
[[ "${metadata[5]}" == "home_url=$staging" && "${metadata[6]}" == "site_url=$staging" ]]
[[ "${metadata[7]}" == "release_commit=$release_sha" && "${metadata[8]}" == "release_manifest_sha256=$expected_release_hash" ]]
require_single_site
[[ "$(wp --path="$site" option get home)" == "$staging" ]]
[[ "$(wp --path="$site" option get siteurl)" == "$staging" ]]
scan_public_dumps

preflight_file="$private/operations/.deploy-$release_sha-$backup_id.preflight.$$"
: > "$preflight_file"
preflight_backup_root 'wp-content/plugins/goetz-site' 'code-plugin-goetz-site.tar.gz' "$site/wp-content/plugins/goetz-site" >> "$preflight_file"
preflight_backup_root 'wp-content/themes/goetz-legal' 'code-theme-goetz-legal.tar.gz' "$site/wp-content/themes/goetz-legal" >> "$preflight_file"
preflight_backup_root 'wp-content/plugins/goetz-migration' 'code-plugin-goetz-migration.tar.gz' "$site/wp-content/plugins/goetz-migration" >> "$preflight_file"
preflight_backup_root 'wp-content/plugins/wordpress-seo' 'code-plugin-wordpress-seo.tar.gz' "$site/wp-content/plugins/wordpress-seo" >> "$preflight_file"
preflight_backup_root 'wp-content/plugins/wpforms-lite' 'code-plugin-wpforms-lite.tar.gz' "$site/wp-content/plugins/wpforms-lite" >> "$preflight_file"
[[ "$(wc -l < "$preflight_file")" -eq 5 ]]
for source_root in \
  "$release/payload/wp-content/plugins/goetz-site" \
  "$release/payload/wp-content/themes/goetz-legal" \
  "$release/payload/wp-content/plugins/goetz-migration" \
  "$release/payload/wp-content/plugins/wordpress-seo" \
  "$release/payload/wp-content/plugins/wpforms-lite"; do
  assert_physical_dir "$source_root" "$source_root"
done
test -s "$release/payload/wp-content/themes/goetz-legal/dist/.vite/manifest.json"
test -s "$release/payload/wp-content/themes/goetz-legal/vendor/autoload.php"
test -s "$release/payload/wp-content/plugins/goetz-site/build/index.js"
test -s "$release/payload/wp-content/plugins/goetz-site/build/index.asset.php"

restore_work="$private/deploy-recovery-$release_sha-$backup_id-$$"
mkdir -m 0700 "$restore_work" "$restore_work/uploads-packet"
while IFS=$'\t' read -r relative state archive target; do
  if [[ "$state" == 'present' ]]; then
    tar --no-same-owner --no-same-permissions -xzf "$backup/$archive" -C "$restore_work"
  fi
done < "$preflight_file"
tar --no-same-owner --no-same-permissions -xzf "$backup/uploads.tar.gz" -C "$restore_work/uploads-packet"
mkdir -p "$restore_work/uploads-packet/wp-content/uploads"
! find "$restore_work" -type l -print -quit | grep -q .

debug_file="$site/wp-content/debug.log"
if [[ -f "$debug_file" && ! -L "$debug_file" ]]; then
  debug_offset="$(stat -c %s "$debug_file")"
  debug_inode="$(stat -c %i "$debug_file")"
fi
write_phase preflight_complete
trap cleanup_work EXIT
trap apply_failed ERR
mutation_started=1
write_phase syncing_code

sync_release_root() {
  local source="$1" target="$2"
  case "$source:$target" in
    "$release/payload/wp-content/plugins/goetz-site:$site/wp-content/plugins/goetz-site"|\
    "$release/payload/wp-content/themes/goetz-legal:$site/wp-content/themes/goetz-legal"|\
    "$release/payload/wp-content/plugins/goetz-migration:$site/wp-content/plugins/goetz-migration"|\
    "$release/payload/wp-content/plugins/wordpress-seo:$site/wp-content/plugins/wordpress-seo"|\
    "$release/payload/wp-content/plugins/wpforms-lite:$site/wp-content/plugins/wpforms-lite") ;;
    *) return 91 ;;
  esac
  assert_physical_dir "$source" "$source"
  assert_target "$target" "$target"
  mkdir -p "$target"
  assert_physical_dir "$target" "$target"
  rsync --archive --delete-delay --checksum "$source/" "$target/"
}

sync_release_root "$release/payload/wp-content/plugins/goetz-site" "$site/wp-content/plugins/goetz-site"
wp --path="$site" plugin activate goetz-site
sync_release_root "$release/payload/wp-content/themes/goetz-legal" "$site/wp-content/themes/goetz-legal"
sync_release_root "$release/payload/wp-content/plugins/goetz-migration" "$site/wp-content/plugins/goetz-migration"
sync_release_root "$release/payload/wp-content/plugins/wordpress-seo" "$site/wp-content/plugins/wordpress-seo"
sync_release_root "$release/payload/wp-content/plugins/wpforms-lite" "$site/wp-content/plugins/wpforms-lite"

write_phase activating
[[ "$(wp --path="$site" plugin get goetz-site --field=version)" == '1.0.0' ]]
[[ "$(wp --path="$site" plugin get goetz-migration --field=version)" == '1.1.0' ]]
[[ "$(wp --path="$site" plugin get wordpress-seo --field=version)" == '28.0' ]]
[[ "$(wp --path="$site" plugin get wpforms-lite --field=version)" == '1.10.0.4' ]]
wp --path="$site" theme activate goetz-legal
wp --path="$site" plugin activate goetz-site goetz-migration wordpress-seo wpforms-lite

write_phase migrating
homepage_plan="$(wp --path="$site" goetz-site migrate homepage --dry-run --format=json)"
validate_json_status "$homepage_plan" 'ready,noop'
homepage_apply="$(wp --path="$site" goetz-site migrate homepage --apply --format=json)"
validate_json_status "$homepage_apply" 'updated,noop'
homepage_noop="$(wp --path="$site" goetz-site migrate homepage --apply --format=json)"
validate_json_status "$homepage_noop" 'noop'
seo_first="$(wp --path="$site" goetz-site seo configure --strict --format=json)"
validate_json_status "$seo_first" 'configured,noop'
seo_noop="$(wp --path="$site" goetz-site seo configure --strict --format=json)"
validate_json_status "$seo_noop" 'noop'
wp --path="$site" yoast index --reindex --skip-confirmation
wp --path="$site" rewrite flush --hard
wp --path="$site" cache flush
wp --path="$site" kinsta cache purge --all 2>/dev/null

write_phase verifying
[[ "$(wp --path="$site" option get home)" == "$staging" ]]
[[ "$(wp --path="$site" option get siteurl)" == "$staging" ]]
scan_public_dumps
if [[ -f "$debug_file" && ! -L "$debug_file" ]]; then
  debug_size="$(stat -c %s "$debug_file")"
  if (( debug_size < debug_offset )); then debug_offset=0; fi
  if (( debug_size > debug_offset )); then
    if tail -c "+$((debug_offset + 1))" "$debug_file" | grep -Eq 'PHP (Fatal|Parse) error'; then
      die 'a new PHP fatal or parse error was written during deployment'
    fi
  fi
fi
for route in '/' '/james-l-goetz/' '/gregory-w-goetz/' '/staff/' '/questions/' '/links/' '/contact/'; do
  smoke_exact_route "$route"
done

current_tmp="$(mktemp "$private/state/.current-release.XXXXXX")"
cat > "$current_tmp" <<STATE
schema_version=1
release_commit=$release_sha
release_manifest_sha256=$expected_release_hash
backup_id=$backup_id
deployed_utc=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
debug_log_inode=$debug_inode
debug_log_offset=$debug_offset
STATE
chmod 0600 "$current_tmp"
mv -f -- "$current_tmp" "$private/state/current-release"
write_phase complete
trap - ERR
REMOTE
then
  printf 'Deployment failed. Remote recovery ran while holding the shared mutation lock.\n' >&2
  printf 'Inspect remote receipt: %s/operations/deploy-%s-%s.status\n' \
    "$GOETZ_REMOTE_PRIVATE" "$GOETZ_RELEASE_SHA" "$backup_id" >&2
  printf 'manager_rollback_command=./manager.sh remote:rollback --backup-id=%q --apply\n' "$backup_id" >&2
  exit 1
fi

printf 'release_commit=%s\n' "$GOETZ_RELEASE_SHA"
printf 'remote_release=%s\n' "$remote_release"
printf 'backup_id=%s\n' "$backup_id"
printf 'manifest_sha256=%s\n' "$GOETZ_RELEASE_DIGEST"
printf 'manager_rollback_command=./manager.sh remote:rollback --backup-id=%q --apply\n' "$backup_id"
