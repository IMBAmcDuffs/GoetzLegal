#!/usr/bin/env bash
set -euo pipefail

unset SSH_KEY_PW
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly GOETZ_RELEASE_ROOT="$root/scripts/release"
readonly GOETZ_LOCAL_BACKUP_ROOT="$root/__dev/kinsta-backups"
GOETZ_COMMAND_NAME='remote-rollback'
# shellcheck source=scripts/release/common.sh
source "$GOETZ_RELEASE_ROOT/common.sh"

backup_id=''
apply=0
backup_seen=0
mode_seen=0
for argument in "$@"; do
  case "$argument" in
    --backup-id=*)
      (( backup_seen == 0 )) || goetz_fail '--backup-id was supplied more than once'
      backup_id="${argument#*=}"
      backup_seen=1
      ;;
    --dry-run)
      (( mode_seen == 0 )) || goetz_fail 'choose exactly one rollback mode'
      apply=0
      mode_seen=1
      ;;
    --apply)
      (( mode_seen == 0 )) || goetz_fail 'choose exactly one rollback mode'
      apply=1
      mode_seen=1
      ;;
    *) goetz_fail "unknown argument: $argument" ;;
  esac
done
[[ -n "$backup_id" ]] || goetz_fail 'usage: rollback.sh --backup-id=<verified-backup> [--dry-run|--apply]'
goetz_validate_backup_id "$backup_id"
goetz_require_kinsta
goetz_verify_local_backup "$backup_id"
goetz_remote_verify_backup_digest

mode='dry-run'
(( apply == 0 )) || mode='apply'
goetz_ssh bash -s -- "$GOETZ_REMOTE_BACKUP" "$GOETZ_BACKUP_DIGEST" "$mode" <<'REMOTE'
# GOETZ_REMOTE_ROLLBACK
set -Eeuo pipefail
umask 077
backup="$1"
expected_backup_hash="$2"
mode="$3"
site='/www/goetzgoetz_755/public'
private='/www/goetzgoetz_755/private'
rollback_failure_trap_active=0

