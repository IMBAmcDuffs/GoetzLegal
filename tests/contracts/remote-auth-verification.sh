#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() {
  printf 'remote-auth-verification: %s\n' "$1" >&2
  exit 1
}

readonly_spec='tests/e2e/production-read-only.spec.ts'
[[ -f "$readonly_spec" ]] || fail 'the dedicated production read-only Playwright spec is missing'
readonly_homepage_title='production homepage read-only verifies the locked tree and editable controls without saving'
readonly_settings_title='Site Settings read-only renders escaped values without submitting the form'
grep -Fq "test('$readonly_homepage_title'" "$readonly_spec" ||
  fail 'the production homepage read-only test title is missing'
grep -Fq "test('$readonly_settings_title'" "$readonly_spec" ||
  fail 'the Site Settings read-only test title is missing'
[[ "$(grep -Ec '^test\(' "$readonly_spec")" == '2' ]] ||
  fail 'the dedicated production read-only spec must contain exactly the two approved tests'
grep -Fq "'production-read-only.spec.ts'" tests/e2e/playwright.config.ts ||
  fail 'the authenticated Playwright config does not include the read-only production spec'
grep -Fq 'bash tests/contracts/remote-auth-verification.sh' manager.sh ||
  fail 'the complete local gate does not run the remote-auth verification contract'

fixture="$(mktemp -d "${TMPDIR:-/tmp}/goetz-remote-auth-contract.XXXXXX")"
cleanup() {
  rm -rf -- "$fixture"
}
trap cleanup EXIT

mkdir -p "$fixture/bin" "$fixture/home" "$fixture/scripts/release" "$fixture/records"
cp manager.sh "$fixture/manager.sh"
cp scripts/release/common.sh "$fixture/scripts/release/common.sh"
chmod 0700 "$fixture/manager.sh" "$fixture/home"

known_hosts="$fixture/known-hosts"
auth_socket="$fixture/ssh-agent.sock"
printf '[163.192.209.112]:43854 ssh-ed25519 CONTRACT-ONLY\n' > "$known_hosts"
printf 'contract socket placeholder\n' > "$auth_socket"

cat > "$fixture/.env" <<ENV
COMPOSE_PROJECT_NAME=goetz-remote-auth-contract
WP_PORT=18080
WP_URL=http://localhost:18080
WP_ADMIN_USER=local-admin
WP_ADMIN_PASSWORD=local-password
MYSQL_DATABASE=wordpress
MYSQL_USER=wordpress
MYSQL_PASSWORD=wordpress
MYSQL_ROOT_PASSWORD=wordpress
KINSTA_SSH_USER=goetzgoetz
KINSTA_SSH_HOST=163.192.209.112
KINSTA_SSH_PORT=43854
KINSTA_SITE_PATH=/www/goetzgoetz_755/public
KINSTA_KNOWN_HOSTS_FILE=$known_hosts
ENV
chmod 0600 "$fixture/.env"

cat > "$fixture/bin/ssh-add" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == '-l' ]]
printf '256 SHA256:contract remote-auth-contract (ED25519)\n'
SH
chmod 0700 "$fixture/bin/ssh-add"

