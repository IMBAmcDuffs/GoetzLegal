#!/usr/bin/env bash
set -euo pipefail

unset SSH_KEY_PW
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly GOETZ_RELEASE_ROOT="$root/scripts/release"
readonly GOETZ_LOCAL_BACKUP_ROOT="$root/__dev/kinsta-backups"
GOETZ_COMMAND_NAME='remote-backup'
# shellcheck source=scripts/release/common.sh
source "$GOETZ_RELEASE_ROOT/common.sh"

goetz_assert_local_backup_dir() {
  local path="$1" expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" ]] ||
    goetz_fail 'local backup parent is unsafe or redirected'
  [[ "$(readlink -f -- "$path")" == "$expected" ]] ||
    goetz_fail 'local backup parent is unsafe or redirected'
}

goetz_prepare_local_backup_root() {
  local dev_root="$root/__dev"
  goetz_assert_local_backup_dir "$root" "$root"
  if [[ -e "$dev_root" || -L "$dev_root" ]]; then
    goetz_assert_local_backup_dir "$dev_root" "$dev_root"
  else
    mkdir -m 0755 -- "$dev_root"
  fi
  goetz_assert_local_backup_dir "$dev_root" "$dev_root"
  if [[ -e "$GOETZ_LOCAL_BACKUP_ROOT" || -L "$GOETZ_LOCAL_BACKUP_ROOT" ]]; then
    goetz_assert_local_backup_dir "$GOETZ_LOCAL_BACKUP_ROOT" "$GOETZ_LOCAL_BACKUP_ROOT"
  else
    mkdir -m 0700 -- "$GOETZ_LOCAL_BACKUP_ROOT"
  fi
  goetz_assert_local_backup_dir "$GOETZ_LOCAL_BACKUP_ROOT" "$GOETZ_LOCAL_BACKUP_ROOT"
}

backup_id=''
purpose=''
release_dir=''
backup_seen=0
purpose_seen=0
release_seen=0
for argument in "$@"; do
  case "$argument" in
    --backup-id=*)
      (( backup_seen == 0 )) || goetz_fail '--backup-id was supplied more than once'
      backup_id="${argument#*=}"
      backup_seen=1
      ;;
    --purpose=*)
      (( purpose_seen == 0 )) || goetz_fail '--purpose was supplied more than once'
      purpose="${argument#*=}"
      purpose_seen=1
      ;;
    --release-dir=*)
      (( release_seen == 0 )) || goetz_fail '--release-dir was supplied more than once'
      release_dir="${argument#*=}"
      release_seen=1
      ;;
    *) goetz_fail "unknown argument: $argument" ;;
  esac
done
[[ -n "$purpose" ]] || goetz_fail 'usage: remote-backup.sh --purpose=<purpose> [--backup-id=<id>] [--release-dir=<release>]'
(( backup_seen == 0 )) || [[ -n "$backup_id" ]] || goetz_fail '--backup-id cannot be empty'
(( release_seen == 0 )) || [[ -n "$release_dir" ]] || goetz_fail '--release-dir cannot be empty'
goetz_validate_purpose "$purpose"

release_sha='none'
release_digest='none'
if [[ -n "$release_dir" ]]; then
  [[ -d "$release_dir" && ! -L "$release_dir" ]] || goetz_fail 'release directory must be a normal directory'
  goetz_release_identity "$release_dir"
  release_sha="$GOETZ_RELEASE_SHA"
  release_digest="$GOETZ_RELEASE_DIGEST"
elif [[ "$purpose" != 'manual' && "$purpose" != 'automatic-recovery' ]]; then
  goetz_fail "$purpose backups require --release-dir to couple the exact release digest"
fi

if [[ -z "$backup_id" ]]; then
  backup_id="$(date -u '+%Y%m%dT%H%M%SZ')-$purpose"
fi
goetz_validate_backup_id "$backup_id"
goetz_prepare_local_backup_root
goetz_require_kinsta