die() {
  printf 'remote rollback: %s\n' "$1" >&2
  if (( rollback_failure_trap_active == 1 )); then return 1; fi
  exit 1
}
assert_physical_dir() {
  local path="$1"
  local expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" ]] || die "unsafe directory: $expected"
  [[ "$(readlink -f -- "$path")" == "$expected" ]] || die "redirected directory: $expected"
}
assert_target() {
  local target="$1"
  local expected="$2"
  local parent="${target%/*}"
  [[ "$target" == "$expected" ]]
  assert_physical_dir "$parent" "$parent"
  if [[ -e "$target" || -L "$target" ]]; then
    assert_physical_dir "$target" "$expected"
  fi
}
write_phase() {
  local phase="$1"
  local receipt="$private/operations/rollback-$backup_id.status"
  local temporary
  [[ ! -L "$receipt" ]]
  temporary="$(mktemp "$private/operations/.rollback-$backup_id.status.XXXXXX")"
  printf 'schema_version=1\noperation=rollback\nbackup_id=%s\nphase=%s\nupdated_utc=%s\n' \
    "$backup_id" "$phase" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$temporary"
  chmod 0600 "$temporary"
  mv -f -- "$temporary" "$receipt"
}
require_single_site() {
  [[ "$(wp --path="$site" eval 'echo is_multisite() ? "yes" : "no";')" == 'no' ]] ||
    die 'multisite is not supported by this release toolchain'
}
validate_metadata() {
  local -a lines=()
  mapfile -t lines < "$backup/BACKUP-METADATA"
  (( ${#lines[@]} == 9 )) || die 'backup metadata field count is invalid'
  [[ "${lines[0]}" == 'schema_version=1' ]]
  [[ "${lines[1]}" == "backup_id=$backup_id" ]]
  case "${lines[2]}" in purpose=pre-deployment|purpose=pre-domain-cutover|purpose=manual|purpose=automatic-recovery) ;; *) die 'backup purpose is invalid' ;; esac
  [[ "${lines[3]}" =~ ^created_utc=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]
  [[ "${lines[4]}" == "site_path=$site" ]]
  backup_home="${lines[5]#home_url=}"
  backup_siteurl="${lines[6]#site_url=}"
  [[ "$backup_home" == "$backup_siteurl" ]]
  case "$backup_home" in 'https://goetzgoetz.kinsta.cloud'|'https://goetzlegal.com') ;; *) die 'backup origin is not approved' ;; esac
  backup_release_sha="${lines[7]#release_commit=}"
  backup_release_digest="${lines[8]#release_manifest_sha256=}"
  [[ "$backup_release_sha" == 'none' || "$backup_release_sha" =~ ^[0-9a-f]{40}$ ]]
  [[ "$backup_release_digest" == 'none' || "$backup_release_digest" =~ ^[0-9a-f]{64}$ ]]
}
validate_archive_entry_prefix() {
  local archive="$1"
  local prefix="$2"
  local entry
  while IFS= read -r entry; do
    [[ -n "$entry" ]]
    [[ "$entry" != /* && "$entry" != *'/../'* && "$entry" != '../'* && "$entry" != *'/..' ]]
    [[ "$entry" == "$prefix" || "$entry" == "$prefix/"* ]]
  done < <(tar -tzf "$archive")
}
state_for_root() {
  local relative_root="$1"
  awk -F '\t' -v wanted="$relative_root" '$1 == wanted { count++; value=$2 "\t" $3 } END { if (count == 1) print value; else exit 1 }' "$backup/code-state.tsv"
}
preflight_root() {
  local relative_root="$1"
  local archive_name="$2"
  local target="$3"
  local recorded state recorded_archive
  recorded="$(state_for_root "$relative_root")" || die "missing or duplicate code state: $relative_root"
  IFS=$'\t' read -r state recorded_archive <<< "$recorded"
  assert_target "$target" "$target"
  case "$state" in
    present)
      [[ "$recorded_archive" == "$archive_name" && -f "$backup/$archive_name" && ! -L "$backup/$archive_name" && -s "$backup/$archive_name" ]] ||
        die "invalid archive state for $relative_root"
      gzip -t "$backup/$archive_name"
      validate_archive_entry_prefix "$backup/$archive_name" "$relative_root"
      ;;
    absent) [[ "$recorded_archive" == '-' ]] || die "invalid absent state for $relative_root" ;;
    *) die "invalid code state for $relative_root" ;;
  esac
  printf '%s\t%s\t%s\t%s\n' "$relative_root" "$state" "$archive_name" "$target"
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
    --write-out '%{url_effective}' "$backup_home$route")" ||
    die "route smoke failed: $route"
  [[ "$effective" == "$backup_home$route" ]] ||
    die "effective URL escaped the exact requested route: $route"
}

[[ "$mode" == 'dry-run' || "$mode" == 'apply' ]]
[[ "$backup" == /www/goetzgoetz_755/private/backups/* && "$backup" != '/www/goetzgoetz_755/private/backups/' ]]
backup_id="${backup##*/}"
[[ "$backup_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ && "$expected_backup_hash" =~ ^[0-9a-f]{64}$ ]]
for command_name in wp rsync tar gzip sha256sum find readlink flock curl awk cmp mktemp; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command unavailable: $command_name"
done
assert_physical_dir "$site" '/www/goetzgoetz_755/public'
assert_physical_dir "$private" '/www/goetzgoetz_755/private'
assert_physical_dir "$backup" "$backup"
assert_physical_dir "$site/wp-content" '/www/goetzgoetz_755/public/wp-content'
assert_physical_dir "$site/wp-content/plugins" '/www/goetzgoetz_755/public/wp-content/plugins'
assert_physical_dir "$site/wp-content/themes" '/www/goetzgoetz_755/public/wp-content/themes'
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]]
[[ -f "$backup/SHA256SUMS" && ! -L "$backup/SHA256SUMS" ]]
[[ "$(sha256sum "$backup/SHA256SUMS" | cut -d' ' -f1)" == "$expected_backup_hash" ]]
(
  cd "$backup"
  sha256sum --check --strict SHA256SUMS >/dev/null
)
for required_nonempty in database.sql uploads.tar.gz code-state.tsv active-theme.txt home-url.txt site-url.txt \
  wordpress-version.txt BACKUP-METADATA release-state.tsv SHA256SUMS; do
  [[ -f "$backup/$required_nonempty" && ! -L "$backup/$required_nonempty" && -s "$backup/$required_nonempty" ]] ||
    die "backup entry is missing or empty: $required_nonempty"
done
for required_file in active-plugins.txt must-use-plugins.txt; do
  [[ -f "$backup/$required_file" && ! -L "$backup/$required_file" ]] || die "backup entry is missing: $required_file"
done
[[ "$(wc -l < "$backup/code-state.tsv")" -eq 5 ]]
gzip -t "$backup/uploads.tar.gz"
while IFS= read -r upload_entry; do
  [[ -n "$upload_entry" ]]
  [[ "$upload_entry" != /* && "$upload_entry" != *'/../'* && "$upload_entry" != '../'* && "$upload_entry" != *'/..' ]]
  [[ "$upload_entry" == 'wp-content/uploads' || "$upload_entry" == 'wp-content/uploads/'* ]]
done < <(tar -tzf "$backup/uploads.tar.gz")
case "$(cat "$backup/release-state.tsv")" in
  $'present\tprevious-current-release')
    [[ -f "$backup/previous-current-release" && ! -L "$backup/previous-current-release" && -s "$backup/previous-current-release" ]]
    ;;
  $'absent\t-') ;;
  *) die 'backup release-state.tsv is invalid' ;;
esac
validate_metadata
require_single_site

lock_file="$private/locks/release-mutation.lock"
[[ -f "$lock_file" && ! -L "$lock_file" ]] || die 'shared mutation lock is missing or redirected'
exec 9>>"$lock_file"
flock -n 9 || die 'another release mutation is in progress'

assert_physical_dir "$private/operations" '/www/goetzgoetz_755/private/operations'
if [[ -e "$private/state" || -L "$private/state" ]]; then
  assert_physical_dir "$private/state" '/www/goetzgoetz_755/private/state'
fi
preflight_data="$({
  preflight_root 'wp-content/plugins/goetz-site' 'code-plugin-goetz-site.tar.gz' "$site/wp-content/plugins/goetz-site"
  preflight_root 'wp-content/themes/goetz-legal' 'code-theme-goetz-legal.tar.gz' "$site/wp-content/themes/goetz-legal"
  preflight_root 'wp-content/plugins/goetz-migration' 'code-plugin-goetz-migration.tar.gz' "$site/wp-content/plugins/goetz-migration"
  preflight_root 'wp-content/plugins/wordpress-seo' 'code-plugin-wordpress-seo.tar.gz' "$site/wp-content/plugins/wordpress-seo"
  preflight_root 'wp-content/plugins/wpforms-lite' 'code-plugin-wpforms-lite.tar.gz' "$site/wp-content/plugins/wpforms-lite"
})"
[[ "$(wc -l <<< "$preflight_data")" -eq 5 ]]

if [[ "$mode" == 'dry-run' ]]; then
  printf 'rollback_preflight=passed\nbackup_id=%s\nbackup_origin=%s\n' "$backup_id" "$backup_home"
  while IFS=$'\t' read -r relative state archive target; do
    printf 'would_restore_code=%s state=%s archive=%s target=%s\n' "$relative" "$state" "$archive" "$target"
  done <<< "$preflight_data"
  printf 'would_restore_uploads=%s -> %s\n' "$backup/uploads.tar.gz" "$site/wp-content/uploads"
  printf 'would_restore_database=%s\n' "$backup/database.sql"
  printf 'would_verify_state=theme,plugins,must-use,home,siteurl\n'
  printf 'would_flush=rewrite,object-cache,kinsta-cache\n'
  printf 'would_smoke_routes=/,/james-l-goetz/,/gregory-w-goetz/,/staff/,/questions/,/links/,/contact/\n'
  exit 0
fi

mkdir -m 0700 -p "$private/state"
assert_physical_dir "$private/operations" '/www/goetzgoetz_755/private/operations'
assert_physical_dir "$private/state" '/www/goetzgoetz_755/private/state'
preflight_file="$private/operations/.rollback-$backup_id.preflight.$$"
printf '%s\n' "$preflight_data" > "$preflight_file"

restore_work="$private/rollback-work-$backup_id-$$"
[[ ! -e "$restore_work" && ! -L "$restore_work" ]]
mkdir -m 0700 "$restore_work"
cleanup_work() {
  if [[ -d "$restore_work" && ! -L "$restore_work" ]]; then
    find "$restore_work" -depth -mindepth 1 -delete
    rmdir "$restore_work"
  fi
  [[ ! -f "$preflight_file" || -L "$preflight_file" ]] || find "$preflight_file" -delete
}
rollback_failed() {
  local status=$?
  trap - ERR
  set +e
  write_phase rollback_failed_manual_intervention_required
  cleanup_work
  exit "$status"
}
trap cleanup_work EXIT
trap rollback_failed ERR
rollback_failure_trap_active=1

while IFS=$'\t' read -r relative state archive target; do
  if [[ "$state" == 'present' ]]; then
    tar --no-same-owner --no-same-permissions -xzf "$backup/$archive" -C "$restore_work"
  fi
done < "$preflight_file"
mkdir -p "$restore_work/uploads-packet"
tar --no-same-owner --no-same-permissions -xzf "$backup/uploads.tar.gz" -C "$restore_work/uploads-packet"
if find "$restore_work" -type l -print -quit | grep -q .; then
  die 'backup archives extracted a symbolic link'
fi
write_phase preflight_complete

restore_code_root() {
  local relative="$1" state="$2" archive="$3" target="$4"
  assert_target "$target" "$target"
  case "$state" in
    present)
      [[ -d "$restore_work/$relative" && ! -L "$restore_work/$relative" ]]
      mkdir -p "$target"
      assert_physical_dir "$target" "$target"
      rsync --archive --delete-delay --checksum "$restore_work/$relative/" "$target/"
      ;;
    absent)
      if [[ -e "$target" || -L "$target" ]]; then
        assert_physical_dir "$target" "$target"
        find "$target" -xdev -depth -mindepth 1 -delete
        rmdir "$target"
      fi
      ;;
    *) return 93 ;;
  esac
}

write_phase restoring_code
while IFS=$'\t' read -r relative state archive target; do
  restore_code_root "$relative" "$state" "$archive" "$target"
done < "$preflight_file"

write_phase restoring_uploads
uploads_source="$restore_work/uploads-packet/wp-content/uploads"
mkdir -p "$uploads_source"
if [[ -e "$site/wp-content/uploads" || -L "$site/wp-content/uploads" ]]; then
  assert_physical_dir "$site/wp-content/uploads" '/www/goetzgoetz_755/public/wp-content/uploads'
else
  mkdir "$site/wp-content/uploads"
fi
rsync --archive --delete-delay --checksum "$uploads_source/" "$site/wp-content/uploads/"

write_phase restoring_database
wp --path="$site" db import "$backup/database.sql"

write_phase restoring_release_state
case "$(cat "$backup/release-state.tsv")" in
  $'present\tprevious-current-release')
    [[ -f "$backup/previous-current-release" && ! -L "$backup/previous-current-release" ]]
    state_tmp="$(mktemp "$private/state/.current-release.XXXXXX")"
    cp -- "$backup/previous-current-release" "$state_tmp"
    chmod 0600 "$state_tmp"
    mv -f -- "$state_tmp" "$private/state/current-release"
    ;;
  $'absent\t-')
    if [[ -e "$private/state/current-release" || -L "$private/state/current-release" ]]; then
      [[ -f "$private/state/current-release" && ! -L "$private/state/current-release" ]]
      find "$private/state/current-release" -delete
    fi
    ;;
  *) die 'backup release-state.tsv is invalid' ;;
esac

write_phase verifying_state
wp --path="$site" theme list --status=active --field=name | LC_ALL=C sort > "$restore_work/active-theme.current"
wp --path="$site" plugin list --status=active --field=name | LC_ALL=C sort > "$restore_work/active-plugins.current"
wp --path="$site" plugin list --status=must-use --field=name | LC_ALL=C sort > "$restore_work/must-use-plugins.current"
cmp -s "$backup/active-theme.txt" "$restore_work/active-theme.current"
cmp -s "$backup/active-plugins.txt" "$restore_work/active-plugins.current"
cmp -s "$backup/must-use-plugins.txt" "$restore_work/must-use-plugins.current"
[[ "$(wp --path="$site" option get home)" == "$backup_home" ]]
[[ "$(wp --path="$site" option get siteurl)" == "$backup_siteurl" ]]

wp --path="$site" rewrite flush --hard
wp --path="$site" cache flush
wp --path="$site" kinsta cache purge --all 2>/dev/null
scan_public_dumps

write_phase smoke
for route in '/' '/james-l-goetz/' '/gregory-w-goetz/' '/staff/' '/questions/' '/links/' '/contact/'; do
  smoke_exact_route "$route"
done
write_phase complete
trap - ERR
REMOTE

if (( apply == 0 )); then
  printf 'rollback_mode=dry-run\n'
  printf 'backup_id=%s\n' "$backup_id"
  printf 'manager_apply_command=./manager.sh remote:rollback --backup-id=%q --apply\n' "$backup_id"
else
  printf 'rollback_mode=applied\n'
  printf 'backup_id=%s\n' "$backup_id"
  printf 'restored_remote_path=%s\n' "$GOETZ_REMOTE_BACKUP"
fi