cat > "$fixture/bin/ssh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
fixture_root="$(cd "${0%/*}/.." && pwd)"
records="$fixture_root/records"
count_file="$records/ssh-count"
count=0
if [[ -s "$count_file" ]]; then
  read -r count < "$count_file"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
{
  printf 'argv:'
  printf ' <%s>' "$@"
  printf '\n'
} > "$records/ssh.$count.argv"
/usr/bin/env | /usr/bin/sort > "$records/ssh.$count.env"

operation=''
username=''
previous=''
is_cleanup=0
for argument in "$@"; do
  [[ "$argument" == 'bash' ]] && is_cleanup=1
  if [[ "$previous" == 'create' || "$previous" == 'get' || "$previous" == 'delete' ]]; then
    operation="$previous"
    username="$argument"
    break
  fi
  previous="$argument"
done

if (( is_cleanup == 1 )); then
  cleanup_script="$(cat)"
  grep -Fq 'GOETZ_REMOTE_VERIFICATION_USER_CLEANUP' <<< "$cleanup_script"
  grep -Fq 'user list' <<< "$cleanup_script"
  grep -Fq -- '--login="$username" --format=count' <<< "$cleanup_script"
  grep -Fq '[[ "$remaining_users" == '\''0'\'' ]]' <<< "$cleanup_script"
  if [[ -e "$fixture_root/fail-cleanup" ]]; then
    exit 61
  fi
  username="${!#}"
  printf '%s\n' "$cleanup_script" > "$records/ssh.cleanup.stdin"
  printf '%s\n' "$username" >> "$records/deleted-users"
  rm -f -- "$records/remote-user"
  exit 0
fi

case "$operation" in
  create)
    IFS= read -r password
    printf '%s\n' "$password" > "$records/ssh.create.stdin"
    printf '%s\n' "$username" > "$records/created-username"
    printf '%s\n' "$username" > "$records/remote-user"
    printf '741\n'
    ;;
  get)
    if [[ -s "$records/remote-user" ]] && [[ "$(cat "$records/remote-user")" == "$username" ]]; then
      printf '741\n'
      exit 0
    fi
    exit 1
    ;;
  delete)
    printf '%s\n' "$username" >> "$records/deleted-users"
    rm -f -- "$records/remote-user"
    printf 'Success: Removed user.\n'
    ;;
  *)
    printf 'unexpected fake SSH operation\n' >&2
    exit 97
    ;;
esac
SH
chmod 0700 "$fixture/bin/ssh"

cat > "$fixture/bin/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
fixture_root="$(cd "${0%/*}/.." && pwd)"
records="$fixture_root/records"
count_file="$records/docker-count"
count=0
if [[ -s "$count_file" ]]; then
  read -r count < "$count_file"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
{
  printf 'argv:'
  printf ' <%s>' "$@"
  printf '\n'
} > "$records/docker.$count.argv"
/usr/bin/env | /usr/bin/sort > "$records/docker.$count.env"

[[ "${1:-}" == 'version' ]] && exit 0

arguments=("$@")
service_index=-1
for index in "${!arguments[@]}"; do
  case "${arguments[$index]}" in
    playwright|playwright-local|playwright-auth|playwright-auth-local)
      service_index="$index"
      break
      ;;
  esac
done
(( service_index >= 0 )) || exit 0
container_command=("${arguments[@]:$((service_index + 1))}")
(( ${#container_command[@]} > 0 )) || exit 0
exec "${container_command[@]}"
SH
chmod 0700 "$fixture/bin/docker"

cat > "$fixture/bin/npm" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
fixture_root="$(cd "${0%/*}/.." && pwd)"
records="$fixture_root/records"

[[ "${1:-}" == 'ci' ]] && exit 0
[[ "${1:-}" == 'run' && "${2:-}" == 'test:auth' ]] || {
  printf 'unexpected fake npm invocation\n' >&2
  exit 96
}
[[ -n "${GOETZ_E2E_USER:-}" && -n "${GOETZ_E2E_PASSWORD:-}" ]] || {
  printf 'the real wrapper did not export both credentials\n' >&2
  exit 95
}
{
  printf 'argv:'
  printf ' <%s>' "$@"
  printf '\n'
} > "$records/npm.auth.argv"
{
  printf 'GOETZ_E2E_USER_SET=yes\n'
  printf 'GOETZ_E2E_PASSWORD_SET=yes\n'
} > "$records/npm.auth.env"
printf '%s\n' "$GOETZ_E2E_USER" > "$records/docker.auth.username"
printf '%s\n' "$GOETZ_E2E_PASSWORD" > "$records/docker.auth.password"
mkdir -p "$fixture_root/__dev/playwright/auth-state"
printf '{"contract":"state"}\n' > "$fixture_root/__dev/playwright/auth-state/auth-state.json"

if [[ -e "$fixture_root/fail-auth" ]]; then
  exit 9
fi
if [[ -e "$fixture_root/block-auth" ]]; then
  touch "$fixture_root/auth-started"
  trap 'exit 143' HUP INT TERM
  while :; do
    /usr/bin/sleep 1
  done
fi
SH
chmod 0700 "$fixture/bin/npm"

reset_records() {
  rm -f -- \
    "$fixture/records"/* \
    "$fixture/fail-auth" \
    "$fixture/fail-cleanup" \
    "$fixture/block-auth" \
    "$fixture/auth-started"
}

run_remote_auth_args() {
  /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    SSH_AUTH_SOCK="$auth_socket" \
    GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
    GOETZ_EXPECT_ORIGIN=https://goetzgoetz.kinsta.cloud \
    GOETZ_E2E_ALLOW_REMOTE=1 \
    /bin/bash "$fixture/manager.sh" test:e2e:auth "$@"
}

run_remote_auth() {
  run_remote_auth_args production-read-only.spec.ts
}

run_remote_auth_with_caller_credentials() {
  /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    SSH_AUTH_SOCK="$auth_socket" \
    GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
    GOETZ_EXPECT_ORIGIN=https://goetzgoetz.kinsta.cloud \
    GOETZ_E2E_ALLOW_REMOTE=1 \
    GOETZ_E2E_USER=caller-contract-user \
    GOETZ_E2E_PASSWORD=caller-contract-password \
    /bin/bash "$fixture/manager.sh" test:e2e:auth settings.spec.ts
}

reset_records
for rejected_args in \
  '' \
  'settings.spec.ts' \
  'production-read-only.spec.ts --grep homepage' \
  'production-read-only.spec.ts --workers=2'; do
  read -r -a rejected_argv <<< "$rejected_args"
  rejected_output="$fixture/rejected-$RANDOM-output"
  if run_remote_auth_args "${rejected_argv[@]}" > "$rejected_output" 2>&1; then
    fail 'an unapproved ephemeral remote Playwright selector was accepted'
  fi
  grep -Fq \
    'Ephemeral remote verification requires exactly: production-read-only.spec.ts' \
    "$rejected_output" ||
    fail 'an unapproved ephemeral selector did not return the fail-closed diagnostic'
  [[ ! -e "$fixture/records/created-username" ]] ||
    fail 'an unapproved ephemeral selector created a temporary remote user'
  [[ ! -e "$fixture/records/ssh.create.stdin" ]] ||
    fail 'an unapproved ephemeral selector reached remote WP-CLI user creation'
  reset_records
done

assert_rejects_symlinked_playwright_path() {
  local relative_path="$1"
  local safe_label
  local path="$fixture/$relative_path"
  local target="$fixture/symlink-target-${relative_path//\//-}"
  local output="$fixture/symlink-${relative_path//\//-}-output"
  safe_label="$(basename "$relative_path")"

  mkdir -p "$(dirname "$path")" "$target"
  ln -s "$target" "$path"
  if run_remote_auth > "$output" 2>&1; then
    fail "a symlinked Playwright $safe_label path was accepted"
  fi
  grep -Fq 'Refusing an unsafe Playwright directory path:' "$output" ||
    fail "a symlinked Playwright $safe_label path did not return the fail-closed diagnostic"
  [[ ! -e "$fixture/records/created-username" ]] ||
    fail "a symlinked Playwright $safe_label path created a temporary remote user"
  [[ ! -e "$fixture/records/ssh.create.stdin" ]] ||
    fail "a symlinked Playwright $safe_label path reached remote WP-CLI user creation"

  unlink -- "$path"
  rm -rf -- "$target"
  reset_records
}

for symlinked_path in \
  __dev/playwright \
  __dev/playwright/auth-state \
  __dev/playwright/auth-node-modules \
  __dev/playwright/public-node-modules \
  artifacts/playwright \
  artifacts/playwright/auth \
  artifacts/playwright/public; do
  assert_rejects_symlinked_playwright_path "$symlinked_path"
done

caller_output="$fixture/caller-credentials-output"
if ! run_remote_auth_with_caller_credentials > "$caller_output" 2>&1; then
  fail 'the explicit caller credential compatibility path failed unexpectedly'
fi
[[ ! -e "$fixture/records/created-username" && ! -e "$fixture/records/ssh.create.stdin" ]] ||
  fail 'the explicit caller credential path created an ephemeral remote user'
grep -Fq '<run> <test:auth> <--> <settings.spec.ts>' "$fixture/records/npm.auth.argv" ||
  fail 'the explicit caller credential path no longer accepts a focused authenticated spec'
[[ "$(cat "$fixture/records/docker.auth.username")" == 'caller-contract-user' ]] ||
  fail 'the explicit caller username did not reach the authenticated test process'
[[ "$(cat "$fixture/records/docker.auth.password")" == 'caller-contract-password' ]] ||
  fail 'the explicit caller password did not reach the authenticated test process'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state survived the caller credential compatibility run'
reset_records

success_output="$fixture/success-output"
if ! run_remote_auth > "$success_output" 2>&1; then
  if grep -Fq 'Remote authenticated tests require explicit caller credentials.' "$success_output"; then
    fail 'manager still requires caller credentials instead of creating a temporary remote administrator'
  fi
  sed -E 's/[a-f0-9]{64}/[redacted-credential]/g' "$success_output" >&2
  fail 'the fake remote authenticated acceptance run failed unexpectedly'
fi

[[ -s "$fixture/records/ssh.create.stdin" ]] ||
  fail 'WP-CLI did not receive the generated password on stdin'
generated_password="$(cat "$fixture/records/ssh.create.stdin")"
generated_username="$(cat "$fixture/records/created-username")"
[[ "$generated_username" =~ ^goetz_verify_[a-f0-9]{16}$ ]] ||
  fail 'the temporary verification username is not uniquely random'
[[ "$generated_password" =~ ^[a-f0-9]{64}$ ]] ||
  fail 'the temporary verification password is not a 256-bit random value'

grep -Fq '<--prompt=user_pass>' "$fixture/records"/ssh.*.argv ||
  fail 'remote WP-CLI user creation did not use --prompt=user_pass'
grep -Fq '<--role=administrator>' "$fixture/records"/ssh.*.argv ||
  fail 'the temporary remote user was not created as an administrator'
[[ "$(cat "$fixture/records/docker.auth.username")" == "$generated_username" ]] ||
  fail 'the generated username was not sent directly to the Playwright process'
[[ "$(cat "$fixture/records/docker.auth.password")" == "$generated_password" ]] ||
  fail 'the generated password was not sent directly to the Playwright process'
[[ -s "$fixture/records/npm.auth.argv" && -s "$fixture/records/npm.auth.env" ]] ||
  fail 'the fake container did not execute the real credential stdin/export wrapper through npm'
grep -Fq '<run> <test:auth> <--> <production-read-only.spec.ts>' \
  "$fixture/records/npm.auth.argv" ||
  fail 'the real container wrapper did not pin npm to the approved read-only selector'
[[ ! -e "$fixture/records/remote-user" ]] ||
  fail 'the temporary remote verification user survived a successful run'
grep -Fqx "$generated_username" "$fixture/records/deleted-users" ||
  fail 'the temporary remote verification user was not deleted after success'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state survived the successful remote run'

for evidence in \
  "$success_output" \
  "$fixture/records"/*.argv \
  "$fixture/records"/*.env; do
  ! grep -Fq "$generated_password" "$evidence" ||
    fail 'the generated password leaked outside the two stdin-only credential channels'
done

reset_records
touch "$fixture/fail-auth"
failure_output="$fixture/failure-output"
if run_remote_auth > "$failure_output" 2>&1; then
  fail 'the fake Playwright failure unexpectedly succeeded'
fi
failed_username="$(cat "$fixture/records/created-username")"
[[ ! -e "$fixture/records/remote-user" ]] ||
  fail 'the temporary remote verification user survived a Playwright failure'
grep -Fqx "$failed_username" "$fixture/records/deleted-users" ||
  fail 'the failure trap did not delete the temporary remote verification user'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state survived the failed remote run'

reset_records
touch "$fixture/fail-auth" "$fixture/fail-cleanup"
cleanup_failure_output="$fixture/cleanup-failure-output"
set +e
run_remote_auth > "$cleanup_failure_output" 2>&1
cleanup_failure_status=$?
set -e
(( cleanup_failure_status == 70 )) ||
  fail 'remote administrator cleanup failure did not return dedicated status 70'
grep -Fqx \
  'CRITICAL: temporary remote verification administrator cleanup failed; follow the emergency cleanup runbook immediately.' \
  "$cleanup_failure_output" ||
  fail 'remote administrator cleanup failure did not emit the credential-free critical message'
cleanup_failure_password="$(cat "$fixture/records/ssh.create.stdin")"
cleanup_failure_username="$(cat "$fixture/records/created-username")"
! grep -Fq "$cleanup_failure_password" "$cleanup_failure_output" ||
  fail 'cleanup failure output disclosed the temporary password'
! grep -Fq "$cleanup_failure_username" "$cleanup_failure_output" ||
  fail 'cleanup failure output disclosed the temporary username'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state survived remote administrator cleanup failure'

reset_records
touch "$fixture/block-auth"
interrupt_output="$fixture/interrupt-output"
/usr/bin/setsid /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  SSH_AUTH_SOCK="$auth_socket" \
  GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
  GOETZ_EXPECT_ORIGIN=https://goetzgoetz.kinsta.cloud \
  GOETZ_E2E_ALLOW_REMOTE=1 \
  /bin/bash "$fixture/manager.sh" test:e2e:auth \
    production-read-only.spec.ts \
    > "$interrupt_output" 2>&1 &
interrupt_pid=$!
for _ in $(seq 1 100); do
  [[ -e "$fixture/auth-started" ]] && break
  /usr/bin/sleep 0.05
done
[[ -e "$fixture/auth-started" ]] || fail 'the fake interrupted Playwright run never started'
/bin/kill -TERM -- "-$interrupt_pid"
for _ in $(seq 1 100); do
  ! kill -0 "$interrupt_pid" 2>/dev/null && break
  interrupt_state="$(/bin/ps -o stat= -p "$interrupt_pid" 2>/dev/null || true)"
  [[ "$interrupt_state" == Z* ]] && break
  /usr/bin/sleep 0.05
done
interrupt_state="$(/bin/ps -o stat= -p "$interrupt_pid" 2>/dev/null || true)"
if [[ -n "$interrupt_state" && "$interrupt_state" != Z* ]]; then
  /bin/ps -o pid,ppid,pgid,stat,args --forest -g "$interrupt_pid" >&2 || true
  /bin/kill -KILL -- "-$interrupt_pid" 2>/dev/null || true
  wait "$interrupt_pid" 2>/dev/null || true
  fail 'the interrupted manager did not finish its cleanup path'
fi
set +e
wait "$interrupt_pid"
interrupt_status=$?
set -e
rm -f -- "$fixture/block-auth"
(( interrupt_status != 0 )) || fail 'the interrupted remote verification run returned success'
interrupted_username="$(cat "$fixture/records/created-username")"
if [[ -e "$fixture/records/remote-user" ]]; then
  printf 'remote-auth-verification diagnostic: status=%s cleanup_ssh=%s deleted_record=%s\n' \
    "$interrupt_status" \
    "$([[ -e "$fixture/records/ssh.cleanup.stdin" ]] && printf yes || printf no)" \
    "$([[ -e "$fixture/records/deleted-users" ]] && printf yes || printf no)" >&2
  sed -E 's/[a-f0-9]{64}/[redacted-credential]/g; s/goetz_verify_[a-f0-9]{16}/[redacted-username]/g' \
    "$interrupt_output" >&2
  fail 'the temporary remote verification user survived interruption'
fi
grep -Fqx "$interrupted_username" "$fixture/records/deleted-users" ||
  fail 'the interruption trap did not delete the temporary remote verification user'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state survived the interrupted remote run'

reset_records
touch "$fixture/block-auth"
direct_interrupt_output="$fixture/direct-interrupt-output"
/usr/bin/setsid /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  SSH_AUTH_SOCK="$auth_socket" \
  GOETZ_BASE_URL=https://goetzgoetz.kinsta.cloud \
  GOETZ_EXPECT_ORIGIN=https://goetzgoetz.kinsta.cloud \
  GOETZ_E2E_ALLOW_REMOTE=1 \
  /bin/bash "$fixture/manager.sh" test:e2e:auth \
    production-read-only.spec.ts \
    > "$direct_interrupt_output" 2>&1 &
direct_interrupt_pid=$!
for _ in $(seq 1 100); do
  [[ -e "$fixture/auth-started" ]] && break
  /usr/bin/sleep 0.05
done
[[ -e "$fixture/auth-started" ]] || fail 'the fake direct-signal Playwright run never started'
/bin/kill -TERM -- "$direct_interrupt_pid"
for _ in $(seq 1 100); do
  direct_interrupt_state="$(/bin/ps -o stat= -p "$direct_interrupt_pid" 2>/dev/null || true)"
  [[ -z "$direct_interrupt_state" || "$direct_interrupt_state" == Z* ]] && break
  /usr/bin/sleep 0.05
done
direct_interrupt_state="$(/bin/ps -o stat= -p "$direct_interrupt_pid" 2>/dev/null || true)"
if [[ -n "$direct_interrupt_state" && "$direct_interrupt_state" != Z* ]]; then
  /bin/kill -KILL -- "-$direct_interrupt_pid" 2>/dev/null || true
  wait "$direct_interrupt_pid" 2>/dev/null || true
  fail 'a direct manager termination did not interrupt Playwright and finish cleanup'
fi
set +e
wait "$direct_interrupt_pid"
direct_interrupt_status=$?
set -e
rm -f -- "$fixture/block-auth"
(( direct_interrupt_status != 0 )) || fail 'the directly interrupted remote verification run returned success'
direct_interrupted_username="$(cat "$fixture/records/created-username")"
[[ ! -e "$fixture/records/remote-user" ]] ||
  fail 'the temporary remote verification user survived a direct manager signal'
grep -Fqx "$direct_interrupted_username" "$fixture/records/deleted-users" ||
  fail 'the direct-signal cleanup trap did not delete the temporary remote verification user'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state survived the direct manager signal'

printf 'remote-auth-verification: PASS\n'