remote_backup="$GOETZ_REMOTE_PRIVATE/backups/$backup_id"
local_backup="$GOETZ_LOCAL_BACKUP_ROOT/$backup_id"
[[ ! -e "$local_backup" && ! -L "$local_backup" ]] || goetz_fail "local backup already exists: $local_backup"

remote_manifest_hash="$(goetz_ssh bash -s -- \
  "$remote_backup" "$GOETZ_EXPECTED_SITE" "$purpose" "$release_sha" "$release_digest" <<'REMOTE'
# GOETZ_REMOTE_BACKUP_CREATE
set -euo pipefail
umask 077
backup="$1"
site="$2"
purpose="$3"
release_sha="$4"
release_digest="$5"
private='/www/goetzgoetz_755/private'

die() { printf 'remote backup: %s\n' "$1" >&2; exit 1; }
assert_physical_dir() {
  local path="$1"
  local expected="$2"
  [[ "$path" == "$expected" && -d "$path" && ! -L "$path" ]] || die "unsafe directory: $expected"
  [[ "$(readlink -f -- "$path")" == "$expected" ]] || die "redirected directory: $expected"
}
write_phase() {
  local phase="$1"
  local receipt="$private/operations/backup-$backup_id.status"
  local temporary
  [[ ! -L "$receipt" ]]
  temporary="$(mktemp "$private/operations/.backup-$backup_id.status.XXXXXX")"
  printf 'schema_version=1\noperation=backup\nbackup_id=%s\nphase=%s\nupdated_utc=%s\n' \
    "$backup_id" "$phase" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$temporary"
  chmod 0600 "$temporary"
  mv -f -- "$temporary" "$receipt"
}
require_single_site() {
  local state
  state="$(wp --path="$site" eval 'echo is_multisite() ? "yes" : "no";')"
  [[ "$state" == 'no' ]] || die 'multisite is not supported by this release toolchain'
}
assert_archiveable_tree() {
  local path="$1"
  [[ -z "$(find "$path" -xdev ! -type d ! -type f -print -quit)" ]] ||
    die "backup source contains an unsupported non-file entry: $path"
}

