#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() {
  printf 'repository-release: %s\n' "$1" >&2
  exit 1
}

# Curated media ships as immutable plugin source. Runtime uploads never belong
# in a release payload, including generated copies of these originals.
readonly CURATED_SEED_ORIGINALS_DIR='wp-content/plugins/goetz-site/assets/seed'
readonly -a ALLOWED_RSYNC_DELETE_PLUGINS=(
  goetz-site
  goetz-migration
  wordpress-seo
  wpforms-lite
)
[[ "${CURATED_SEED_ORIGINALS_DIR:-}" == 'wp-content/plugins/goetz-site/assets/seed' ]] ||
  fail 'curated seed originals must live in the goetz-site plugin assets/seed directory'
[[ "$CURATED_SEED_ORIGINALS_DIR" != wp-content/uploads/* ]] ||
  fail 'curated seed originals must never be sourced from runtime uploads'

normalize_absolute_path() {
  local path="$1"
  local component
  local -a components=()
  local -a normalized=()

  [[ "$path" == /* ]] || return 1
  IFS='/' read -r -a components <<< "$path"
  for component in "${components[@]}"; do
    case "$component" in
      ''|.)
        ;;
      ..)
        (( ${#normalized[@]} > 0 )) || return 1
        unset "normalized[$((${#normalized[@]} - 1))]"
        ;;
      *)
        normalized+=("$component")
        ;;
    esac
  done

  if (( ${#normalized[@]} == 0 )); then
    printf '/\n'
    return 0
  fi

  printf '/%s' "${normalized[0]}"
  for component in "${normalized[@]:1}"; do
    printf '/%s' "$component"
  done
  printf '\n'
}

rsync_delete_is_safe() {
  local record_path="$1"
  local site_root="$2"
  local argument
  local destination
  local destination_path
  local normalized_destination
  local normalized_site_root
  local plugin_path
  local plugin_name
  local allowed_plugin
  local has_delete=0
  local -a arguments=()

  [[ -s "$record_path" ]] || return 1
  mapfile -d '' -t arguments < "$record_path"
  (( ${#arguments[@]} >= 2 )) || return 1

  for argument in "${arguments[@]}"; do
    case "$argument" in
      --delete|--delete-*)
        has_delete=1
        ;;
    esac
  done
  (( has_delete == 1 )) || return 0

  destination="${arguments[$((${#arguments[@]} - 1))]}"
  case "$destination" in
    *:/*)
      destination_path="${destination#*:}"
      ;;
    /*)
      destination_path="$destination"
      ;;
    *)
      return 1
      ;;
  esac

  normalized_destination="$(normalize_absolute_path "$destination_path")" || return 1
  normalized_site_root="$(normalize_absolute_path "$site_root")" || return 1

  case "$normalized_destination" in
    "$normalized_site_root"|\
    "$normalized_site_root/wp-admin"|"$normalized_site_root/wp-admin/"*|\
    "$normalized_site_root/wp-includes"|"$normalized_site_root/wp-includes/"*|\
    "$normalized_site_root/wp-content/mu-plugins"|"$normalized_site_root/wp-content/mu-plugins/"*|\
    "$normalized_site_root/wp-content/uploads"|"$normalized_site_root/wp-content/uploads/"*|\
    "$normalized_site_root/wp-content/plugins")
      return 1
      ;;
    "$normalized_site_root/wp-content/plugins/"*)
      plugin_path="${normalized_destination#"$normalized_site_root/wp-content/plugins/"}"
      plugin_name="${plugin_path%%/*}"
      for allowed_plugin in "${ALLOWED_RSYNC_DELETE_PLUGINS[@]}"; do
        [[ "$plugin_name" == "$allowed_plugin" ]] && return 0
      done
      return 1
      ;;
  esac

  return 0
}

repository_script_is_delete_free() {
  local script_path="$1"

  awk '
    /^[[:space:]]*#/ { next }
    {
      content = content " " $0
    }
    END {
      has_rsync = content ~ /(^|[^[:alnum:]_])rsync([^[:alnum:]_]|$)/
      has_delete = content ~ /--delete(-[[:alnum:]_-]+)?([^[:alnum:]_-]|$)/
      exit(has_rsync && has_delete ? 1 : 0)
    }
  ' "$script_path"
}

scan_repository_deployment_scripts() {
  local script_path
  local -a deployment_scripts=()

  if (( $# > 0 )); then
    deployment_scripts=("$@")
  else
    deployment_scripts=(manager.sh)
    if [[ -d scripts/release ]]; then
      while IFS= read -r -d '' script_path; do
        deployment_scripts+=("$script_path")
      done < <(find scripts/release -maxdepth 1 -type f -name '*.sh' -print0)
    fi
  fi

  for script_path in "${deployment_scripts[@]}"; do
    if ! repository_script_is_delete_free "$script_path"; then
      printf 'repository deployment script contains forbidden rsync deletion: %s\n' "$script_path" >&2
      return 1
    fi
  done

  return 0
}

# Repository ignore and manager safety invariants.
git check-ignore -q --no-index .env
! git check-ignore -q --no-index .env.example
grep -Fqx '/.env*' .gitignore
grep -Fqx '/.env' .gitignore
grep -Fqx '!.env.example' .gitignore
grep -q '^unset SSH_KEY_PW$' manager.sh
mapfile -t ssh_unset_lines < <(grep -n '^unset SSH_KEY_PW$' manager.sh | cut -d: -f1)
root_dir_line="$(grep -n '^ROOT_DIR=' manager.sh | cut -d: -f1)"
(( ${#ssh_unset_lines[@]} >= 2 ))
(( ssh_unset_lines[0] < root_dir_line ))
awk '
  BEGIN { found = 0 }
  /^source "\$\{ROOT_DIR\}\/\.env"$/ {
    found = 1
    if ((getline next_line) <= 0 || next_line != "unset SSH_KEY_PW") exit 1
  }
  END { if (!found) exit 1 }
' manager.sh
grep -q 'COMPOSE_DISABLE_ENV_FILE=1' manager.sh
grep -q 'env -i' manager.sh
grep -q -- '--env-file /dev/null' manager.sh
! grep -q -- '--env-file .*\.env' manager.sh
! grep -Eq 'npm install([[:space:]]|$)' manager.sh
grep -Fq 'playwright-installer npx playwright install --with-deps chromium' manager.sh ||
  fail 'e2e:install must run the mandated browser/dependency installer in its isolated service'
! grep -Eq 'wp plugin install (wordpress-seo|wpforms-lite)([[:space:]]|$)' manager.sh
! grep -Eq 'deploy:db|wp db import' manager.sh
! grep -Fq '${ROOT_DIR}/wp-content/uploads/' manager.sh
declare -F scan_repository_deployment_scripts >/dev/null || fail 'repository rsync deletion scanner is missing'
scan_repository_deployment_scripts || fail 'repository deployment code violates the zero-delete baseline'

# Dependency reproducibility invariants. Lockfiles are source artifacts: they
# must exist, be trackable, and be consumed through locked install commands.
readonly -a REQUIRED_LOCKFILES=(
  composer.lock
  wp-content/themes/goetz-legal/composer.lock
  wp-content/themes/goetz-legal/package-lock.json
  wp-content/plugins/goetz-site/package-lock.json
  tests/e2e/package-lock.json
)
for lockfile in "${REQUIRED_LOCKFILES[@]}"; do
  [[ -f "$lockfile" ]] || fail "required dependency lockfile is missing: $lockfile"
  if git check-ignore -q --no-index "$lockfile"; then
    fail "required dependency lockfile is ignored: $lockfile"
  fi
done

[[ -f composer.json ]] || fail 'root composer.json is missing'
grep -Eq '"wpackagist-plugin/wordpress-seo"[[:space:]]*:[[:space:]]*"28\.0"' composer.json ||
  fail 'root composer.json must lock Yoast SEO to 28.0'
grep -Eq '"wpackagist-plugin/wpforms-lite"[[:space:]]*:[[:space:]]*"1\.10\.0\.4"' composer.json ||
  fail 'root composer.json must lock WPForms Lite to 1.10.0.4'
grep -A2 '"platform"' composer.json | grep -Eq '"php"[[:space:]]*:[[:space:]]*"8\.3\.0"' ||
  fail 'root Composer resolution must target production PHP 8.3'
grep -A2 '"platform"' wp-content/themes/goetz-legal/composer.json | grep -Eq '"php"[[:space:]]*:[[:space:]]*"8\.3\.0"' ||
  fail 'theme Composer resolution must target production PHP 8.3'

grep -Fq 'composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress' manager.sh ||
  fail 'manager production dependency install must be locked and optimized'
grep -Fq 'npm ci' manager.sh || fail 'manager Node dependency installs must use npm ci'
grep -Fq 'vendor/bin/phpunit --cache-result-file /work/vendor/.phpunit.result.cache' manager.sh ||
  fail 'PHPUnit cache must use the narrow writable Composer vendor mount'
! grep -Eq '(^|[[:space:]])npm install([[:space:]]|$)' manager.sh ||
  fail 'manager contains a floating npm install'
! grep -Eq '(^|[[:space:]])composer (update|require)([[:space:]]|$)' manager.sh ||
  fail 'manager contains a floating Composer operation'

# The legacy baseline is captured through a dedicated, read-only browser
# contract. The only tracked writable bind is the immutable fixture directory.
[[ -f tests/e2e/helpers/settle-page.ts ]] ||
  fail 'legacy capture settle helper is missing'
[[ -f tests/e2e/capture-reference.spec.ts ]] ||
  fail 'legacy reference capture specification is missing'
grep -Fq 'CALLER_GOETZ_REFERENCE_URL_SET=' manager.sh ||
  fail 'manager does not preserve an explicit caller reference URL across sanitized env loading'
grep -Fq 'CALLER_GOETZ_REFERENCE_ALLOW_OVERRIDE_SET=' manager.sh ||
  fail 'manager does not preserve explicit reference override approval across sanitized env loading'
grep -Fq 'CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN_SET=' manager.sh ||
  fail 'manager does not preserve an explicit reference expected origin across sanitized env loading'
grep -Fq 'visual:capture-reference)' manager.sh ||
  fail 'manager is missing the one-time reference capture dispatcher'
grep -Fq 'GOETZ_CAPTURE_MODE=contract' manager.sh ||
  fail 'test:capture must select non-writing contract mode'
grep -Fq 'GOETZ_CAPTURE_MODE=write' manager.sh ||
  fail 'visual:capture-reference must select immutable fixture write mode'
grep -Fq "serviceWorkers: 'block'" tests/e2e/playwright.capture.config.ts ||
  fail 'capture browser must block service workers'
grep -Fq 'acceptDownloads: false' tests/e2e/playwright.capture.config.ts ||
  fail 'capture browser must refuse downloads'
! grep -Fq 'wordpressLaunchOptions' tests/e2e/playwright.capture.config.ts ||
  fail 'remote-only capture config must never install a local host-gateway route'
for required_capture_marker in \
  'captured_at_utc' \
  'pixel_width' \
  'pixel_height' \
  "userAgent: devices['Desktop Chrome'].userAgent" \
  "animations: 'disabled'" \
  "caret: 'hide'" \
  "scale: 'css'" \
  "topbar: '#header_meta'" \
  "primary_nav: '#avia-menu'" \
  "logo: '#header_main .logo img'" \
  "practice_items: '#av-layout-grid-1 .article-icon-entry'" \
  "footer_columns: '#av_section_5 .entry-content-wrapper > .flex_column'" \
  'border_radius' \
  'padding_top' \
  'margin_top' \
  'object_fit' \
  'object_position' \
  'transform: style.transform' \
  'read_only_contract' \
  'allowed_methods' \
  'blocked_requests: []' \
  'dynamic_masks: []' \
  'images_complete' \
  'returned_to_top' \
  'browser_version' \
  'browser_name' \
  'device_scale_factor'; do
  grep -Fq "$required_capture_marker" tests/e2e/capture-reference.spec.ts ||
    fail "capture specification is missing deterministic geometry marker: $required_capture_marker"
done
grep -Fq 'practiceIconSelector' tests/e2e/helpers/settle-page.ts ||
  fail 'settle helper does not monitor the live practice icon animation seam'
grep -Fq 'practiceIcons' tests/e2e/helpers/settle-page.ts ||
  fail 'settle helper layout signature omits live practice icon styles'
grep -Fq 'await assertPracticeIconsComplete(page);' tests/e2e/capture-reference.spec.ts ||
  fail 'live capture does not require all seven practice icons to reach their final state'
[[ "$(grep -Fc 'assertFinalReferenceLocation(page, target)' tests/e2e/capture-reference.spec.ts)" -ge 2 ]] ||
  fail 'capture must revalidate the exact final location after settlement and before screenshot geometry'

inspect_release() {
  local release_dir="$1"
  local entry

  [[ -d "$release_dir" ]] || fail "GOETZ_RELEASE_DIR is not a directory: $release_dir"

  while IFS= read -r entry; do
    case "$entry" in
      .env*|*/.env*|*.sql|*/.git|*/.git/*|.git|.git/*|vendor|vendor/*|node_modules|*/node_modules|node_modules/*|*/node_modules/*|tests|*/tests|tests/*|*/tests/*)
        fail "forbidden release entry: $entry"
        ;;
    esac
  done < <(find "$release_dir" -mindepth 1 -printf '%P\n')

  while IFS= read -r entry; do
    case "$entry" in
      wp-content/uploads/*)
        fail "unapproved upload in release: $entry"
        ;;
    esac
  done < <(find "$release_dir" \( -type f -o -type l \) -printf '%P\n')
}

if [[ -n "${GOETZ_RELEASE_DIR:-}" ]]; then
  inspect_release "$GOETZ_RELEASE_DIR"
fi

# Prove against a disposable manager copy that secrets and non-allowlisted
# settings from .env never reach Docker. This fixture never reads workspace .env.
fixture="$(mktemp -d "${TMPDIR:-/tmp}/goetz-release-contract.XXXXXX")"
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/bin" "$fixture/home"
cp manager.sh .env.example "$fixture/"

cat > "$fixture/.env" <<'ENV'
COMPOSE_PROJECT_NAME=contract-project
WP_PORT=18080
MYSQL_DATABASE=contract_database
MYSQL_USER=contract_user
MYSQL_PASSWORD=contract_password
MYSQL_ROOT_PASSWORD=contract_root_password
FETCH_PROXY_URL=https://proxy.invalid/fetch
WORDPRESS_IMAGE=wordpress:contract
WPCLI_IMAGE=wordpress:cli-contract
WP_URL=https://local.invalid
WP_TITLE="Never export this title"
WP_ADMIN_USER=never-export-admin
WP_ADMIN_PASSWORD=never-export-admin-password
WP_ADMIN_EMAIL=never-export@example.invalid
SOURCE_URL=https://source.invalid
PROD_URL=https://production.invalid
KINSTA_SSH_USER=never-export-ssh-user
KINSTA_SSH_HOST=never-export-ssh-host
KINSTA_SSH_PORT=65535
KINSTA_SITE_PATH=/never/export/this/path
GOETZ_NOT_ALLOWLISTED=never-forward-non-allowlisted
GOETZ_BASE_URL=https://env-base.invalid
GOETZ_EXPECT_ORIGIN=https://env-origin.invalid
GOETZ_EXPECT_PRODUCTION=env-production
GOETZ_E2E_ALLOW_REMOTE=env-allow
GOETZ_E2E_USER=env-user
GOETZ_E2E_PASSWORD=env-password
GOETZ_REFERENCE_URL=https://env-reference.invalid
GOETZ_REFERENCE_EXPECT_ORIGIN=https://env-reference-origin.invalid
GOETZ_REFERENCE_ALLOW_OVERRIDE=env-reference-allow
SSH_KEY_PW=never-forward-this-test-value
ENV
chmod 0644 "$fixture/.env"

cat > "$fixture/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail
record="${0%/*}/docker-record"
count_file="${0%/*}/docker-count"
fixture_root="$(cd "${0%/*}/.." && pwd)"
count=0
if [[ -s "$count_file" ]]; then
  read -r count < "$count_file"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"

write_record() {
  local target="$1"
  shift
  {
    printf 'argv:'
    printf ' <%s>' "$@"
    printf '\n'
    /usr/bin/env | /usr/bin/sort
  } >> "$target"
}

write_record "$record" "$@"
write_record "${record}.${count}" "$@"

is_npm=0
is_ci=0
for argument in "$@"; do
  [[ "$argument" == 'npm' ]] && is_npm=1
  [[ "$argument" == 'ci' ]] && is_ci=1
  if [[ "$argument" == 'test:auth' ]]; then
    mkdir -p "$fixture_root/__dev/playwright/auth-state"
    printf '{"synthetic":"state"}\n' > "$fixture_root/__dev/playwright/auth-state/auth-state.json"
    chmod 0644 "$fixture_root/__dev/playwright/auth-state/auth-state.json"
    if [[ -e "$fixture_root/fail-auth" ]]; then
      exit 9
    fi
  fi
done
if (( is_npm == 1 && is_ci == 1 )) && [[ -e "$fixture_root/fail-npm" ]]; then
  exit 8
fi
DOCKER
chmod 700 "$fixture/bin/docker"

reset_fake_docker() {
  rm -f "$fixture/bin/docker-count" "$fixture/bin/docker-record" "$fixture/bin"/docker-record.*
}

/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_PREEXPORTED_SENTINEL=never-forward-preexported-value \
  WP_ADMIN_PASSWORD=never-forward-preexported-admin \
  SSH_KEY_PW=never-forward-inherited-ssh-value \
  /bin/bash "$fixture/manager.sh" compose config

[[ "$(stat -c '%a' "$fixture/.env")" == '600' ]] || fail 'existing synthetic .env was not restricted to mode 600'

record="$fixture/bin/docker-record"
[[ -s "$record" ]] || fail 'fake Docker did not record the Compose invocation'
[[ "$(grep -c '^argv:' "$record")" -eq 2 ]] || fail 'expected sanitized docker version and Compose invocations'
grep -Fq 'argv: <compose> <--env-file> </dev/null> <config>' "$record" ||
  fail 'Compose was not invoked with --env-file /dev/null and quoted forwarding'

allowed=(
  COMPOSE_DISABLE_ENV_FILE
  COMPOSE_PROJECT_NAME
  WP_PORT
  MYSQL_DATABASE
  MYSQL_USER
  MYSQL_PASSWORD
  MYSQL_ROOT_PASSWORD
  FETCH_PROXY_URL
  WORDPRESS_IMAGE
  WPCLI_IMAGE
)
for name in "${allowed[@]}"; do
  grep -q "^${name}=" "$record" || fail "allowlisted Compose variable was not supplied: $name"
done

disallowed=(
  SSH_KEY_PW
  WP_URL
  WP_TITLE
  WP_ADMIN_USER
  WP_ADMIN_PASSWORD
  WP_ADMIN_EMAIL
  SOURCE_URL
  PROD_URL
  KINSTA_SSH_USER
  KINSTA_SSH_HOST
  KINSTA_SSH_PORT
  KINSTA_SITE_PATH
  GOETZ_NOT_ALLOWLISTED
  GOETZ_PREEXPORTED_SENTINEL
  GOETZ_BASE_URL
  GOETZ_EXPECT_ORIGIN
  GOETZ_EXPECT_PRODUCTION
  GOETZ_E2E_ALLOW_REMOTE
  GOETZ_E2E_USER
  GOETZ_E2E_PASSWORD
  GOETZ_REFERENCE_URL
  GOETZ_REFERENCE_ALLOW_OVERRIDE
  GOETZ_REFERENCE_EXPECT_ORIGIN
  GOETZ_REFERENCE_OVERRIDE_APPROVED
  GOETZ_CAPTURE_MODE
)
for name in "${disallowed[@]}"; do
  ! grep -q "^${name}=" "$record" || fail "non-allowlisted variable reached Docker: $name"
done

! grep -Fq 'never-forward-this-test-value' "$record" || fail 'synthetic SSH passphrase reached Docker'
! grep -Fq 'never-forward-inherited-ssh-value' "$record" || fail 'inherited synthetic SSH passphrase reached Docker'
! grep -Fq 'never-forward-non-allowlisted' "$record" || fail 'synthetic non-allowlisted value reached Docker'
! grep -Fq 'never-forward-preexported-value' "$record" || fail 'pre-exported synthetic value reached Docker'
! grep -Fq "$fixture/.env" "$record" || fail 'synthetic .env path reached Docker'

readonly -a GOETZ_BROWSER_VARIABLES=(
  GOETZ_BASE_URL
  GOETZ_EXPECT_ORIGIN
  GOETZ_EXPECT_PRODUCTION
  GOETZ_E2E_ALLOW_REMOTE
  GOETZ_E2E_USER
  GOETZ_E2E_PASSWORD
  GOETZ_REFERENCE_URL
  GOETZ_REFERENCE_ALLOW_OVERRIDE
  GOETZ_REFERENCE_EXPECT_ORIGIN
  GOETZ_REFERENCE_OVERRIDE_APPROVED
  GOETZ_CAPTURE_MODE
)

assert_no_browser_environment() {
  local record_path="$1"
  local name

  [[ -s "$record_path" ]] || fail "missing fake Docker invocation record: $record_path"
  for name in "${GOETZ_BROWSER_VARIABLES[@]}"; do
    ! grep -q "^${name}=" "$record_path" ||
      fail "browser-only variable reached unrelated Docker invocation: $name"
  done
}

assert_public_browser_environment() {
  local record_path="$1"

  [[ -s "$record_path" ]] || fail "missing public browser Docker invocation record: $record_path"
  grep -Fqx 'GOETZ_BASE_URL=https://caller-base.invalid' "$record_path" ||
    fail 'public browser invocation did not receive the caller base URL'
  grep -Fqx 'GOETZ_EXPECT_ORIGIN=https://caller-origin.invalid' "$record_path" ||
    fail 'public browser invocation did not receive the caller expected origin'
  grep -Fqx 'GOETZ_EXPECT_PRODUCTION=caller-production' "$record_path" ||
    fail 'public browser invocation did not receive the caller production expectation'
  for name in GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD; do
    ! grep -q "^${name}=" "$record_path" ||
      fail "public browser invocation received an authenticated-only variable: $name"
  done
  for name in GOETZ_REFERENCE_URL GOETZ_REFERENCE_ALLOW_OVERRIDE GOETZ_REFERENCE_EXPECT_ORIGIN \
    GOETZ_REFERENCE_OVERRIDE_APPROVED GOETZ_CAPTURE_MODE; do
    ! grep -q "^${name}=" "$record_path" ||
      fail "public browser invocation received a capture-only variable: $name"
  done
}

run_browser_fixture() {
  local command="$1"
  shift
  local expected_origin='https://caller-origin.invalid'
  if [[ "$command" == 'test:e2e:auth' ]]; then
    expected_origin='https://caller-base.invalid'
  fi

  /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    GOETZ_BASE_URL=https://caller-base.invalid \
    GOETZ_EXPECT_ORIGIN="$expected_origin" \
    GOETZ_EXPECT_PRODUCTION=caller-production \
    GOETZ_E2E_ALLOW_REMOTE=1 \
    GOETZ_E2E_USER=caller-user \
    GOETZ_E2E_PASSWORD=caller-password \
    /bin/bash "$fixture/manager.sh" "$command" "$@"
}

reset_fake_docker
run_browser_fixture test:e2e:auth --grep 'two words' ||
  fail 'synthetic authenticated browser invocation failed unexpectedly'
assert_no_browser_environment "$fixture/bin/docker-record.1"
assert_no_browser_environment "$fixture/bin/docker-record.2"
auth_record="$fixture/bin/docker-record.3"
grep -Fqx 'GOETZ_BASE_URL=https://caller-base.invalid' "$auth_record" ||
  fail 'authenticated browser invocation did not receive caller base URL'
grep -Fqx 'GOETZ_EXPECT_ORIGIN=https://caller-base.invalid' "$auth_record" ||
  fail 'authenticated browser invocation did not receive caller expected origin'
grep -Fqx 'GOETZ_EXPECT_PRODUCTION=caller-production' "$auth_record" ||
  fail 'authenticated browser invocation did not receive caller production expectation'
grep -Fqx 'GOETZ_E2E_ALLOW_REMOTE=1' "$auth_record" ||
  fail 'authenticated browser invocation did not receive caller remote opt-in'
grep -Fqx 'GOETZ_E2E_USER=caller-user' "$auth_record" ||
  fail 'authenticated browser invocation did not receive caller username'
grep -Fqx 'GOETZ_E2E_PASSWORD=caller-password' "$auth_record" ||
  fail 'authenticated browser invocation did not receive caller password'
grep -Fq '<--grep> <two words>' "$auth_record" ||
  fail 'authenticated browser focused arguments were not quoted and forwarded intact'
grep -Fq '<playwright-auth>' "$auth_record" ||
  fail 'authenticated browser invocation did not use the state-enabled runtime service'
! grep -Fq '<playwright-auth-local>' "$auth_record" ||
  fail 'remote authenticated browser invocation used the local host-gateway service'
[[ -d "$fixture/__dev/playwright/auth-state" ]] || fail 'authenticated browser state directory was not prepared'
[[ "$(stat -c '%a' "$fixture/__dev/playwright/auth-state")" == '700' ]] ||
  fail 'authenticated browser state directory was not restricted to mode 700'
[[ -d "$fixture/__dev/playwright/auth-node-modules" ]] ||
  fail 'authenticated browser dependency directory was not prepared'
[[ -d "$fixture/__dev/playwright/public-node-modules" ]] ||
  fail 'public browser dependency directory was not prepared'
[[ -d "$fixture/artifacts/playwright/auth" ]] ||
  fail 'authenticated browser artifact directory was not prepared'
[[ "$(stat -c '%a' "$fixture/artifacts/playwright/auth")" == '700' ]] ||
  fail 'authenticated browser artifact directory was not restricted to mode 700'
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state persisted after a successful run'
if find "$fixture/__dev/playwright/auth-state" -maxdepth 1 -name 'auth-state.json.tmp.*' -print -quit | grep -q .; then
  fail 'authenticated browser temporary state persisted after a successful run'
fi

reset_fake_docker
touch "$fixture/fail-auth"
if run_browser_fixture test:e2e:auth >/dev/null 2>&1; then
  fail 'synthetic authenticated browser failure unexpectedly succeeded'
fi
rm -f "$fixture/fail-auth"
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'authenticated browser state persisted after a failed run'
if find "$fixture/__dev/playwright/auth-state" -maxdepth 1 -name 'auth-state.json.tmp.*' -print -quit | grep -q .; then
  fail 'authenticated browser temporary state persisted after a failed run'
fi

reset_fake_docker
printf '{"synthetic":"stale"}\n' > "$fixture/__dev/playwright/auth-state/auth-state.json"
touch "$fixture/fail-npm"
if run_browser_fixture test:e2e:auth >/dev/null 2>&1; then
  fail 'synthetic authenticated dependency-install failure unexpectedly succeeded'
fi
rm -f "$fixture/fail-npm"
[[ ! -e "$fixture/__dev/playwright/auth-state/auth-state.json" ]] ||
  fail 'stale authenticated browser state survived a failed dependency install'

reset_fake_docker
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL=https://remote.invalid \
  GOETZ_E2E_ALLOW_REMOTE=1 \
  /bin/bash "$fixture/manager.sh" test:e2e:auth >/dev/null 2>&1; then
  fail 'remote authenticated browser run accepted missing caller credentials'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'remote authenticated credential validation invoked Docker before rejecting the request'

reset_fake_docker
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL=http://localhost:8080@remote.invalid \
  /bin/bash "$fixture/manager.sh" test:e2e:auth >/dev/null 2>&1; then
  fail 'userinfo-shaped remote URL bypassed the authenticated remote opt-in guard'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'authenticated URL validation invoked Docker before rejecting a non-loopback authority'

reset_fake_docker
mixed_auth_output="$fixture/mixed-auth-output"
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL=http://localhost:18080 \
  GOETZ_EXPECT_ORIGIN=https://redirect.invalid \
  /bin/bash "$fixture/manager.sh" test:e2e:auth >"$mixed_auth_output" 2>&1; then
  fail 'local authenticated base accepted a remote expected origin'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'mixed authenticated origins invoked Docker before rejection'
for forbidden in 'http://localhost:18080' 'https://redirect.invalid' never-export-admin never-export-admin-password; do
  ! grep -Fq "$forbidden" "$mixed_auth_output" ||
    fail 'mixed authenticated origin rejection disclosed sensitive context'
done

reset_fake_docker
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL=https://caller-base.invalid \
  GOETZ_EXPECT_ORIGIN=https://other-remote.invalid \
  GOETZ_E2E_ALLOW_REMOTE=1 \
  GOETZ_E2E_USER=caller-user \
  GOETZ_E2E_PASSWORD=caller-password \
  /bin/bash "$fixture/manager.sh" test:e2e:auth >/dev/null 2>&1; then
  fail 'authenticated browser accepted a different remote expected origin'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'different authenticated origins invoked Docker before rejection'

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL=HTTPS://CALLER-BASE.INVALID:443/subpath \
  GOETZ_EXPECT_ORIGIN=https://caller-base.invalid \
  GOETZ_E2E_ALLOW_REMOTE=1 \
  GOETZ_E2E_USER=caller-user \
  GOETZ_E2E_PASSWORD=caller-password \
  /bin/bash "$fixture/manager.sh" test:e2e:auth ||
  fail 'authenticated origin comparison did not normalize scheme, host, and default port'
grep -Fq '<playwright-auth>' "$fixture/bin/docker-record.3" ||
  fail 'canonical remote authenticated origin did not use the remote service'

for malformed_expected in \
  'http://synthetic-user@localhost:18080' \
  'http://localhost:99999' \
  'http://[::::]:18080' \
  'http://[127.0.0.1]:18080' \
  'http://[12345::1]:18080'; do
  reset_fake_docker
  if /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    GOETZ_BASE_URL=http://localhost:18080 \
    GOETZ_EXPECT_ORIGIN="$malformed_expected" \
    /bin/bash "$fixture/manager.sh" test:e2e:auth >/dev/null 2>&1; then
    fail 'authenticated browser accepted userinfo or a malformed expected origin'
  fi
  [[ ! -e "$fixture/bin/docker-record" ]] ||
    fail 'malformed authenticated expected origin invoked Docker before rejection'
done

for malformed_base in \
  'http://[::::]:18080' \
  'http://[127.0.0.1]:18080' \
  'http://[12345::1]:18080'; do
  reset_fake_docker
  if /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    GOETZ_BASE_URL="$malformed_base" \
    /bin/bash "$fixture/manager.sh" test:public >/dev/null 2>&1; then
    fail 'public browser accepted a malformed bracketed base URL'
  fi
  [[ ! -e "$fixture/bin/docker-record" ]] ||
    fail 'malformed public base URL invoked Docker before rejection'
done

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL='http://[::1]:18080' \
  /bin/bash "$fixture/manager.sh" test:public ||
  fail 'supported bracketed IPv6 loopback URL was rejected'
grep -Fq '<playwright-local>' "$fixture/bin/docker-record.3" ||
  fail 'bracketed IPv6 loopback did not select the local-only browser service'

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL='http://[::1]:18080' \
  /bin/bash "$fixture/manager.sh" test:e2e:auth ||
  fail 'bracketed IPv6 authenticated loopback required remote opt-in'
ipv6_auth_record="$fixture/bin/docker-record.3"
grep -Fq '<playwright-auth-local>' "$ipv6_auth_record" ||
  fail 'bracketed IPv6 authenticated loopback did not use the local auth service'
grep -Fqx 'GOETZ_E2E_USER=never-export-admin' "$ipv6_auth_record" ||
  fail 'bracketed IPv6 authenticated loopback did not use the local username fallback'
grep -Fqx 'GOETZ_E2E_PASSWORD=never-export-admin-password' "$ipv6_auth_record" ||
  fail 'bracketed IPv6 authenticated loopback did not use the local password fallback'
! grep -q '^GOETZ_E2E_ALLOW_REMOTE=' "$ipv6_auth_record" ||
  fail 'bracketed IPv6 authenticated loopback received remote opt-in'

for public_command in test:public; do
  for mixed_pair in \
    'http://localhost:18080 https://redirect.invalid' \
    'https://caller-base.invalid http://127.0.0.1:18080'; do
    read -r mixed_base mixed_expected <<< "$mixed_pair"
    reset_fake_docker
    if /usr/bin/env -i \
      HOME="$fixture/home" \
      PATH="$fixture/bin:/usr/bin:/bin" \
      GOETZ_BASE_URL="$mixed_base" \
      GOETZ_EXPECT_ORIGIN="$mixed_expected" \
      /bin/bash "$fixture/manager.sh" "$public_command" >/dev/null 2>&1; then
      fail "$public_command accepted mixed local/remote base and expected origins"
    fi
    [[ ! -e "$fixture/bin/docker-record" ]] ||
      fail "$public_command mixed-origin validation invoked Docker before rejection"
  done
done

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_BASE_URL=http://localhost:18080 \
  /bin/bash "$fixture/manager.sh" test:e2e:auth ||
  fail 'local authenticated browser run did not retain local WordPress admin fallback'
local_auth_record="$fixture/bin/docker-record.3"
grep -Fqx 'GOETZ_E2E_USER=never-export-admin' "$local_auth_record" ||
  fail 'local authenticated browser run did not receive the local WordPress username fallback'
grep -Fqx 'GOETZ_E2E_PASSWORD=never-export-admin-password' "$local_auth_record" ||
  fail 'local authenticated browser run did not receive the local WordPress password fallback'
grep -Fq '<playwright-auth-local>' "$local_auth_record" ||
  fail 'local authenticated browser run did not use the local-only host-gateway service'

for public_command in test:public; do
  reset_fake_docker
  run_browser_fixture "$public_command" --grep 'public words' ||
    fail "synthetic browser invocation failed unexpectedly: $public_command"
  assert_no_browser_environment "$fixture/bin/docker-record.1"
  assert_no_browser_environment "$fixture/bin/docker-record.2"
  public_record="$fixture/bin/docker-record.3"
  assert_public_browser_environment "$public_record"
  grep -Fq '<playwright>' "$public_record" ||
    fail "$public_command did not use the state-free runtime service"
  ! grep -Fq '<playwright-local>' "$public_record" ||
    fail "$public_command remote run used the local host-gateway service"
  ! grep -Fq '<playwright-auth>' "$public_record" ||
    fail "$public_command used the authenticated state-enabled runtime service"
  grep -Fq '<--grep> <public words>' "$public_record" ||
    fail "$public_command focused arguments were not quoted and forwarded intact"
done

for public_command in test:public; do
  reset_fake_docker
  /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    GOETZ_BASE_URL=http://localhost:18080 \
    /bin/bash "$fixture/manager.sh" "$public_command" --grep 'local public words' ||
    fail "synthetic local browser invocation failed unexpectedly: $public_command"
  local_public_record="$fixture/bin/docker-record.3"
  grep -Fq '<playwright-local>' "$local_public_record" ||
    fail "$public_command local run did not use the local-only host-gateway service"
  ! grep -Fq '<playwright-auth-local>' "$local_public_record" ||
    fail "$public_command local run used an authenticated service"
  for auth_name in GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD; do
    ! grep -q "^${auth_name}=" "$local_public_record" ||
      fail "$public_command local run received an auth-only variable: $auth_name"
  done
done

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_REFERENCE_URL=https://must-not-reach-contract.invalid \
  GOETZ_REFERENCE_ALLOW_OVERRIDE=1 \
  /bin/bash "$fixture/manager.sh" test:capture --grep 'reference capture contract' ||
  fail 'synthetic capture-contract invocation failed unexpectedly'
assert_no_browser_environment "$fixture/bin/docker-record.1"
assert_no_browser_environment "$fixture/bin/docker-record.2"
capture_contract_record="$fixture/bin/docker-record.3"
grep -Fq '<playwright-capture>' "$capture_contract_record" ||
  fail 'test:capture did not use the dedicated capture service'
grep -Fqx 'GOETZ_CAPTURE_MODE=contract' "$capture_contract_record" ||
  fail 'test:capture did not select non-writing contract mode'
for name in GOETZ_REFERENCE_URL GOETZ_REFERENCE_ALLOW_OVERRIDE GOETZ_REFERENCE_EXPECT_ORIGIN \
  GOETZ_REFERENCE_OVERRIDE_APPROVED \
  GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD GOETZ_BASE_URL; do
  ! grep -q "^${name}=" "$capture_contract_record" ||
    fail "capture-contract invocation received an unapproved variable: $name"
done
grep -Fq '<--grep> <reference capture contract>' "$capture_contract_record" ||
  fail 'capture-contract focused arguments were not quoted and forwarded intact'

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_REFERENCE_URL=https://goetzlegal.com \
  /bin/bash "$fixture/manager.sh" visual:capture-reference ||
  fail 'synthetic one-time reference capture invocation failed unexpectedly'
assert_no_browser_environment "$fixture/bin/docker-record.1"
assert_no_browser_environment "$fixture/bin/docker-record.2"
capture_write_record="$fixture/bin/docker-record.3"
grep -Fq '<playwright-capture>' "$capture_write_record" ||
  fail 'visual:capture-reference did not use the dedicated capture service'
grep -Fqx 'GOETZ_CAPTURE_MODE=write' "$capture_write_record" ||
  fail 'visual:capture-reference did not select immutable write mode'
grep -Fqx 'GOETZ_REFERENCE_URL=https://goetzlegal.com/' "$capture_write_record" ||
  fail 'visual:capture-reference did not forward the canonical default reference URL'
grep -Fqx 'GOETZ_REFERENCE_EXPECT_ORIGIN=https://goetzlegal.com' "$capture_write_record" ||
  fail 'visual:capture-reference did not forward the exact expected origin'
for name in GOETZ_REFERENCE_ALLOW_OVERRIDE GOETZ_REFERENCE_OVERRIDE_APPROVED \
  GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER \
  GOETZ_E2E_PASSWORD GOETZ_BASE_URL; do
  ! grep -q "^${name}=" "$capture_write_record" ||
    fail "reference capture received an unapproved variable: $name"
done

reset_fake_docker
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_REFERENCE_URL=https://override.invalid \
  /bin/bash "$fixture/manager.sh" visual:capture-reference >/dev/null 2>&1; then
  fail 'non-default reference capture succeeded without explicit override approval'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'unapproved reference override invoked Docker before rejection'

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_REFERENCE_URL=https://override.invalid \
  GOETZ_REFERENCE_EXPECT_ORIGIN=https://override.invalid \
  GOETZ_REFERENCE_ALLOW_OVERRIDE=1 \
  /bin/bash "$fixture/manager.sh" visual:capture-reference ||
  fail 'fully approved exact HTTPS reference override was rejected'
approved_override_record="$fixture/bin/docker-record.3"
grep -Fqx 'GOETZ_REFERENCE_URL=https://override.invalid/' "$approved_override_record" ||
  fail 'approved reference override was not canonicalized before forwarding'
grep -Fqx 'GOETZ_REFERENCE_EXPECT_ORIGIN=https://override.invalid' "$approved_override_record" ||
  fail 'approved reference expected origin was not canonicalized before forwarding'
grep -Fqx 'GOETZ_REFERENCE_OVERRIDE_APPROVED=1' "$approved_override_record" ||
  fail 'approved non-default reference did not receive manager-issued runtime approval'
! grep -q '^GOETZ_REFERENCE_ALLOW_OVERRIDE=' "$approved_override_record" ||
  fail 'caller reference approval flag reached the capture container'

reset_fake_docker
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  GOETZ_REFERENCE_URL=https://override.invalid \
  GOETZ_REFERENCE_EXPECT_ORIGIN=https://different.invalid \
  GOETZ_REFERENCE_ALLOW_OVERRIDE=1 \
  /bin/bash "$fixture/manager.sh" visual:capture-reference >/dev/null 2>&1; then
  fail 'reference override accepted a mismatched caller expected origin'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'mismatched reference expected origin invoked Docker before rejection'

for rejected_reference in \
  'http://goetzlegal.com' \
  'https://synthetic-user@goetzlegal.com' \
  'https://goetzlegal.com/path' \
  'https://goetzlegal.com/?query=1' \
  'https://goetzlegal.com/#fragment'; do
  reset_fake_docker
  if /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    GOETZ_REFERENCE_URL="$rejected_reference" \
    GOETZ_REFERENCE_ALLOW_OVERRIDE=1 \
    /bin/bash "$fixture/manager.sh" visual:capture-reference >/dev/null 2>&1; then
    fail 'reference capture accepted a non-exact HTTPS origin URL'
  fi
  [[ ! -e "$fixture/bin/docker-record" ]] ||
    fail 'invalid reference target invoked Docker before rejection'
done

reset_fake_docker
if /usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  /bin/bash "$fixture/manager.sh" visual:capture-reference unexpected >/dev/null 2>&1; then
  fail 'visual:capture-reference accepted positional arguments'
fi
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'invalid visual:capture-reference arguments invoked Docker'

compose_service_block() {
  local service="$1"

  awk -v service="$service" '
    $0 ~ "^  " service ":[[:space:]]*$" { in_service = 1 }
    in_service && $0 ~ /^  [[:alnum:]_-]+:[[:space:]]*$/ &&
      $0 !~ "^  " service ":[[:space:]]*$" { exit }
    in_service { print }
  ' docker-compose.yml
}

assert_bind_sources_allowlisted() {
  local service="$1"
  shift
  local block
  local line
  local mount
  local source
  local allowed_source
  local accepted
  local in_volumes=0
  block="$(compose_service_block "$service")"
  [[ -n "$block" ]] || fail "Compose service is missing: $service"

  while IFS= read -r line; do
    if [[ "$line" =~ ^[[:space:]]{4}volumes:[[:space:]]*$ ]]; then
      in_volumes=1
      continue
    fi
    if (( in_volumes == 1 )) && [[ "$line" =~ ^[[:space:]]{4}[[:alnum:]_-]+: ]]; then
      in_volumes=0
    fi
    (( in_volumes == 1 )) || continue
    [[ "$line" =~ ^[[:space:]]{6}-[[:space:]]+([^:]+): ]] ||
      fail "$service uses an unparsed or long-syntax volume entry: $line"
    mount="${line#*- }"
    source="${mount%%:*}"
    accepted=0
    for allowed_source in "$@"; do
      [[ "$source" == "$allowed_source" ]] && accepted=1
    done
    (( accepted == 1 )) || fail "$service exposes an unapproved bind source: $source"
  done <<< "$block"
}

playwright_capture_block="$(compose_service_block playwright-capture)"
[[ -n "$playwright_capture_block" ]] || fail 'dedicated Playwright capture service is missing'
grep -Eq '^[[:space:]]*user:[[:space:]]*"?1000:1000"?[[:space:]]*$' <<< "$playwright_capture_block" ||
  fail 'capture Playwright must run as the non-root repository user'
grep -Eq '^[[:space:]]*read_only:[[:space:]]*true[[:space:]]*$' <<< "$playwright_capture_block" ||
  fail 'capture Playwright root filesystem must be read-only'
grep -Eq '^[[:space:]]*-[[:space:]]*ALL[[:space:]]*$' <<< "$playwright_capture_block" ||
  fail 'capture Playwright must drop all Linux capabilities'
grep -Fq 'no-new-privileges:true' <<< "$playwright_capture_block" ||
  fail 'capture Playwright must disable privilege escalation'
grep -Eq '^[[:space:]]*shm_size:[[:space:]]*' <<< "$playwright_capture_block" ||
  fail 'capture Playwright must use private shared memory'
! grep -Eq '^[[:space:]]*(network_mode|ipc):[[:space:]]*host([[:space:]]|$)' <<< "$playwright_capture_block" ||
  fail 'capture Playwright must not share host network or IPC namespaces'
! grep -Fq 'host.docker.internal' <<< "$playwright_capture_block" ||
  fail 'remote-only capture Playwright must not receive a local host-gateway route'
! grep -Fq 'GOETZ_COMPOSE_HOST_GATEWAY' <<< "$playwright_capture_block" ||
  fail 'remote-only capture Playwright must not enable localhost resolver mapping'
! grep -Fq 'GOETZ_AUTH_STATE_PATH' <<< "$playwright_capture_block" ||
  fail 'capture Playwright must not receive authenticated state'
for forbidden_name in GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD; do
  ! grep -Fq "$forbidden_name" <<< "$playwright_capture_block" ||
    fail "capture Playwright statically defines an auth-only setting: $forbidden_name"
done
grep -Fq 'GOETZ_CAPTURE_OUTPUT_DIR: /work/fixtures' <<< "$playwright_capture_block" ||
  fail 'capture output must be statically pinned to the sole writable fixture mount'
assert_bind_sources_allowlisted playwright-capture \
  ./tests/e2e ./__dev/playwright/capture-node-modules \
  ./artifacts/playwright/capture ./tests/visual/fixtures/legacy
for required_mount in \
  './tests/e2e:/work/e2e:ro' \
  './__dev/playwright/capture-node-modules:/work/e2e/node_modules' \
  './artifacts/playwright/capture:/work/artifacts' \
  './tests/visual/fixtures/legacy:/work/fixtures'; do
  grep -Fq "$required_mount" <<< "$playwright_capture_block" ||
    fail "capture Playwright narrow mount is missing: $required_mount"
done
[[ "$(grep -Ec '^[[:space:]]*-[[:space:]]+\./tests/visual/fixtures/legacy:/work/fixtures([[:space:]]|$)' <<< "$playwright_capture_block")" -eq 1 ]] ||
  fail 'capture Playwright must have exactly one tracked writable fixture mount'

playwright_block="$(compose_service_block playwright)"
! grep -Eq '^[[:space:]]*network_mode:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_block" ||
  fail 'Playwright must not share the host network namespace'
! grep -Eq '^[[:space:]]*ipc:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_block" ||
  fail 'Playwright must not share the host IPC namespace'
grep -Eq '^[[:space:]]*user:[[:space:]]*"?1000:1000"?[[:space:]]*$' <<< "$playwright_block" ||
  fail 'Playwright must run as the non-root repository user'
grep -Eq '^[[:space:]]*read_only:[[:space:]]*true[[:space:]]*$' <<< "$playwright_block" ||
  fail 'Playwright root filesystem must be read-only'
grep -Eq '^[[:space:]]*-[[:space:]]*ALL[[:space:]]*$' <<< "$playwright_block" ||
  fail 'Playwright must drop all Linux capabilities'
grep -Fq 'no-new-privileges:true' <<< "$playwright_block" ||
  fail 'Playwright must disable privilege escalation'
grep -Eq '^[[:space:]]*shm_size:[[:space:]]*' <<< "$playwright_block" ||
  fail 'Playwright must use a private shared-memory allocation'
! grep -Fq 'host.docker.internal:host-gateway' <<< "$playwright_block" ||
  fail 'remote-capable public Playwright must not receive the local host-gateway route'
! grep -Fq 'GOETZ_COMPOSE_HOST_GATEWAY' <<< "$playwright_block" ||
  fail 'remote-capable public Playwright must not enable localhost resolver mapping'
assert_bind_sources_allowlisted playwright \
  ./tests/e2e ./__dev/playwright/public-node-modules ./artifacts/playwright/public
for required_mount in \
  './tests/e2e:/work/e2e:ro' \
  './__dev/playwright/public-node-modules:/work/e2e/node_modules' \
  './artifacts/playwright/public:/work/artifacts'; do
  grep -Fq "$required_mount" <<< "$playwright_block" ||
    fail "Playwright narrow mount is missing: $required_mount"
done

playwright_local_block="$(compose_service_block playwright-local)"
! grep -Eq '^[[:space:]]*network_mode:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_local_block" ||
  fail 'local public Playwright must not share the host network namespace'
! grep -Eq '^[[:space:]]*ipc:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_local_block" ||
  fail 'local public Playwright must not share the host IPC namespace'
grep -Eq '^[[:space:]]*user:[[:space:]]*"?1000:1000"?[[:space:]]*$' <<< "$playwright_local_block" ||
  fail 'local public Playwright must run as the non-root repository user'
grep -Eq '^[[:space:]]*read_only:[[:space:]]*true[[:space:]]*$' <<< "$playwright_local_block" ||
  fail 'local public Playwright root filesystem must be read-only'
grep -Eq '^[[:space:]]*-[[:space:]]*ALL[[:space:]]*$' <<< "$playwright_local_block" ||
  fail 'local public Playwright must drop all Linux capabilities'
grep -Fq 'no-new-privileges:true' <<< "$playwright_local_block" ||
  fail 'local public Playwright must disable privilege escalation'
grep -Eq '^[[:space:]]*shm_size:[[:space:]]*' <<< "$playwright_local_block" ||
  fail 'local public Playwright must use private shared memory'
grep -Fq 'host.docker.internal:host-gateway' <<< "$playwright_local_block" ||
  fail 'local public Playwright is missing the explicit host-gateway route'
grep -Fq 'GOETZ_COMPOSE_HOST_GATEWAY: "1"' <<< "$playwright_local_block" ||
  fail 'local public Playwright does not enable loopback resolver mapping'
assert_bind_sources_allowlisted playwright-local \
  ./tests/e2e ./__dev/playwright/public-node-modules ./artifacts/playwright/public
for required_mount in \
  './tests/e2e:/work/e2e:ro' \
  './__dev/playwright/public-node-modules:/work/e2e/node_modules' \
  './artifacts/playwright/public:/work/artifacts'; do
  grep -Fq "$required_mount" <<< "$playwright_local_block" ||
    fail "local public Playwright narrow mount is missing: $required_mount"
done
! grep -Fq 'GOETZ_AUTH_STATE_PATH' <<< "$playwright_local_block" ||
  fail 'local public Playwright must not receive the authenticated state path'
for auth_only_name in GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD; do
  ! grep -Fq "$auth_only_name" <<< "$playwright_local_block" ||
    fail "local public Playwright statically defines an auth-only setting: $auth_only_name"
done
! grep -Fq './__dev/playwright:' <<< "$playwright_block" ||
  fail 'public Playwright runtime must not expose authenticated session state'
! grep -Fq 'GOETZ_AUTH_STATE_PATH' <<< "$playwright_block" ||
  fail 'public Playwright runtime must not receive the authenticated state path'
for auth_only_name in GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD; do
  ! grep -Fq "$auth_only_name" <<< "$playwright_block" ||
    fail "public Playwright runtime statically defines an auth-only setting: $auth_only_name"
done

playwright_auth_block="$(compose_service_block playwright-auth)"
grep -Eq '^[[:space:]]*user:[[:space:]]*"?1000:1000"?[[:space:]]*$' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must run as the non-root repository user'
grep -Eq '^[[:space:]]*read_only:[[:space:]]*true[[:space:]]*$' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright root filesystem must be read-only'
grep -Eq '^[[:space:]]*-[[:space:]]*ALL[[:space:]]*$' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must drop all Linux capabilities'
grep -Fq 'no-new-privileges:true' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must disable privilege escalation'
! grep -Eq '^[[:space:]]*network_mode:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must not share the host network namespace'
! grep -Eq '^[[:space:]]*ipc:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must not share the host IPC namespace'
grep -Eq '^[[:space:]]*shm_size:[[:space:]]*' <<< "$playwright_auth_block" ||
  fail 'remote-capable authenticated Playwright must use private shared memory'
! grep -Fq 'host.docker.internal:host-gateway' <<< "$playwright_auth_block" ||
  fail 'remote-capable authenticated Playwright must not receive the local host-gateway route'
! grep -Fq 'GOETZ_COMPOSE_HOST_GATEWAY' <<< "$playwright_auth_block" ||
  fail 'remote-capable authenticated Playwright must not enable localhost resolver mapping'
assert_bind_sources_allowlisted playwright-auth \
  ./tests/e2e ./__dev/playwright/auth-node-modules ./__dev/playwright/auth-state \
  ./artifacts/playwright/auth
for required_mount in \
  './tests/e2e:/work/e2e:ro' \
  './__dev/playwright/auth-node-modules:/work/e2e/node_modules' \
  './__dev/playwright/auth-state:/work/state' \
  './artifacts/playwright/auth:/work/artifacts'; do
  grep -Fq "$required_mount" <<< "$playwright_auth_block" ||
    fail "authenticated Playwright narrow mount is missing: $required_mount"
done
grep -Fq 'GOETZ_AUTH_STATE_PATH: /work/state/auth-state.json' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright state path is not scoped to its narrow mount'
! grep -Fq './artifacts/playwright/public:' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must not expose public/remote browser artifacts'
! grep -Fq './artifacts/playwright/auth:' <<< "$playwright_block" ||
  fail 'public/capture Playwright must not expose authenticated browser artifacts'
! grep -Fq './__dev/playwright/auth-node-modules:' <<< "$playwright_block" ||
  fail 'public/capture Playwright must not expose authenticated browser dependencies'
! grep -Fq './__dev/playwright/public-node-modules:' <<< "$playwright_auth_block" ||
  fail 'authenticated Playwright must not expose public/remote browser dependencies'

playwright_auth_local_block="$(compose_service_block playwright-auth-local)"
! grep -Eq '^[[:space:]]*network_mode:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright must not share the host network namespace'
! grep -Eq '^[[:space:]]*ipc:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright must not share the host IPC namespace'
grep -Eq '^[[:space:]]*user:[[:space:]]*"?1000:1000"?[[:space:]]*$' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright must run as the non-root repository user'
grep -Eq '^[[:space:]]*read_only:[[:space:]]*true[[:space:]]*$' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright root filesystem must be read-only'
grep -Eq '^[[:space:]]*-[[:space:]]*ALL[[:space:]]*$' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright must drop all Linux capabilities'
grep -Fq 'no-new-privileges:true' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright must disable privilege escalation'
grep -Eq '^[[:space:]]*shm_size:[[:space:]]*' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright must use private shared memory'
grep -Fq 'host.docker.internal:host-gateway' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright is missing the explicit host-gateway route'
grep -Fq 'GOETZ_COMPOSE_HOST_GATEWAY: "1"' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright does not enable loopback resolver mapping'
assert_bind_sources_allowlisted playwright-auth-local \
  ./tests/e2e ./__dev/playwright/auth-node-modules ./__dev/playwright/auth-state \
  ./artifacts/playwright/auth
for required_mount in \
  './tests/e2e:/work/e2e:ro' \
  './__dev/playwright/auth-node-modules:/work/e2e/node_modules' \
  './__dev/playwright/auth-state:/work/state' \
  './artifacts/playwright/auth:/work/artifacts'; do
  grep -Fq "$required_mount" <<< "$playwright_auth_local_block" ||
    fail "local authenticated Playwright narrow mount is missing: $required_mount"
done
grep -Fq 'GOETZ_AUTH_STATE_PATH: /work/state/auth-state.json' <<< "$playwright_auth_local_block" ||
  fail 'local authenticated Playwright state path is not scoped to its narrow mount'

playwright_installer_block="$(compose_service_block playwright-installer)"
grep -Eq '^[[:space:]]*user:[[:space:]]*"?0:0"?[[:space:]]*$' <<< "$playwright_installer_block" ||
  fail 'isolated Playwright installer must explicitly declare its ephemeral root identity'
! grep -Eq '^[[:space:]]*network_mode:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_installer_block" ||
  fail 'Playwright installer must not share the host network namespace'
! grep -Eq '^[[:space:]]*ipc:[[:space:]]*host([[:space:]]|$)' <<< "$playwright_installer_block" ||
  fail 'Playwright installer must not share the host IPC namespace'
! grep -Fq 'host.docker.internal' <<< "$playwright_installer_block" ||
  fail 'Playwright installer must not receive a local host-gateway route'
! grep -Fq 'GOETZ_' <<< "$playwright_installer_block" ||
  fail 'Playwright installer must not receive browser targets, credentials, or state settings'
assert_bind_sources_allowlisted playwright-installer ./tests/e2e ./__dev/playwright/public-node-modules
grep -Fq './tests/e2e:/work/e2e:ro' <<< "$playwright_installer_block" ||
  fail 'Playwright installer must receive only the read-only E2E dependency tree'
grep -Fq './__dev/playwright/public-node-modules:/work/e2e/node_modules:ro' <<< "$playwright_installer_block" ||
  fail 'Playwright installer must read only the isolated public dependency tree'

node_block="$(compose_service_block node)"
assert_bind_sources_allowlisted node ./wp-content/themes/goetz-legal ./wp-content/plugins/goetz-site
grep -Fq './wp-content/themes/goetz-legal:/work/theme' <<< "$node_block" ||
  fail 'Node must expose only the Goetz theme at /work/theme'
grep -Fq './wp-content/plugins/goetz-site:/work/site' <<< "$node_block" ||
  fail 'Node must expose only the Goetz site plugin at /work/site'

wpcli_block="$(compose_service_block wpcli)"
assert_bind_sources_allowlisted wpcli wordpress_core ./wp-content ./tests
grep -Fq './tests:/app/tests:ro' <<< "$wpcli_block" ||
  fail 'WP-CLI must expose only the read-only test fixtures under /app'

composer_block="$(compose_service_block composer)"
assert_bind_sources_allowlisted composer \
  ./composer.json ./composer.lock ./phpunit.xml.dist ./vendor ./tests/phpunit \
  ./wp-content/plugins ./wp-content/themes/goetz-legal
for required_mount in \
  './composer.json:/work/composer.json:ro' \
  './composer.lock:/work/composer.lock:ro' \
  './phpunit.xml.dist:/work/phpunit.xml.dist:ro' \
  './vendor:/work/vendor' \
  './tests/phpunit:/work/tests/phpunit:ro' \
  './wp-content/plugins:/work/wp-content/plugins' \
  './wp-content/themes/goetz-legal:/work/wp-content/themes/goetz-legal'; do
  grep -Fq "$required_mount" <<< "$composer_block" ||
    fail "Composer narrow mount is missing: $required_mount"
done

for service in playwright playwright-local playwright-auth playwright-auth-local playwright-capture playwright-installer node wpcli composer; do
  service_block="$(compose_service_block "$service")"
  ! grep -Eq '^[[:space:]]*-[[:space:]]+\.:/' <<< "$service_block" ||
    fail "$service must not bind-mount the repository root"
  ! grep -Fq '.env:' <<< "$service_block" ||
    fail "$service must not bind-mount the root .env"
done

auth_helper_fixture="$fixture/auth-helper"
mkdir -p "$auth_helper_fixture"
node --input-type=module - "$root/tests/e2e/helpers/auth-state.mjs" "$auth_helper_fixture" <<'NODE'
import { readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [modulePath, fixturePath] = process.argv.slice(2);
const { cleanupAuthState, prepareAuthState, writePrivateStateAtomic } =
  await import(pathToFileURL(modulePath).href);
const statePath = path.join(fixturePath, 'nested', 'auth-state.json');
await prepareAuthState(statePath);
if ((await stat(path.dirname(statePath))).mode.toString(8).slice(-3) !== '700') {
  throw new Error('auth state directory mode is not 0700');
}
await writeFile(statePath, '{"stale":true}\n', { mode: 0o644 });
await prepareAuthState(statePath);
try {
  await stat(statePath);
  throw new Error('prepareAuthState did not delete stale state');
} catch (error) {
  if (error.code !== 'ENOENT') throw error;
}
await writePrivateStateAtomic(statePath, async (temporaryPath) => {
  if ((await stat(temporaryPath)).mode.toString(8).slice(-3) !== '600') {
    throw new Error('auth state temporary file mode is not 0600 before writing');
  }
  await writeFile(temporaryPath, '{"synthetic":"private"}\n');
});
if ((await stat(statePath)).mode.toString(8).slice(-3) !== '600') {
  throw new Error('auth state file mode is not 0600');
}
if ((await readdir(path.dirname(statePath))).some((entry) => entry.startsWith('auth-state.json.tmp.'))) {
  throw new Error('atomic auth state temporary file persisted');
}
await cleanupAuthState(statePath);
try {
  await stat(statePath);
  throw new Error('cleanupAuthState did not delete state');
} catch (error) {
  if (error.code !== 'ENOENT') throw error;
}
NODE

node tests/contracts/auth-login-security.mjs
grep -Fq 'runAuthenticatedSetup' tests/e2e/global-setup.ts ||
  fail 'authenticated Playwright global setup does not use the tested lifecycle orchestrator'
grep -Fq 'guardedWordPressLogin' tests/e2e/helpers/auth-setup.mjs ||
  fail 'authenticated lifecycle orchestrator does not use the guarded login helper'

[[ -f tests/e2e/helpers/browser.mjs ]] ||
  fail 'Playwright local WordPress browser routing helper is missing'
grep -Fq 'host.docker.internal' tests/e2e/helpers/browser.mjs ||
  fail 'Playwright browser routing does not target the explicit Compose host gateway'
grep -Fq -- '--host-resolver-rules=' tests/e2e/helpers/browser.mjs ||
  fail 'Playwright browser routing does not preserve the canonical localhost URL'
node --input-type=module - "$root/tests/e2e/helpers/browser.mjs" <<'NODE'
import { pathToFileURL } from 'node:url';

const { isLoopbackURL, wordpressLaunchOptions } =
  await import(pathToFileURL(process.argv[2]).href);
for (const localURL of [
  'http://localhost:8080',
  'http://127.0.0.1:8080',
  'http://[::1]:8080',
]) {
  if (!isLoopbackURL(localURL)) {
    throw new Error(`browser loopback classifier rejected: ${localURL}`);
  }
  if (!isLoopbackURL(new URL(localURL))) {
    throw new Error(`browser URL-object loopback classifier rejected: ${localURL}`);
  }
  const localOptions = wordpressLaunchOptions(localURL, true);
  if (!localOptions.args?.some((argument) => argument.includes('host.docker.internal'))) {
    throw new Error('loopback Compose URL did not receive the explicit host-gateway route');
  }
}
for (const remoteURL of ['https://goetzlegal.com', 'https://example.invalid']) {
  if (isLoopbackURL(remoteURL)) {
    throw new Error(`browser loopback classifier accepted remote URL: ${remoteURL}`);
  }
  if (isLoopbackURL(new URL(remoteURL))) {
    throw new Error(`browser URL-object loopback classifier accepted remote URL: ${remoteURL}`);
  }
  const remoteOptions = wordpressLaunchOptions(remoteURL, true);
  if (remoteOptions.args?.some((argument) => argument.includes('host.docker.internal'))) {
    throw new Error(`remote URL received a host-gateway resolver rule: ${remoteURL}`);
  }
}
NODE
grep -Fq 'isLoopbackURL(baseURL)' tests/e2e/global-setup.ts ||
  fail 'authenticated global setup does not use the shared browser loopback classifier'
for playwright_config in \
  tests/e2e/playwright.config.ts \
  tests/e2e/playwright.public.config.ts; do
  grep -Fq 'GOETZ_ARTIFACT_DIR' "$playwright_config" ||
    fail "Playwright config does not use the narrow artifact mount: $playwright_config"
  grep -Fq 'wordpressLaunchOptions(baseURL)' "$playwright_config" ||
    fail "Playwright config does not conditionally use the local WordPress bridge route: $playwright_config"
done
grep -Fq 'GOETZ_REFERENCE_URL' tests/e2e/playwright.capture.config.ts ||
  fail 'capture Playwright config does not use its dedicated reference target'
grep -Fq 'GOETZ_ARTIFACT_DIR' tests/e2e/playwright.capture.config.ts ||
  fail 'capture Playwright config does not use the narrow capture artifact mount'
grep -Fq 'GOETZ_AUTH_STATE_PATH' tests/e2e/playwright.config.ts ||
  fail 'authenticated Playwright config does not use the narrow state mount'
for auth_state_consumer in \
  tests/e2e/playwright.config.ts \
  tests/e2e/global-setup.ts \
  tests/e2e/global-teardown.ts; do
  grep -Fq '../../__dev/playwright/auth-state/auth-state.json' "$auth_state_consumer" ||
    fail "direct authenticated Playwright fallback is outside the isolated state directory: $auth_state_consumer"
done
grep -Fq "globalTeardown: './global-teardown.ts'" tests/e2e/playwright.config.ts ||
  fail 'authenticated Playwright config does not clean session state after direct test runs'
[[ -f tests/e2e/global-teardown.ts ]] ||
  fail 'authenticated Playwright global teardown is missing'
grep -Fq 'cleanupAuthState' tests/e2e/global-teardown.ts ||
  fail 'authenticated Playwright global teardown does not delete session state'
for auth_state_function in prepareAuthState writePrivateStateAtomic cleanupAuthState; do
  grep -Fq "$auth_state_function" tests/e2e/helpers/auth-setup.mjs ||
    fail "authenticated Playwright lifecycle does not use $auth_state_function"
done
grep -Fq 'launchOptions' tests/e2e/global-setup.ts ||
  fail 'authenticated Playwright setup does not preserve project browser routing options'

dispatcher_without_shift="$(awk '
  /^case "\$\{1:-help\}" in$/ { in_dispatcher = 1; next }
  in_dispatcher && /^esac$/ { exit }
  in_dispatcher && /^  [[:alnum:]][[:alnum:]_:.-]*\)/ && $0 !~ /\)[[:space:]]*shift;/ { print }
' manager.sh)"
[[ -z "$dispatcher_without_shift" ]] ||
  fail "manager dispatcher branches must shift then quote-forward arguments: $dispatcher_without_shift"

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  /bin/bash "$fixture/manager.sh" logs 'service with spaces'
grep -Fq '<logs> <-f> <service with spaces>' "$fixture/bin/docker-record.2" ||
  fail 'logs dispatcher did not preserve a service argument containing spaces'

for focused_command in 'logs one two' 'shell unexpected' 'db unexpected' 'db:export one two' 'migrate:scan unexpected' 'migrate:import unexpected'; do
  reset_fake_docker
  read -r -a focused_arguments <<< "$focused_command"
  if /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    /bin/bash "$fixture/manager.sh" "${focused_arguments[@]}" >/dev/null 2>&1; then
    fail "focused dispatcher validation unexpectedly accepted arguments: $focused_command"
  fi
  [[ ! -e "$fixture/bin/docker-record" ]] ||
    fail "focused dispatcher validation invoked Docker before rejecting arguments: $focused_command"
done

reset_fake_docker
/usr/bin/env -i \
  HOME="$fixture/home" \
  PATH="$fixture/bin:/usr/bin:/bin" \
  /bin/bash "$fixture/manager.sh" site:build >/dev/null ||
  fail 'site:build did not gracefully handle the pre-block-source repository state'
[[ ! -e "$fixture/bin/docker-record" ]] ||
  fail 'site:build invoked Docker even though the site block entrypoint is absent'

new_env_fixture="$fixture/new-env"
mkdir -p "$new_env_fixture"
cp manager.sh .env.example "$new_env_fixture/"
(
  umask 0022
  /usr/bin/env -i \
    HOME="$fixture/home" \
    PATH="$fixture/bin:/usr/bin:/bin" \
    SSH_KEY_PW=never-forward-new-env-ssh-value \
    /bin/bash "$new_env_fixture/manager.sh" help >/dev/null
)
[[ "$(stat -c '%a' "$new_env_fixture/.env")" == '600' ]] || fail 'new synthetic .env was not created with mode 600'

unsafe_repository_script="$fixture/unsafe-release.sh"
cat > "$unsafe_repository_script" <<'UNSAFE_RELEASE'
#!/usr/bin/env bash
set -euo pipefail
site_root='/www/example/public'
plugin_root="deploy@example.invalid:${site_root}/wp-content/plugins"
rsync -az --delete /tmp/source/ "${plugin_root}/"
UNSAFE_RELEASE

safe_repository_script="$fixture/safe-release.sh"
cat > "$safe_repository_script" <<'SAFE_RELEASE'
#!/usr/bin/env bash
set -euo pipefail
plugin_target='deploy@example.invalid:/www/example/public/wp-content/plugins/goetz-site/'
rsync -az /tmp/source/ "$plugin_target"
SAFE_RELEASE

if scan_repository_deployment_scripts "$unsafe_repository_script" >/dev/null 2>&1; then
  fail 'variable-built repository rsync --delete fixture passed the zero-delete scanner'
fi
scan_repository_deployment_scripts "$safe_repository_script" || fail 'safe no-delete repository rsync fixture failed the scanner'

rsync_bin="$fixture/rsync-bin"
mkdir -p "$rsync_bin"
cat > "$rsync_bin/rsync" <<'RSYNC'
#!/usr/bin/env bash
set -euo pipefail
: "${GOETZ_RSYNC_RECORD:?}"
printf '%s\0' "$@" > "$GOETZ_RSYNC_RECORD"
RSYNC
chmod 700 "$rsync_bin/rsync"

record_rsync() {
  local record_path="$1"
  shift
  GOETZ_RSYNC_RECORD="$record_path" PATH="$rsync_bin:/usr/bin:/bin" "$@"
}

site_root='/www/example/public'
variable_plugin_record="$fixture/rsync-variable-plugin.record"
record_rsync "$variable_plugin_record" /bin/bash -c '
  site_root=$1
  plugin_root="deploy@example.invalid:${site_root}/wp-content/plugins"
  rsync -az --delete /tmp/source/ "${plugin_root}/"
' _ "$site_root"

unquoted_mu_record="$fixture/rsync-unquoted-mu.record"
record_rsync "$unquoted_mu_record" /bin/bash -c \
  'rsync -az --delete /tmp/source/ deploy@example.invalid:/www/example/public/wp-content/mu-plugins/'

core_record="$fixture/rsync-core.record"
record_rsync "$core_record" /bin/bash -c \
  'rsync -az --delete-delay /tmp/source/ deploy@example.invalid:/www/example/public/'

uploads_record="$fixture/rsync-uploads.record"
record_rsync "$uploads_record" /bin/bash -c \
  'rsync -az --delete-after /tmp/source/ deploy@example.invalid:/www/example/public/wp-content/uploads/'

safe_plugin_record="$fixture/rsync-safe-plugin.record"
record_rsync "$safe_plugin_record" /bin/bash -c \
  'rsync -az --delete /tmp/source/ deploy@example.invalid:/www/example/public/wp-content/plugins/goetz-site/'

unsafe_descendant_destinations=(
  'deploy@example.invalid:/www/example/public/wp-content/uploads/2026/07/'
  'deploy@example.invalid:/www/example/public/wp-content/mu-plugins/kinsta/cache/'
  'deploy@example.invalid:/www/example/public/wp-admin/'
  'deploy@example.invalid:/www/example/public/wp-admin/css/'
  'deploy@example.invalid:/www/example/public/wp-includes/'
  'deploy@example.invalid:/www/example/public/wp-includes/blocks/'
  'deploy@example.invalid:/www/example/public/wp-content/plugins/unapproved-plugin/'
  'deploy@example.invalid:/www/example/public/wp-content/plugins/unapproved-plugin/includes/'
)
unsafe_descendant_records=()
for index in "${!unsafe_descendant_destinations[@]}"; do
  descendant_record="$fixture/rsync-unsafe-descendant-${index}.record"
  record_rsync "$descendant_record" /bin/bash -c \
    'rsync -az --delete /tmp/source/ "$1"' _ "${unsafe_descendant_destinations[$index]}"
  unsafe_descendant_records+=("$descendant_record")
done

allowed_plugin_names=(goetz-site goetz-migration wordpress-seo wpforms-lite)
allowed_plugin_records=()
for plugin_name in "${allowed_plugin_names[@]}"; do
  allowed_record="$fixture/rsync-allowed-${plugin_name}.record"
  record_rsync "$allowed_record" /bin/bash -c \
    'rsync -az --delete /tmp/source/ "$1"' _ \
    "deploy@example.invalid:/www/example/public/wp-content/plugins/${plugin_name}/"
  allowed_plugin_records+=("$allowed_record")
done

declare -F rsync_delete_is_safe >/dev/null || fail 'resolved rsync deletion guard is missing'
if rsync_delete_is_safe "$variable_plugin_record" "$site_root"; then
  fail 'variable-built plugin-root --delete destination was accepted'
fi
if rsync_delete_is_safe "$unquoted_mu_record" "$site_root"; then
  fail 'unquoted MU-plugin-root --delete destination was accepted'
fi
if rsync_delete_is_safe "$core_record" "$site_root"; then
  fail 'WordPress-root --delete destination was accepted'
fi
if rsync_delete_is_safe "$uploads_record" "$site_root"; then
  fail 'uploads-root --delete destination was accepted'
fi
for index in "${!unsafe_descendant_records[@]}"; do
  if rsync_delete_is_safe "${unsafe_descendant_records[$index]}" "$site_root"; then
    fail "unsafe core/plugin/upload descendant was accepted: ${unsafe_descendant_destinations[$index]}"
  fi
done
rsync_delete_is_safe "$safe_plugin_record" "$site_root" || fail 'explicit named-plugin --delete destination was rejected'
for index in "${!allowed_plugin_records[@]}"; do
  rsync_delete_is_safe "${allowed_plugin_records[$index]}" "$site_root" ||
    fail "allowlisted named-plugin destination was rejected: ${allowed_plugin_names[$index]}"
done

printf 'repository-release: PASS\n'
