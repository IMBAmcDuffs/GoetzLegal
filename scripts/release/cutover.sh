#!/usr/bin/env bash
set -euo pipefail

unset SSH_KEY_PW
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly GOETZ_RELEASE_ROOT="$root/scripts/release"
readonly GOETZ_LOCAL_BACKUP_ROOT="$root/__dev/kinsta-backups"
GOETZ_COMMAND_NAME='remote-cutover'
# shellcheck source=scripts/release/common.sh
source "$GOETZ_RELEASE_ROOT/common.sh"

from=''
to=''
backup_id=''
apply=0
from_seen=0
to_seen=0
backup_seen=0
mode_seen=0
for argument in "$@"; do
  case "$argument" in
    --from=*)
      (( from_seen == 0 )) || goetz_fail '--from was supplied more than once'
      from="${argument#*=}"
      from_seen=1
      ;;
    --to=*)
      (( to_seen == 0 )) || goetz_fail '--to was supplied more than once'
      to="${argument#*=}"
      to_seen=1
      ;;
    --backup-id=*)
      (( backup_seen == 0 )) || goetz_fail '--backup-id was supplied more than once'
      backup_id="${argument#*=}"
      backup_seen=1
      ;;
    --dry-run)
      (( mode_seen == 0 )) || goetz_fail 'choose exactly one cutover mode'
      apply=0
      mode_seen=1
      ;;
    --apply)
      (( mode_seen == 0 )) || goetz_fail 'choose exactly one cutover mode'
      apply=1
      mode_seen=1
      ;;
    *) goetz_fail "unknown argument: $argument" ;;
  esac
done
[[ -n "$from" && -n "$to" && -n "$backup_id" ]] ||
  goetz_fail 'usage: cutover.sh --from=<current-url> --to=<new-url> --backup-id=<verified-pre-domain-cutover-backup> [--apply]'
[[ "$from" == "$GOETZ_STAGING_ORIGIN" ]] || goetz_fail 'cutover source must be the exact verified Kinsta staging origin'
[[ "$to" == "$GOETZ_PRODUCTION_ORIGIN" ]] || goetz_fail 'cutover destination must be the exact final production origin'
goetz_validate_backup_id "$backup_id"
goetz_require_kinsta
goetz_verify_local_backup "$backup_id" pre-domain-cutover
[[ "$GOETZ_BACKUP_HOME" == "$from" && "$GOETZ_BACKUP_SITEURL" == "$from" ]] ||
  goetz_fail 'cutover backup is not bound to the exact staging origin'
[[ "$GOETZ_BACKUP_RELEASE_SHA" =~ ^[0-9a-f]{40}$ && "$GOETZ_BACKUP_RELEASE_DIGEST" =~ ^[0-9a-f]{64}$ ]] ||
  goetz_fail 'cutover backup is not coupled to a deployed release digest'
goetz_remote_verify_backup_digest

mode='dry-run'
(( apply == 0 )) || mode='apply'
if ! goetz_ssh bash -s -- \
  "$from" "$to" "$GOETZ_REMOTE_BACKUP" "$GOETZ_BACKUP_DIGEST" \
  "$GOETZ_BACKUP_RELEASE_SHA" "$GOETZ_BACKUP_RELEASE_DIGEST" "$mode" <<'REMOTE'
# GOETZ_REMOTE_CUTOVER
set -Eeuo pipefail
umask 077
from="$1"
to="$2"
backup="$3"
expected_backup_hash="$4"
release_sha="$5"
release_digest="$6"
mode="$7"
site='/www/goetzgoetz_755/public'
private='/www/goetzgoetz_755/private'
backup_id="${backup##*/}"
mutation_started=0
failure_handling=0
# Keep recovery parent-only even though ERR inheritance is enabled for helper
# functions: command-substitution children propagate their status without
# touching the database or durable receipt.
operation_shell_pid="$BASHPID"