[[ "$site" == '/www/goetzgoetz_755/public' ]]
[[ "$backup" == /www/goetzgoetz_755/private/backups/* && "$backup" != '/www/goetzgoetz_755/private/backups/' ]]
backup_id="${backup##*/}"
[[ "$backup_id" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
case "$purpose" in pre-deployment|pre-domain-cutover|manual|automatic-recovery) ;; *) die 'invalid backup purpose' ;; esac
if [[ "$release_sha" == 'none' || "$release_digest" == 'none' ]]; then
  [[ "$release_sha" == 'none' && "$release_digest" == 'none' ]]
else
  [[ "$release_sha" =~ ^[0-9a-f]{40}$ && "$release_digest" =~ ^[0-9a-f]{64}$ ]]
fi
for command_name in wp tar gzip sha256sum find sort readlink flock date awk mktemp; do
  command -v "$command_name" >/dev/null 2>&1 || die "required command unavailable: $command_name"
done
assert_physical_dir "$site" '/www/goetzgoetz_755/public'
assert_physical_dir "$private" '/www/goetzgoetz_755/private'
[[ -f "$site/wp-load.php" && ! -L "$site/wp-load.php" ]] || die 'WordPress bootstrap is missing or redirected'
mkdir -m 0700 -p "$private/backups" "$private/operations" "$private/locks"
assert_physical_dir "$private/backups" '/www/goetzgoetz_755/private/backups'
assert_physical_dir "$private/operations" '/www/goetzgoetz_755/private/operations'
assert_physical_dir "$private/locks" '/www/goetzgoetz_755/private/locks'
lock_file="$private/locks/release-mutation.lock"
[[ ! -L "$lock_file" ]] || die 'mutation lock is a symlink'
exec 9>>"$lock_file"
flock -n 9 || die 'another release mutation is in progress'
[[ ! -e "$backup" && ! -L "$backup" ]] || die 'backup already exists'
require_single_site

home_url="$(wp --path="$site" option get home)"
site_url="$(wp --path="$site" option get siteurl)"
[[ "$home_url" == "$site_url" && "$home_url" == https://* ]] || die 'home/siteurl must be the same HTTPS origin'
if [[ "$purpose" == 'pre-deployment' || "$purpose" == 'pre-domain-cutover' ]]; then
  [[ "$home_url" == 'https://goetzgoetz.kinsta.cloud' ]] || die 'release backups require the exact staging origin'
  [[ "$release_sha" =~ ^[0-9a-f]{40}$ && "$release_digest" =~ ^[0-9a-f]{64}$ ]] || die 'release backup is not digest-coupled'
fi
current="$private/state/current-release"
if [[ -e "$current" || -L "$current" ]]; then
  [[ -f "$current" && ! -L "$current" ]] || die 'current release receipt is not a normal file'
  mapfile -t current_lines < "$current"
  (( ${#current_lines[@]} == 7 || ${#current_lines[@]} == 8 )) ||
    die 'current release receipt schema is invalid'
  [[ "${current_lines[0]}" == 'schema_version=1' ]]
  [[ "${current_lines[1]}" =~ ^release_commit=[0-9a-f]{40}$ ]]
  [[ "${current_lines[2]}" =~ ^release_manifest_sha256=[0-9a-f]{64}$ ]]
  [[ "${current_lines[3]}" =~ ^backup_id=[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
  [[ "${current_lines[4]}" =~ ^deployed_utc=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]]
  [[ "${current_lines[5]}" =~ ^debug_log_inode=(none|[0-9]+)$ ]]
  [[ "${current_lines[6]}" =~ ^debug_log_offset=[0-9]+$ ]]
  if (( ${#current_lines[@]} == 8 )); then
    [[ "${current_lines[7]}" =~ ^debug_log_prefix_sha256=(none|[0-9a-f]{64})$ ]]
  fi
fi
if [[ "$purpose" == 'pre-domain-cutover' ]]; then
  [[ -e "$current" || -L "$current" ]] || die 'current release receipt is missing'
  (( ${#current_lines[@]} == 8 )) || die 'pre-domain-cutover requires the current release receipt schema'
  [[ "${current_lines[1]}" == "release_commit=$release_sha" ]]
  [[ "${current_lines[2]}" == "release_manifest_sha256=$release_digest" ]]
fi

write_phase preflight_complete
mkdir -m 0700 "$backup"
assert_physical_dir "$backup" "$backup"
write_phase snapshot_started

wp --path="$site" db export "$backup/database.sql" --add-drop-table --quiet
[[ -s "$backup/database.sql" ]]
uploads="$site/wp-content/uploads"
if [[ -e "$uploads" || -L "$uploads" ]]; then
  assert_physical_dir "$uploads" '/www/goetzgoetz_755/public/wp-content/uploads'
  assert_archiveable_tree "$uploads"
  tar --hard-dereference -czf "$backup/uploads.tar.gz" -C "$site" 'wp-content/uploads'
else
  tar -czf "$backup/uploads.tar.gz" -T /dev/null
fi

: > "$backup/code-state.tsv"
backup_code_root() {
  local relative_root="$1"
  local archive_name="$2"
  local source="$site/$relative_root"
  case "$relative_root:$archive_name" in
    'wp-content/themes/goetz-legal:code-theme-goetz-legal.tar.gz'|\
    'wp-content/plugins/goetz-site:code-plugin-goetz-site.tar.gz'|\
    'wp-content/plugins/goetz-migration:code-plugin-goetz-migration.tar.gz'|\
    'wp-content/plugins/wordpress-seo:code-plugin-wordpress-seo.tar.gz'|\
    'wp-content/plugins/wpforms-lite:code-plugin-wpforms-lite.tar.gz') ;;
    *) return 90 ;;
  esac
  if [[ -e "$source" || -L "$source" ]]; then
    assert_physical_dir "$source" "$source"
    assert_archiveable_tree "$source"
    tar --hard-dereference -czf "$backup/$archive_name" -C "$site" "$relative_root"
    printf '%s\tpresent\t%s\n' "$relative_root" "$archive_name" >> "$backup/code-state.tsv"
  else
    printf '%s\tabsent\t-\n' "$relative_root" >> "$backup/code-state.tsv"
  fi
}
backup_code_root 'wp-content/themes/goetz-legal' 'code-theme-goetz-legal.tar.gz'
backup_code_root 'wp-content/plugins/goetz-site' 'code-plugin-goetz-site.tar.gz'
backup_code_root 'wp-content/plugins/goetz-migration' 'code-plugin-goetz-migration.tar.gz'
backup_code_root 'wp-content/plugins/wordpress-seo' 'code-plugin-wordpress-seo.tar.gz'
backup_code_root 'wp-content/plugins/wpforms-lite' 'code-plugin-wpforms-lite.tar.gz'

wp --path="$site" theme list --status=active --field=name | LC_ALL=C sort > "$backup/active-theme.txt"
wp --path="$site" plugin list --status=active --field=name | LC_ALL=C sort > "$backup/active-plugins.txt"
wp --path="$site" plugin list --status=must-use --field=name | LC_ALL=C sort > "$backup/must-use-plugins.txt"
printf '%s\n' "$home_url" > "$backup/home-url.txt"
printf '%s\n' "$site_url" > "$backup/site-url.txt"
wp --path="$site" core version > "$backup/wordpress-version.txt"
current="$private/state/current-release"
if [[ -e "$current" || -L "$current" ]]; then
  [[ -f "$current" && ! -L "$current" ]] || die 'current release receipt is not a normal file'
  cp -- "$current" "$backup/previous-current-release"
  printf 'present\tprevious-current-release\n' > "$backup/release-state.tsv"
else
  printf 'absent\t-\n' > "$backup/release-state.tsv"
fi
created_utc="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
cat > "$backup/BACKUP-METADATA" <<METADATA
schema_version=1
backup_id=$backup_id
purpose=$purpose
created_utc=$created_utc
site_path=$site
home_url=$home_url
site_url=$site_url
release_commit=$release_sha
release_manifest_sha256=$release_digest
METADATA

[[ -s "$backup/uploads.tar.gz" && -s "$backup/database.sql" ]]
[[ "$(wc -l < "$backup/code-state.tsv")" -eq 5 ]]
[[ -s "$backup/active-theme.txt" && -e "$backup/active-plugins.txt" && -e "$backup/must-use-plugins.txt" ]]
[[ -s "$backup/home-url.txt" && -s "$backup/site-url.txt" && -s "$backup/BACKUP-METADATA" ]]
(
  cd "$backup"
  find . -maxdepth 1 -type f ! -name SHA256SUMS -printf '%P\0' |
    LC_ALL=C sort -z |
    xargs -0 sha256sum > SHA256SUMS
  chmod 0600 ./*
  sha256sum --check --strict SHA256SUMS >/dev/null
)
chmod 0700 "$backup"
write_phase complete
sha256sum "$backup/SHA256SUMS" | cut -d' ' -f1
REMOTE
)" || goetz_fail 'remote backup creation failed'
[[ "$remote_manifest_hash" =~ ^[0-9a-f]{64}$ ]] || goetz_fail 'remote backup did not return a valid manifest hash'

goetz_prepare_local_backup_root
download_dir="$(mktemp -d "$GOETZ_LOCAL_BACKUP_ROOT/.${backup_id}.download.XXXXXX")"
goetz_assert_local_backup_dir "$download_dir" "$download_dir"
cleanup_download=1
cleanup() {
  if (( cleanup_download == 1 )) && [[ -d "$download_dir" && ! -L "$download_dir" ]] &&
    [[ "$(readlink -f -- "$download_dir")" == "$download_dir" ]]; then
    find "$download_dir" -depth -mindepth 1 -delete
    rmdir "$download_dir"
  fi
}
trap cleanup EXIT
goetz_rsync --archive --protect-args -e "$GOETZ_RSYNC_SHELL" \
  "$GOETZ_REMOTE:$remote_backup/" "$download_dir/"

for required_nonempty in database.sql uploads.tar.gz code-state.tsv active-theme.txt \
  home-url.txt site-url.txt wordpress-version.txt BACKUP-METADATA release-state.tsv SHA256SUMS; do
  [[ -f "$download_dir/$required_nonempty" && ! -L "$download_dir/$required_nonempty" && -s "$download_dir/$required_nonempty" ]] ||
    goetz_fail "downloaded backup is missing or empty: $required_nonempty"
done
for required_file in active-plugins.txt must-use-plugins.txt; do
  [[ -f "$download_dir/$required_file" && ! -L "$download_dir/$required_file" ]] ||
    goetz_fail "downloaded backup is missing: $required_file"
done
[[ "$(wc -l < "$download_dir/code-state.tsv")" -eq 5 ]] || goetz_fail 'downloaded code-state.tsv must contain exactly five runtime roots'
(
  cd "$download_dir"
  sha256sum --check --strict SHA256SUMS >/dev/null
) || goetz_fail 'downloaded backup checksum verification failed'
local_manifest_hash="$(sha256sum "$download_dir/SHA256SUMS" | cut -d' ' -f1)"
[[ "$local_manifest_hash" == "$remote_manifest_hash" ]] || goetz_fail 'local and remote backup manifest hashes differ'
goetz_validate_safe_tar_archive "$download_dir/uploads.tar.gz" 'wp-content/uploads'

GOETZ_BACKUP_PURPOSE=''
GOETZ_BACKUP_RELEASE_SHA=''
GOETZ_BACKUP_RELEASE_DIGEST=''
goetz_validate_packet_metadata "$download_dir/BACKUP-METADATA" "$backup_id"
[[ "$GOETZ_BACKUP_PURPOSE" == "$purpose" && "$GOETZ_BACKUP_RELEASE_SHA" == "$release_sha" && "$GOETZ_BACKUP_RELEASE_DIGEST" == "$release_digest" ]] ||
  goetz_fail 'downloaded metadata does not match the requested backup coupling'

while IFS=$'\t' read -r relative_root state archive_name; do
  case "$relative_root" in
    wp-content/themes/goetz-legal|wp-content/plugins/goetz-site|wp-content/plugins/goetz-migration|\
    wp-content/plugins/wordpress-seo|wp-content/plugins/wpforms-lite) ;;
    *) goetz_fail "backup contains an unexpected code root: $relative_root" ;;
  esac
  case "$state" in
    present)
      [[ "$archive_name" != '-' && -f "$download_dir/$archive_name" && ! -L "$download_dir/$archive_name" && -s "$download_dir/$archive_name" ]] ||
        goetz_fail "present code root lacks an archive: $relative_root"
      goetz_validate_safe_tar_archive "$download_dir/$archive_name" "$relative_root"
      ;;
    absent) [[ "$archive_name" == '-' ]] || goetz_fail "absent code root has an unexpected archive: $relative_root" ;;
    *) goetz_fail "backup contains an invalid code state: $state" ;;
  esac
done < "$download_dir/code-state.tsv"

chmod 0700 "$download_dir"
find "$download_dir" -maxdepth 1 -type f -exec chmod 0600 {} +
cat > "$download_dir/LOCAL-VERIFICATION" <<RECEIPT
schema_version=1
backup_id=$backup_id
remote_path=$remote_backup
manifest_sha256=$local_manifest_hash
purpose=$purpose
release_commit=$release_sha
release_manifest_sha256=$release_digest
RECEIPT
chmod 0600 "$download_dir/LOCAL-VERIFICATION"
goetz_prepare_local_backup_root
[[ ! -e "$local_backup" && ! -L "$local_backup" ]] || goetz_fail "local backup appeared during download: $local_backup"
mv "$download_dir" "$local_backup"
cleanup_download=0

printf 'backup_id=%s\n' "$backup_id"
printf 'purpose=%s\n' "$purpose"
printf 'remote_backup=%s\n' "$remote_backup"
printf 'local_backup=%s\n' "$local_backup"
printf 'manifest_sha256=%s\n' "$local_manifest_hash"
printf 'release_commit=%s\n' "$release_sha"
printf 'release_manifest_sha256=%s\n' "$release_digest"
