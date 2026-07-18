#!/usr/bin/env bash

# Shared local-side release guardrails. Callers must enable `set -euo pipefail`
# before sourcing this file.

readonly GOETZ_EXPECTED_SSH_USER='goetzgoetz'
readonly GOETZ_EXPECTED_SSH_HOST='163.192.209.112'
readonly GOETZ_EXPECTED_SSH_PORT='43854'
readonly GOETZ_EXPECTED_SITE='/www/goetzgoetz_755/public'
readonly GOETZ_REMOTE_PRIVATE='/www/goetzgoetz_755/private'
readonly GOETZ_STAGING_ORIGIN='https://goetzgoetz.kinsta.cloud'
readonly GOETZ_PRODUCTION_ORIGIN='https://goetzlegal.com'

GOETZ_COMMAND_NAME="${GOETZ_COMMAND_NAME:-release}"
GOETZ_EXEC_PATH="${PATH:-/usr/local/bin:/usr/bin:/bin}"
GOETZ_EXEC_HOME="${HOME:-/tmp}"

goetz_fail() {
  printf '%s: %s\n' "$GOETZ_COMMAND_NAME" "$1" >&2
  exit 1
}

goetz_clean_exec() {
  /usr/bin/env -i \
    "HOME=$GOETZ_EXEC_HOME" \
    "PATH=$GOETZ_EXEC_PATH" \
    "SSH_AUTH_SOCK=${SSH_AUTH_SOCK:-}" \
    "$@"
}