die() { printf 'remote cutover: %s\n' "$1" >&2; return 1; }
assert_physical_dir() {
  local path="$1" expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" && "$(readlink -f -- "$path")" == "$expected" ]] ||
    die "unsafe or redirected directory: $expected"
}
write_phase() {
  local phase="$1"
  local receipt="$private/operations/cutover-$backup_id.status"
  local temporary
  [[ ! -L "$receipt" ]]
  temporary="$(mktemp "$private/operations/.cutover-$backup_id.status.XXXXXX")"
  printf 'schema_version=1\noperation=cutover\nbackup_id=%s\nrelease_commit=%s\nphase=%s\nupdated_utc=%s\n' \
    "$backup_id" "$release_sha" "$phase" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$temporary"
  chmod 0600 "$temporary"
  mv -f -- "$temporary" "$receipt"
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
handle_cutover_failure() {
  local status="$1" failure_phase="$2"
  if [[ "$BASHPID" != "$operation_shell_pid" ]]; then
    trap - ERR
    exit "$status"
  fi
  if (( failure_handling == 1 )); then
    exit 97
  fi
  failure_handling=1
  trap - ERR
  trap '' HUP INT TERM
  set +e
  (( status != 0 )) || status=1
  write_phase "$failure_phase"
  if (( mutation_started == 1 )); then
    if wp --path="$site" db import "$backup/database.sql" &&
      [[ "$(wp --path="$site" option get home)" == "$from" ]] &&
      [[ "$(wp --path="$site" option get siteurl)" == "$from" ]] &&
      wp --path="$site" rewrite flush --hard >/dev/null 2>&1 &&
      wp --path="$site" cache flush >/dev/null 2>&1 &&
      wp --path="$site" kinsta cache purge --all >/dev/null 2>&1 &&
      scan_public_dumps; then
      if ! write_phase auto_rollback_succeeded; then status=97; fi
    else
      write_phase auto_rollback_failed_manual_intervention_required || true
      status=97
    fi
  fi
  exit "$status"
}
cutover_failed() {
  local status=$?
  handle_cutover_failure "$status" cutover_failed
}
cutover_interrupted() {
  local signal_name="$1" status="$2"
  handle_cutover_failure "$status" "cutover_interrupted_${signal_name,,}"
}

[[ "$from" == 'https://goetzgoetz.kinsta.cloud' && "$to" == 'https://goetzlegal.com' && "$mode" =~ ^(dry-run|apply)$ ]]
[[ "$backup" == /www/goetzgoetz_755/private/backups/* && "$backup" != '/www/goetzgoetz_755/private/backups/' ]]
[[ "$backup_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
[[ "$expected_backup_hash" =~ ^[0-9a-f]{64}$ && "$release_sha" =~ ^[0-9a-f]{40}$ && "$release_digest" =~ ^[0-9a-f]{64}$ ]]
for command_name in wp php sha256sum readlink flock find grep date mktemp; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command unavailable: $command_name"
done
assert_physical_dir "$site" '/www/goetzgoetz_755/public'
assert_physical_dir "$private" '/www/goetzgoetz_755/private'
assert_physical_dir "$backup" "$backup"
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]]

assert_physical_dir "$private/locks" '/www/goetzgoetz_755/private/locks'
lock_file="$private/locks/release-mutation.lock"
[[ -f "$lock_file" && ! -L "$lock_file" ]] || die 'shared mutation lock is missing or redirected'
exec 9>>"$lock_file"
flock -n 9 || die 'another release mutation is in progress'

# The backup, deployed-release receipt, and WordPress origin are one locked
# preflight snapshot. None of these state checks may authorize a later mutation
# from observations made before the shared lock was acquired.
assert_physical_dir "$site" '/www/goetzgoetz_755/public'
assert_physical_dir "$backup" "$backup"
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]]
[[ "$(wp --path="$site" eval 'echo is_multisite() ? "yes" : "no";')" == 'no' ]] || die 'multisite is not supported'
[[ "$(sha256sum "$backup/SHA256SUMS" | cut -d' ' -f1)" == "$expected_backup_hash" ]]
(cd "$backup" && sha256sum --check --strict SHA256SUMS >/dev/null)
[[ -s "$backup/database.sql" && ! -L "$backup/database.sql" ]]
mapfile -t metadata < "$backup/BACKUP-METADATA"
(( ${#metadata[@]} == 9 ))
[[ "${metadata[0]}" == 'schema_version=1' && "${metadata[1]}" == "backup_id=$backup_id" ]]
[[ "${metadata[2]}" == 'purpose=pre-domain-cutover' && "${metadata[4]}" == "site_path=$site" ]]
[[ "${metadata[5]}" == "home_url=$from" && "${metadata[6]}" == "site_url=$from" ]]
[[ "${metadata[7]}" == "release_commit=$release_sha" && "${metadata[8]}" == "release_manifest_sha256=$release_digest" ]]
current="$private/state/current-release"
[[ -f "$current" && ! -L "$current" ]]
mapfile -t current_lines < "$current"
(( ${#current_lines[@]} == 8 ))
[[ "${current_lines[0]}" == 'schema_version=1' ]]
[[ "${current_lines[1]}" == "release_commit=$release_sha" ]]
[[ "${current_lines[2]}" == "release_manifest_sha256=$release_digest" ]]
[[ "$(wp --path="$site" option get home)" == "$from" ]]
[[ "$(wp --path="$site" option get siteurl)" == "$from" ]]
scan_public_dumps

wp --path="$site" search-replace "$from" "$to" --all-tables-with-prefix --precise --dry-run
if [[ "$mode" == 'dry-run' ]]; then
  printf 'cutover_preflight=passed\nbackup_id=%s\nrelease_commit=%s\nrelease_manifest_sha256=%s\n' \
    "$backup_id" "$release_sha" "$release_digest"
  exit 0
fi

mkdir -m 0700 -p "$private/operations"
assert_physical_dir "$private/operations" '/www/goetzgoetz_755/private/operations'
trap cutover_failed ERR
trap 'cutover_interrupted HUP 129' HUP
trap 'cutover_interrupted INT 130' INT
trap 'cutover_interrupted TERM 143' TERM
write_phase preflight_complete
mutation_started=1
write_phase replacing_urls
wp --path="$site" search-replace "$from" "$to" --all-tables-with-prefix --precise
wp --path="$site" option update home "$to"
wp --path="$site" option update siteurl "$to"
seo_first="$(wp --path="$site" goetz-site seo configure --strict --format=json)"
validate_json_status "$seo_first" 'configured,noop'
seo_noop="$(wp --path="$site" goetz-site seo configure --strict --format=json)"
validate_json_status "$seo_noop" 'noop'
wp --path="$site" yoast index --reindex --skip-confirmation
wp --path="$site" rewrite flush --hard
wp --path="$site" cache flush
wp --path="$site" kinsta cache purge --all 2>/dev/null
[[ "$(wp --path="$site" option get home)" == "$to" ]]
[[ "$(wp --path="$site" option get siteurl)" == "$to" ]]
scan_public_dumps
write_phase complete
trap - ERR HUP INT TERM
REMOTE
then
  printf 'Cutover failed or the transport ended unexpectedly. Recovery status is unknown until the durable remote receipt is inspected.\n' >&2
  printf 'Inspect remote receipt: %s/operations/cutover-%s.status\n' "$GOETZ_REMOTE_PRIVATE" "$backup_id" >&2
  printf 'manager_rollback_command=./manager.sh remote:rollback --backup-id=%q --apply\n' "$backup_id" >&2
  exit 1
fi

printf 'cutover_mode=%s\n' "$mode"
printf 'from=%s\n' "$from"
printf 'to=%s\n' "$to"
printf 'backup_id=%s\n' "$backup_id"
if (( apply == 0 )); then
  printf 'manager_apply_command=./manager.sh remote:cutover --from=%q --to=%q --backup-id=%q --apply\n' "$from" "$to" "$backup_id"
fi
printf 'manager_rollback_command=./manager.sh remote:rollback --backup-id=%q --apply\n' "$backup_id"