goetz_require_kinsta() {
  local required_name
  for required_name in \
    KINSTA_SSH_USER KINSTA_SSH_HOST KINSTA_SSH_PORT KINSTA_SITE_PATH \
    KINSTA_KNOWN_HOSTS_FILE SSH_AUTH_SOCK; do
    [[ -n "${!required_name:-}" ]] || goetz_fail "required configuration is missing: $required_name"
  done
  [[ "$KINSTA_SSH_USER" == "$GOETZ_EXPECTED_SSH_USER" ]] || goetz_fail 'refusing an unexpected Kinsta SSH user'
  [[ "$KINSTA_SSH_HOST" == "$GOETZ_EXPECTED_SSH_HOST" ]] || goetz_fail 'refusing an unexpected Kinsta SSH host'
  [[ "$KINSTA_SSH_PORT" == "$GOETZ_EXPECTED_SSH_PORT" ]] || goetz_fail 'refusing an unexpected Kinsta SSH port'
  [[ "$KINSTA_SITE_PATH" == "$GOETZ_EXPECTED_SITE" ]] || goetz_fail 'refusing an unexpected Kinsta WordPress root'
  [[ "$KINSTA_KNOWN_HOSTS_FILE" == /* && "$KINSTA_KNOWN_HOSTS_FILE" != *[[:space:]]* ]] ||
    goetz_fail 'known-host file must be an absolute path without whitespace'
  [[ -f "$KINSTA_KNOWN_HOSTS_FILE" && ! -L "$KINSTA_KNOWN_HOSTS_FILE" && -s "$KINSTA_KNOWN_HOSTS_FILE" ]] ||
    goetz_fail 'pinned known-host file must be a nonempty regular file, not a symlink'
  grep -Fq "[$GOETZ_EXPECTED_SSH_HOST]:$GOETZ_EXPECTED_SSH_PORT " "$KINSTA_KNOWN_HOSTS_FILE" ||
    goetz_fail 'pinned known-host file does not contain the fixed Kinsta endpoint'
  [[ -e "$SSH_AUTH_SOCK" ]] || goetz_fail 'SSH_AUTH_SOCK does not exist'
  goetz_clean_exec ssh-add -l >/dev/null 2>&1 || goetz_fail 'the isolated SSH agent has no unlocked identity'

  GOETZ_REMOTE="$GOETZ_EXPECTED_SSH_USER@$GOETZ_EXPECTED_SSH_HOST"
  GOETZ_SSH_OPTIONS=(
    -F /dev/null
    -T
    -o BatchMode=yes
    -o StrictHostKeyChecking=yes
    -o "UserKnownHostsFile=$KINSTA_KNOWN_HOSTS_FILE"
    -o GlobalKnownHostsFile=/dev/null
    -o IdentityFile=none
    -o IdentitiesOnly=no
    -o ForwardAgent=no
    -o ClearAllForwardings=yes
    -o PermitLocalCommand=no
    -o RequestTTY=no
    -o ProxyCommand=none
    -o ProxyJump=none
    -o ConnectTimeout=15
    -o ConnectionAttempts=1
    -o ServerAliveInterval=15
    -o ServerAliveCountMax=2
    -p "$GOETZ_EXPECTED_SSH_PORT"
  )
  printf -v GOETZ_RSYNC_SHELL \
    'ssh -F /dev/null -T -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%s -o GlobalKnownHostsFile=/dev/null -o IdentityFile=none -o IdentitiesOnly=no -o ForwardAgent=no -o ClearAllForwardings=yes -o PermitLocalCommand=no -o RequestTTY=no -o ProxyCommand=none -o ProxyJump=none -o ConnectTimeout=15 -o ConnectionAttempts=1 -o ServerAliveInterval=15 -o ServerAliveCountMax=2 -p %s' \
    "$KINSTA_KNOWN_HOSTS_FILE" "$GOETZ_EXPECTED_SSH_PORT"
}

goetz_ssh() {
  goetz_clean_exec ssh "${GOETZ_SSH_OPTIONS[@]}" "$GOETZ_REMOTE" "$@"
}

goetz_rsync() {
  goetz_clean_exec rsync "$@"
}

goetz_validate_backup_id() {
  [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]] || goetz_fail 'backup ID contains unsafe characters'
}

goetz_validate_purpose() {
  case "$1" in
    pre-deployment|pre-domain-cutover|manual|automatic-recovery) ;;
    *) goetz_fail 'backup purpose must be pre-deployment, pre-domain-cutover, manual, or automatic-recovery' ;;
  esac
}

goetz_release_payload_path() {
  local release_dir="$1"
  if [[ -f "$release_dir/release.json" ]]; then
    printf '%s\n' "$release_dir"
  elif [[ -f "$release_dir/payload/release.json" ]]; then
    printf '%s\n' "$release_dir/payload"
  else
    return 1
  fi
}

goetz_release_identity() {
  local release_dir="$1"
  local payload
  payload="$(goetz_release_payload_path "$release_dir")" || goetz_fail 'release payload metadata is missing'
  "$GOETZ_RELEASE_ROOT/verify.sh" "$payload" >/dev/null
  GOETZ_RELEASE_PAYLOAD="$payload"
  GOETZ_RELEASE_SHA="$(node -e 'const fs=require("fs");const d=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(d.commit)' "$payload/release.json")"
  GOETZ_RELEASE_DIGEST="$(sha256sum "$payload/RELEASE-MANIFEST.sha256" | cut -d' ' -f1)"
  [[ "$GOETZ_RELEASE_SHA" =~ ^[0-9a-f]{40}$ && "$GOETZ_RELEASE_DIGEST" =~ ^[0-9a-f]{64}$ ]] ||
    goetz_fail 'release identity is invalid'
}

goetz_validate_packet_metadata() {
  local metadata="$1"
  local expected_id="$2"
  local -a lines=()
  [[ -f "$metadata" && ! -L "$metadata" ]] || goetz_fail 'backup metadata is missing or is a symlink'
  mapfile -t lines < "$metadata"
  (( ${#lines[@]} == 9 )) || goetz_fail 'backup metadata must contain exactly nine fields'
  [[ "${lines[0]}" == 'schema_version=1' ]] || goetz_fail 'backup metadata schema is invalid'
  [[ "${lines[1]}" == "backup_id=$expected_id" ]] || goetz_fail 'backup metadata ID does not match'
  GOETZ_BACKUP_PURPOSE="${lines[2]#purpose=}"
  [[ "${lines[2]}" == "purpose=$GOETZ_BACKUP_PURPOSE" ]] || goetz_fail 'backup purpose field is malformed'
  goetz_validate_purpose "$GOETZ_BACKUP_PURPOSE"
  GOETZ_BACKUP_CREATED_UTC="${lines[3]#created_utc=}"
  [[ "${lines[3]}" == "created_utc=$GOETZ_BACKUP_CREATED_UTC" && "$GOETZ_BACKUP_CREATED_UTC" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] ||
    goetz_fail 'backup creation time is malformed'
  [[ "${lines[4]}" == "site_path=$GOETZ_EXPECTED_SITE" ]] || goetz_fail 'backup site path is unexpected'
  GOETZ_BACKUP_HOME="${lines[5]#home_url=}"
  GOETZ_BACKUP_SITEURL="${lines[6]#site_url=}"
  [[ "${lines[5]}" == "home_url=$GOETZ_BACKUP_HOME" && "${lines[6]}" == "site_url=$GOETZ_BACKUP_SITEURL" ]] ||
    goetz_fail 'backup URL metadata is malformed'
  GOETZ_BACKUP_RELEASE_SHA="${lines[7]#release_commit=}"
  GOETZ_BACKUP_RELEASE_DIGEST="${lines[8]#release_manifest_sha256=}"
  [[ "${lines[7]}" == "release_commit=$GOETZ_BACKUP_RELEASE_SHA" && "${lines[8]}" == "release_manifest_sha256=$GOETZ_BACKUP_RELEASE_DIGEST" ]] ||
    goetz_fail 'backup release coupling fields are malformed'
  [[ "$GOETZ_BACKUP_RELEASE_SHA" == 'none' || "$GOETZ_BACKUP_RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]] ||
    goetz_fail 'backup release commit is malformed'
  [[ "$GOETZ_BACKUP_RELEASE_DIGEST" == 'none' || "$GOETZ_BACKUP_RELEASE_DIGEST" =~ ^[0-9a-f]{64}$ ]] ||
    goetz_fail 'backup release digest is malformed'
  if [[ "$GOETZ_BACKUP_RELEASE_SHA" == 'none' || "$GOETZ_BACKUP_RELEASE_DIGEST" == 'none' ]]; then
    [[ "$GOETZ_BACKUP_RELEASE_SHA" == 'none' && "$GOETZ_BACKUP_RELEASE_DIGEST" == 'none' ]] ||
      goetz_fail 'backup release coupling must be wholly present or wholly absent'
  fi
}

goetz_validate_safe_tar_archive() {
  local archive="$1"
  local prefix="$2"
  local entry listing
  case "$prefix" in
    wp-content/uploads|wp-content/themes/goetz-legal|wp-content/plugins/goetz-site|\
    wp-content/plugins/goetz-migration|wp-content/plugins/wordpress-seo|wp-content/plugins/wpforms-lite) ;;
    *) goetz_fail 'backup archive prefix is not allowlisted' ;;
  esac
  [[ -f "$archive" && ! -L "$archive" && -s "$archive" ]] ||
    goetz_fail "backup archive is missing, empty, or redirected: $archive"
  gzip -t "$archive" || goetz_fail "backup archive is not valid gzip data: $archive"
  tar -tzf "$archive" >/dev/null || goetz_fail "backup archive cannot be listed: $archive"
  while IFS= read -r entry; do
    [[ -n "$entry" && "$entry" != /* && "$entry" != *'/../'* && "$entry" != '../'* && "$entry" != *'/..' ]] ||
      goetz_fail "backup archive contains an unsafe path: $archive"
    [[ "$entry" == "$prefix" || "$entry" == "$prefix/"* ]] ||
      goetz_fail "backup archive escaped its allowlisted prefix: $archive"
  done < <(tar -tzf "$archive")
  while IFS= read -r listing; do
    case "${listing:0:1}" in
      -|d) ;;
      *) goetz_fail "backup archive contains an unsupported entry type: $archive" ;;
    esac
  done < <(tar --numeric-owner --full-time --quoting-style=escape -tvzf "$archive")
}

goetz_verify_local_backup() {
  local backup_id="$1"
  local expected_purpose="${2:-}"
  local local_backup="$GOETZ_LOCAL_BACKUP_ROOT/$backup_id"
  local receipt="$local_backup/LOCAL-VERIFICATION"
  local -a lines=()
  local expected_remote="$GOETZ_REMOTE_PRIVATE/backups/$backup_id"
  local receipt_hash
  local relative_root state archive_name expected_archive
  local -A seen_code_roots=()

  [[ -d "$local_backup" && ! -L "$local_backup" ]] || goetz_fail 'backup has not been downloaded to a normal local directory'
  [[ "$(readlink -f -- "$local_backup")" == "$(readlink -m -- "$GOETZ_LOCAL_BACKUP_ROOT/$backup_id")" ]] ||
    goetz_fail 'local backup path does not resolve to its expected physical path'
  [[ -f "$receipt" && ! -L "$receipt" ]] || goetz_fail 'local backup verification receipt is missing or is a symlink'
  mapfile -t lines < "$receipt"
  (( ${#lines[@]} == 7 )) || goetz_fail 'local backup receipt must contain exactly seven fields'
  [[ "${lines[0]}" == 'schema_version=1' ]] || goetz_fail 'local backup receipt schema is invalid'
  [[ "${lines[1]}" == "backup_id=$backup_id" ]] || goetz_fail 'local backup receipt ID does not match'
  [[ "${lines[2]}" == "remote_path=$expected_remote" ]] || goetz_fail 'local backup receipt remote path does not match'
  receipt_hash="${lines[3]#manifest_sha256=}"
  [[ "${lines[3]}" == "manifest_sha256=$receipt_hash" && "$receipt_hash" =~ ^[0-9a-f]{64}$ ]] ||
    goetz_fail 'local backup receipt digest is invalid'
  [[ "${lines[4]}" == purpose=* && "${lines[5]}" == release_commit=* && "${lines[6]}" == release_manifest_sha256=* ]] ||
    goetz_fail 'local backup receipt coupling fields are malformed'
  [[ -f "$local_backup/SHA256SUMS" && ! -L "$local_backup/SHA256SUMS" ]] || goetz_fail 'local backup checksum manifest is missing'
  [[ "$(find "$local_backup" -maxdepth 1 -type f ! -name SHA256SUMS ! -name LOCAL-VERIFICATION -printf '%f\n' | LC_ALL=C sort)" == \
      "$(awk '{print $2}' "$local_backup/SHA256SUMS" | LC_ALL=C sort)" ]] ||
    goetz_fail 'local backup contains missing or unmanifested packet files'
  (
    cd "$local_backup"
    sha256sum --check --strict SHA256SUMS >/dev/null
  ) || goetz_fail 'local backup packet no longer matches its checksums'
  [[ "$(sha256sum "$local_backup/SHA256SUMS" | cut -d' ' -f1)" == "$receipt_hash" ]] ||
    goetz_fail 'local backup manifest digest changed after verification'
  goetz_validate_safe_tar_archive "$local_backup/uploads.tar.gz" 'wp-content/uploads'
  while IFS=$'\t' read -r relative_root state archive_name; do
    case "$relative_root" in
      wp-content/themes/goetz-legal) expected_archive='code-theme-goetz-legal.tar.gz' ;;
      wp-content/plugins/goetz-site) expected_archive='code-plugin-goetz-site.tar.gz' ;;
      wp-content/plugins/goetz-migration) expected_archive='code-plugin-goetz-migration.tar.gz' ;;
      wp-content/plugins/wordpress-seo) expected_archive='code-plugin-wordpress-seo.tar.gz' ;;
      wp-content/plugins/wpforms-lite) expected_archive='code-plugin-wpforms-lite.tar.gz' ;;
      *) goetz_fail "backup contains an unexpected code root: $relative_root" ;;
    esac
    [[ ! -v "seen_code_roots[$relative_root]" ]] ||
      goetz_fail "backup contains duplicate code state: $relative_root"
    seen_code_roots["$relative_root"]=1
    case "$state" in
      present)
        [[ "$archive_name" == "$expected_archive" ]] ||
          goetz_fail "backup code archive name is invalid: $relative_root"
        goetz_validate_safe_tar_archive "$local_backup/$archive_name" "$relative_root"
        ;;
      absent)
        [[ "$archive_name" == '-' ]] || goetz_fail "absent code root has an unexpected archive: $relative_root"
        ;;
      *) goetz_fail "backup contains an invalid code state: $state" ;;
    esac
  done < "$local_backup/code-state.tsv"
  (( ${#seen_code_roots[@]} == 5 )) || goetz_fail 'backup code state does not cover all five runtime roots'
  goetz_validate_packet_metadata "$local_backup/BACKUP-METADATA" "$backup_id"
  [[ "${lines[4]}" == "purpose=$GOETZ_BACKUP_PURPOSE" && "${lines[5]}" == "release_commit=$GOETZ_BACKUP_RELEASE_SHA" && "${lines[6]}" == "release_manifest_sha256=$GOETZ_BACKUP_RELEASE_DIGEST" ]] ||
    goetz_fail 'local backup receipt is not coupled to packet metadata'
  [[ -z "$expected_purpose" || "$GOETZ_BACKUP_PURPOSE" == "$expected_purpose" ]] ||
    goetz_fail "backup purpose must be $expected_purpose"

  GOETZ_LOCAL_BACKUP="$local_backup"
  GOETZ_REMOTE_BACKUP="$expected_remote"
  GOETZ_BACKUP_DIGEST="$receipt_hash"
}

goetz_remote_verify_backup_digest() {
  local output
  output="$(goetz_ssh bash -s -- "$GOETZ_REMOTE_BACKUP" "$GOETZ_BACKUP_DIGEST" <<'REMOTE'
# GOETZ_REMOTE_BACKUP_VERIFY
set -euo pipefail
backup="$1"
expected="$2"
[[ "$backup" == /www/goetzgoetz_755/private/backups/* && "$backup" != '/www/goetzgoetz_755/private/backups/' ]]
[[ -d "$backup" && ! -L "$backup" ]]
[[ "$(readlink -f -- "$backup")" == "$backup" ]]
[[ -f "$backup/SHA256SUMS" && ! -L "$backup/SHA256SUMS" ]]
[[ "$(find "$backup" -maxdepth 1 -type f ! -name SHA256SUMS -printf '%f\n' | LC_ALL=C sort)" == \
    "$(awk '{print $2}' "$backup/SHA256SUMS" | LC_ALL=C sort)" ]]
[[ "$(sha256sum "$backup/SHA256SUMS" | cut -d' ' -f1)" == "$expected" ]]
(
  cd "$backup"
  sha256sum --check --strict SHA256SUMS >/dev/null
)
printf '%s\n' "$expected"
REMOTE
)" || goetz_fail 'remote backup verification failed'
  [[ "$output" == "$GOETZ_BACKUP_DIGEST" ]] || goetz_fail 'remote backup digest does not match the locally verified packet'
}
