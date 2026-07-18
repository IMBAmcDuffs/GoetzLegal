#!/usr/bin/env bash
set -euo pipefail

# Preserve only explicit browser-test overrides across the non-exporting .env
# load. The values are never printed and are forwarded by name, not argv value.
CALLER_GOETZ_BASE_URL_SET="${GOETZ_BASE_URL+x}"
CALLER_GOETZ_BASE_URL="${GOETZ_BASE_URL-}"
CALLER_GOETZ_EXPECT_ORIGIN_SET="${GOETZ_EXPECT_ORIGIN+x}"
CALLER_GOETZ_EXPECT_ORIGIN="${GOETZ_EXPECT_ORIGIN-}"
CALLER_GOETZ_EXPECT_PRODUCTION_SET="${GOETZ_EXPECT_PRODUCTION+x}"
CALLER_GOETZ_EXPECT_PRODUCTION="${GOETZ_EXPECT_PRODUCTION-}"
CALLER_GOETZ_E2E_ALLOW_REMOTE_SET="${GOETZ_E2E_ALLOW_REMOTE+x}"
CALLER_GOETZ_E2E_ALLOW_REMOTE="${GOETZ_E2E_ALLOW_REMOTE-}"
CALLER_GOETZ_E2E_USER_SET="${GOETZ_E2E_USER+x}"
CALLER_GOETZ_E2E_USER="${GOETZ_E2E_USER-}"
CALLER_GOETZ_E2E_PASSWORD_SET="${GOETZ_E2E_PASSWORD+x}"
CALLER_GOETZ_E2E_PASSWORD="${GOETZ_E2E_PASSWORD-}"
CALLER_GOETZ_REFERENCE_URL_SET="${GOETZ_REFERENCE_URL+x}"
CALLER_GOETZ_REFERENCE_URL="${GOETZ_REFERENCE_URL-}"
CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN_SET="${GOETZ_REFERENCE_EXPECT_ORIGIN+x}"
CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN="${GOETZ_REFERENCE_EXPECT_ORIGIN-}"
CALLER_GOETZ_REFERENCE_ALLOW_OVERRIDE_SET="${GOETZ_REFERENCE_ALLOW_OVERRIDE+x}"
CALLER_GOETZ_REFERENCE_ALLOW_OVERRIDE="${GOETZ_REFERENCE_ALLOW_OVERRIDE-}"
CALLER_SSH_AUTH_SOCK_SET="${SSH_AUTH_SOCK+x}"
CALLER_SSH_AUTH_SOCK="${SSH_AUTH_SOCK-}"
CALLER_RELEASE_HOME="${HOME:-/tmp}"
CALLER_RELEASE_PATH="${PATH:-/usr/local/bin:/usr/bin:/bin}"
unset GOETZ_BASE_URL GOETZ_EXPECT_ORIGIN GOETZ_EXPECT_PRODUCTION
unset GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD
unset GOETZ_REFERENCE_URL GOETZ_REFERENCE_ALLOW_OVERRIDE
unset GOETZ_REFERENCE_EXPECT_ORIGIN GOETZ_REFERENCE_OVERRIDE_APPROVED GOETZ_CAPTURE_MODE
unset SSH_AUTH_SOCK
unset SSH_KEY_PW
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_PATH=/var/www/html
ENV_PATH="${ROOT_DIR}/.env"

require_safe_env_file() {
  [[ -f "$ENV_PATH" && ! -L "$ENV_PATH" ]] || {
    printf 'Refusing an unsafe or redirected .env file: %s\n' "$ENV_PATH" >&2
    return 2
  }
  [[ "$(readlink -f -- "$ENV_PATH")" == "$ENV_PATH" ]] || {
    printf 'Refusing an unsafe or redirected .env file: %s\n' "$ENV_PATH" >&2
    return 2
  }
}

if [[ -e "$ENV_PATH" || -L "$ENV_PATH" ]]; then
  require_safe_env_file
else
  env_tmp="$(
    umask 077
    mktemp "${ROOT_DIR}/.env.create.XXXXXX"
  )"
  cleanup_env_tmp() {
    if [[ -n "${env_tmp:-}" && -f "$env_tmp" && ! -L "$env_tmp" ]] &&
      [[ "$(readlink -f -- "$env_tmp")" == "$env_tmp" ]]; then
      unlink -- "$env_tmp"
    fi
  }
  trap cleanup_env_tmp EXIT
  cp "${ROOT_DIR}/.env.example" "$env_tmp"
  chmod 0600 "$env_tmp"
  if ! ln -- "$env_tmp" "$ENV_PATH"; then
    printf 'Could not create .env without following an existing path: %s\n' "$ENV_PATH" >&2
    exit 2
  fi
  unlink -- "$env_tmp"
  env_tmp=''
  trap - EXIT
fi
require_safe_env_file
chmod 0600 "$ENV_PATH"
require_safe_env_file

cd "${ROOT_DIR}"
set +a
# shellcheck disable=SC1091
source "${ROOT_DIR}/.env"
unset SSH_KEY_PW
unset GOETZ_BASE_URL GOETZ_EXPECT_ORIGIN GOETZ_EXPECT_PRODUCTION
unset GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD
unset GOETZ_REFERENCE_URL GOETZ_REFERENCE_ALLOW_OVERRIDE
unset GOETZ_REFERENCE_EXPECT_ORIGIN GOETZ_REFERENCE_OVERRIDE_APPROVED GOETZ_CAPTURE_MODE
if [[ -n "$CALLER_SSH_AUTH_SOCK_SET" ]]; then
  SSH_AUTH_SOCK="$CALLER_SSH_AUTH_SOCK"
else
  unset SSH_AUTH_SOCK
fi

docker_cli() {
  local -a clean_env=(
    "HOME=${HOME:-/tmp}"
    "PATH=${PATH:-/usr/local/bin:/usr/bin:/bin}"
    'COMPOSE_DISABLE_ENV_FILE=1'
    "COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-goetzlegal}"
    "WP_PORT=${WP_PORT:-8080}"
    "MYSQL_DATABASE=${MYSQL_DATABASE:-wordpress}"
    "MYSQL_USER=${MYSQL_USER:-wordpress}"
    "MYSQL_PASSWORD=${MYSQL_PASSWORD:-wordpress}"
    "MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD:-wordpress}"
    "FETCH_PROXY_URL=${FETCH_PROXY_URL:-}"
    "WORDPRESS_IMAGE=${WORDPRESS_IMAGE:-wordpress:6.9.4-php8.3-apache@sha256:5d2c212561c4b5442ebc4d98933a9cbadcf3dee8888ed3fd9ed44667c27cc905}"
    "WPCLI_IMAGE=${WPCLI_IMAGE:-wordpress:cli-2.12.0-php8.3@sha256:f8aeb68164c6a04f5dcc91da30d8ffa096b0f7fafb7a65f144c2dd62587caca0}"
  )
  local process_variable

  for process_variable in \
    DOCKER_API_VERSION DOCKER_CERT_PATH DOCKER_CONFIG DOCKER_CONTEXT \
    DOCKER_HOST DOCKER_TLS_VERIFY SSH_AUTH_SOCK SSL_CERT_DIR SSL_CERT_FILE \
    TERM TMPDIR XDG_RUNTIME_DIR; do
    if [[ -v "${process_variable}" ]]; then
      clean_env+=("${process_variable}=${!process_variable}")
    fi
  done

  for process_variable in \
    GOETZ_BASE_URL GOETZ_EXPECT_ORIGIN GOETZ_EXPECT_PRODUCTION \
    GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD \
    GOETZ_REFERENCE_URL GOETZ_REFERENCE_EXPECT_ORIGIN \
    GOETZ_REFERENCE_OVERRIDE_APPROVED GOETZ_CAPTURE_MODE; do
    if [[ -v "${process_variable}" ]]; then
      clean_env+=("${process_variable}=${!process_variable}")
    fi
  done

  /usr/bin/env -i "${clean_env[@]}" docker "$@"
}

compose() {
  docker_cli compose --env-file /dev/null "$@"
}

need_docker() {
  if ! command -v docker >/dev/null 2>&1 || ! docker_cli version >/dev/null 2>&1; then
    cat >&2 <<'MSG'
Docker is not available in this shell.

If you are running this inside WSL, enable Docker Desktop WSL integration for
this distro, then rerun the manager command.
MSG
    exit 1
  fi
}

wp() {
  need_docker
  compose exec -T wpcli wp --path="${WP_PATH}" --allow-root "$@"
}

start() {
  (( $# == 0 )) || {
    echo 'start does not accept additional arguments.' >&2
    return 2
  }
  need_docker
  compose up -d db wordpress wpcli
  printf 'WordPress: %s\n' "${WP_URL:-http://localhost:${WP_PORT:-8080}}"
}

wait_for_wordpress_files() {
  need_docker
  for _ in $(seq 1 30); do
    if compose exec -T wordpress test -f "${WP_PATH}/wp-load.php" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done

  echo "Timed out waiting for WordPress core files." >&2
  return 1
}

stop() {
  (( $# == 0 )) || {
    echo 'stop does not accept additional arguments.' >&2
    return 2
  }
  need_docker
  compose stop
}

restart_services() {
  (( $# == 0 )) || {
    echo 'restart does not accept additional arguments.' >&2
    return 2
  }
  stop
  start
}

logs_command() {
  (( $# <= 1 )) || {
    echo 'logs accepts at most one service name.' >&2
    return 2
  }
  need_docker
  compose logs -f "${1:-wordpress}"
}

shell_command() {
  (( $# == 0 )) || {
    echo 'shell does not accept additional arguments.' >&2
    return 2
  }
  need_docker
  compose exec wordpress bash
}

db_shell() {
  (( $# == 0 )) || {
    echo 'db does not accept additional arguments.' >&2
    return 2
  }
  need_docker
  compose exec db mariadb \
    -u"${MYSQL_USER:-wordpress}" \
    -p"${MYSQL_PASSWORD:-wordpress}" \
    "${MYSQL_DATABASE:-wordpress}"
}

install_locked_dependencies() {
  need_docker
  mkdir -p "${ROOT_DIR}/vendor"
  compose run --rm -w /work composer composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
  compose run --rm -w /work/wp-content/themes/goetz-legal composer composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
}

build_locked_theme() {
  need_docker
  compose run --rm -w /work/theme node npm ci
  compose run --rm -w /work/theme node npm run build
}

build_locked_site() {
  if [[ ! -f "${ROOT_DIR}/wp-content/plugins/goetz-site/src/index.js" ]]; then
    echo 'No goetz-site block entrypoint is present yet; skipping the editor asset build.'
    return 0
  fi
  need_docker
  compose run --rm -w /work/site node npm ci
  compose run --rm -w /work/site node npm run build
}

deps_install() {
  (( $# == 0 )) || {
    echo 'deps:install does not accept additional arguments.' >&2
    return 2
  }

  install_locked_dependencies
  compose run --rm -w /work/theme node npm ci
  compose run --rm -w /work/site node npm ci
}

install_site() {
  (( $# == 0 )) || {
    echo 'install does not accept additional arguments.' >&2
    return 2
  }
  start
  wait_for_wordpress_files

  if ! wp core is-installed >/dev/null 2>&1; then
    wp core install \
      --url="${WP_URL:-http://localhost:${WP_PORT:-8080}}" \
      --title="${WP_TITLE:-Goetz & Goetz}" \
      --admin_user="${WP_ADMIN_USER:-admin}" \
      --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
      --admin_email="${WP_ADMIN_EMAIL:-info@goetzlegal.com}" \
      --skip-email
  fi

  install_locked_dependencies
  build_locked_theme
  if [[ -f "${ROOT_DIR}/wp-content/plugins/goetz-site/src/index.js" ]]; then
    build_locked_site
  fi
  wp theme activate goetz-legal
  if [[ -f "${ROOT_DIR}/wp-content/plugins/goetz-site/goetz-site.php" ]]; then
    wp plugin activate goetz-site
  fi
  wp plugin activate goetz-migration || true
  wp plugin activate wordpress-seo wpforms-lite
  wp rewrite structure '/%postname%/' --hard
  wp rewrite flush
}

theme_build() {
  (( $# == 0 )) || {
    echo 'theme:build does not accept additional arguments.' >&2
    return 2
  }
  build_locked_theme
}

theme_dev() {
  need_docker
  compose run --rm --service-ports -w /work/theme node npm ci
  compose run --rm --service-ports -w /work/theme node npm run dev -- "$@"
}

site_build() {
  (( $# == 0 )) || {
    echo 'site:build does not accept additional arguments.' >&2
    return 2
  }
  build_locked_site
}

phpunit_test() {
  need_docker
  mkdir -p "${ROOT_DIR}/vendor"
  compose run --rm -w /work composer composer install --prefer-dist --no-interaction --no-progress
  compose run --rm -w /work composer \
    vendor/bin/phpunit --cache-result-file /work/vendor/.phpunit.result.cache "$@"
}

site_test() {
  need_docker
  local -a test_args=("$@")
  local argument
  local has_run_in_band=0
  for argument in "${test_args[@]}"; do
    [[ "$argument" == '--runInBand' ]] && has_run_in_band=1
  done
  (( has_run_in_band == 1 )) || test_args=(--runInBand "${test_args[@]}")

  compose run --rm -w /work/site node npm ci
  compose run --rm -w /work/site node npm run test:unit -- "${test_args[@]}"
}

test_unit() {
  (( $# == 0 )) || {
    echo 'test:unit does not accept focused runner arguments; use phpunit:test or site:test.' >&2
    return 2
  }
  phpunit_test
  site_test --passWithNoTests
}

test_integration() {
  (( $# == 0 )) || {
    echo 'test:integration does not accept additional arguments.' >&2
    return 2
  }

  local integration_url="${WP_URL:-http://localhost:${WP_PORT:-8080}}"
  is_local_test_url "$integration_url" || {
    echo 'test:integration is local-loopback-only.' >&2
    return 2
  }

  install_site
  local script
  local ran=0
  while IFS= read -r -d '' script; do
    ran=1
    compose exec -T \
      -e GOETZ_ALLOW_MUTATING_TESTS=1 \
      -e WP_ENVIRONMENT_TYPE=local \
      wpcli wp \
      --path="${WP_PATH}" --allow-root eval-file "/var/www/html/${script}"
  done < <(find \
    wp-content/plugins/goetz-site/tests/php \
    wp-content/plugins/goetz-migration/tests \
    -type f -name '*.php' -print0 2>/dev/null | sort -z)

  if (( ran == 0 )); then
    echo 'No WordPress integration scripts are present yet.'
  fi
}

test_compat() {
  local mode="${1:-full}"
  (( $# <= 1 )) || {
    echo 'test:compat accepts only --bootstrap-only.' >&2
    return 2
  }

  if [[ "$mode" == 'full' ]]; then
    install_locked_dependencies
    build_locked_theme
    build_locked_site
  elif [[ "$mode" != '--bootstrap-only' ]]; then
    printf 'Unknown compatibility option: %s\n' "$mode" >&2
    return 2
  fi

  # shellcheck disable=SC1091
  source "${ROOT_DIR}/tests/integration/wp-version-matrix.sh"
  goetz_run_wordpress_version_matrix "$mode"
}

e2e_install() {
  (( $# == 0 )) || {
    echo 'e2e:install does not accept additional arguments.' >&2
    return 2
  }
  need_docker
  prepare_playwright_paths
  compose run --rm -w /work/e2e playwright npm ci
  compose run --rm -w /work/e2e playwright-installer npx playwright install --with-deps chromium
}

canonical_http_origin() {
  local url="$1"
  local scheme
  local authority
  local host
  local port=''
  local port_number

  if [[ "$url" =~ ^([Hh][Tt][Tt][Pp][Ss]?)://([^/?#]+)([/?#].*)?$ ]]; then
    scheme="${BASH_REMATCH[1],,}"
    authority="${BASH_REMATCH[2]}"
  else
    return 1
  fi

  [[ "$authority" != *@* ]] || return 1
  if [[ "$authority" =~ ^\[([0-9A-Fa-f:.]+)\](:([0-9]+))?$ ]]; then
    [[ "${BASH_REMATCH[1],,}" == '::1' ]] || return 1
    host='[::1]'
    port="${BASH_REMATCH[3]-}"
  elif [[ "$authority" =~ ^([A-Za-z0-9._~-]+)(:([0-9]+))?$ ]]; then
    host="${BASH_REMATCH[1],,}"
    port="${BASH_REMATCH[3]-}"
  else
    return 1
  fi

  if [[ -n "$port" ]]; then
    (( ${#port} <= 5 )) || return 1
    port_number=$((10#$port))
    (( port_number >= 1 && port_number <= 65535 )) || return 1
    if [[ ( "$scheme" == 'http' && "$port_number" -eq 80 ) ||
      ( "$scheme" == 'https' && "$port_number" -eq 443 ) ]]; then
      port=''
    else
      port=":${port_number}"
    fi
  fi

  printf '%s://%s%s\n' "$scheme" "$host" "$port"
}

is_local_test_url() {
  local origin
  origin="$(canonical_http_origin "$1")" || return 1
  [[ "$origin" =~ ^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]+)?$ ]]
}

validate_browser_origin_policy() {
  local base_url="$1"
  local authenticated="$2"
  local base_origin
  local expected_origin
  local base_local='no'
  local expected_local='no'

  base_origin="$(canonical_http_origin "$base_url")" || {
    echo 'Browser test URL validation failed.' >&2
    return 2
  }

  [[ -n "$CALLER_GOETZ_EXPECT_ORIGIN_SET" ]] || return 0
  expected_origin="$(canonical_http_origin "$CALLER_GOETZ_EXPECT_ORIGIN")" || {
    echo 'Browser expected-origin validation failed.' >&2
    return 2
  }

  if [[ "$authenticated" == 'yes' ]]; then
    [[ "$expected_origin" == "$base_origin" ]] || {
      echo 'Authenticated browser origins must match.' >&2
      return 2
    }
    return 0
  fi

  is_local_test_url "$base_origin" && base_local='yes'
  is_local_test_url "$expected_origin" && expected_local='yes'
  [[ "$base_local" == "$expected_local" ]] || {
    echo 'Public browser origins must use the same locality.' >&2
    return 2
  }
}

readonly PLAYWRIGHT_WORK_DIR="${ROOT_DIR}/__dev/playwright"
readonly PLAYWRIGHT_STATE_DIR="${PLAYWRIGHT_WORK_DIR}/auth-state"
readonly PLAYWRIGHT_AUTH_STATE="${PLAYWRIGHT_STATE_DIR}/auth-state.json"
readonly PLAYWRIGHT_LEGACY_AUTH_STATE="${PLAYWRIGHT_WORK_DIR}/auth-state.json"
readonly PLAYWRIGHT_AUTH_MODULES="${PLAYWRIGHT_WORK_DIR}/auth-node-modules"
readonly PLAYWRIGHT_PUBLIC_MODULES="${PLAYWRIGHT_WORK_DIR}/public-node-modules"
readonly PLAYWRIGHT_CAPTURE_MODULES="${PLAYWRIGHT_WORK_DIR}/capture-node-modules"
readonly PLAYWRIGHT_AUTH_ARTIFACTS="${ROOT_DIR}/artifacts/playwright/auth"
readonly PLAYWRIGHT_PUBLIC_ARTIFACTS="${ROOT_DIR}/artifacts/playwright/public"
readonly PLAYWRIGHT_CAPTURE_ARTIFACTS="${ROOT_DIR}/artifacts/playwright/capture"
readonly PLAYWRIGHT_REFERENCE_FIXTURES_PARENT="${ROOT_DIR}/tests/visual/fixtures"
readonly PLAYWRIGHT_SETTINGS_SNAPSHOT_SCRIPT='/app/tests/e2e/helpers/site-settings-option.php'

cleanup_playwright_auth_state() {
  rm -f -- "$PLAYWRIGHT_AUTH_STATE" "${PLAYWRIGHT_AUTH_STATE}.tmp."*
  rm -f -- "$PLAYWRIGHT_LEGACY_AUTH_STATE" "${PLAYWRIGHT_LEGACY_AUTH_STATE}.tmp."*
}

snapshot_local_site_settings() {
  local snapshot_file="$1"
  compose exec -T wpcli wp --path="${WP_PATH}" --allow-root \
    eval-file "$PLAYWRIGHT_SETTINGS_SNAPSHOT_SCRIPT" snapshot > "$snapshot_file"
  chmod 0600 "$snapshot_file"
  [[ -s "$snapshot_file" ]] || {
    echo 'The local Site Settings snapshot is empty.' >&2
    return 1
  }
}

restore_local_site_settings() {
  local snapshot_file="$1"
  compose exec -T wpcli wp --path="${WP_PATH}" --allow-root \
    eval-file "$PLAYWRIGHT_SETTINGS_SNAPSHOT_SCRIPT" restore < "$snapshot_file" >/dev/null
}

playwright_directory_error() {
  printf 'Refusing an unsafe Playwright directory path: %s\n' "$1" >&2
  return 2
}

validate_playwright_directory_path() {
  local path="$1"
  local relative
  local current="$ROOT_DIR"
  local component
  local -a components=()

  [[ "$path" == "$ROOT_DIR"/* && -d "$ROOT_DIR" && ! -L "$ROOT_DIR" ]] ||
    playwright_directory_error "$path" || return
  relative="${path#"$ROOT_DIR"/}"
  IFS='/' read -r -a components <<< "$relative"
  (( ${#components[@]} > 0 )) || playwright_directory_error "$path" || return

  for component in "${components[@]}"; do
    [[ -n "$component" && "$component" != '.' && "$component" != '..' ]] ||
      playwright_directory_error "$path" || return
    current="${current}/${component}"
    if [[ -L "$current" || ( -e "$current" && ! -d "$current" ) ]]; then
      playwright_directory_error "$path"
      return
    fi
  done
}

prepare_safe_playwright_directory() {
  local path="$1"
  local mode="$2"
  local relative
  local current="$ROOT_DIR"
  local component
  local -a components=()

  validate_playwright_directory_path "$path"
  relative="${path#"$ROOT_DIR"/}"
  IFS='/' read -r -a components <<< "$relative"
  for component in "${components[@]}"; do
    current="${current}/${component}"
    if [[ ! -e "$current" && ! -L "$current" ]]; then
      if ! mkdir -- "$current"; then
        playwright_directory_error "$path"
        return
      fi
    fi
    validate_playwright_directory_path "$current"
  done
  if [[ "$mode" != '-' ]]; then
    chmod "$mode" "$path"
  fi
  validate_playwright_directory_path "$path"
}

prepare_playwright_paths() {
  local path
  local -a paths=(
    "$PLAYWRIGHT_STATE_DIR"
    "$PLAYWRIGHT_AUTH_MODULES"
    "$PLAYWRIGHT_PUBLIC_MODULES"
    "$PLAYWRIGHT_AUTH_ARTIFACTS"
    "$PLAYWRIGHT_PUBLIC_ARTIFACTS"
  )
  for path in "${paths[@]}"; do
    validate_playwright_directory_path "$path"
  done
  prepare_safe_playwright_directory "$PLAYWRIGHT_STATE_DIR" 0700
  prepare_safe_playwright_directory "$PLAYWRIGHT_AUTH_MODULES" 0700
  prepare_safe_playwright_directory "$PLAYWRIGHT_PUBLIC_MODULES" -
  prepare_safe_playwright_directory "$PLAYWRIGHT_AUTH_ARTIFACTS" 0700
  prepare_safe_playwright_directory "$PLAYWRIGHT_PUBLIC_ARTIFACTS" -
}

prepare_playwright_capture_paths() {
  local path
  local -a paths=(
    "$PLAYWRIGHT_CAPTURE_MODULES"
    "$PLAYWRIGHT_CAPTURE_ARTIFACTS"
    "$PLAYWRIGHT_REFERENCE_FIXTURES_PARENT"
  )
  for path in "${paths[@]}"; do
    validate_playwright_directory_path "$path"
  done
  prepare_safe_playwright_directory "$PLAYWRIGHT_CAPTURE_MODULES" 0700
  prepare_safe_playwright_directory "$PLAYWRIGHT_CAPTURE_ARTIFACTS" 0700
  prepare_safe_playwright_directory "$PLAYWRIGHT_REFERENCE_FIXTURES_PARENT" -
}

invoke_playwright_child() {
  local script="$1"
  local authenticated="$2"
  local playwright_service="$3"
  local base_url="$4"
  local username="$5"
  local password="$6"
  shift 6

  local GOETZ_BASE_URL="$base_url"
  local -a environment_args=(-e GOETZ_BASE_URL)
  if [[ -n "$CALLER_GOETZ_EXPECT_ORIGIN_SET" ]]; then
    local GOETZ_EXPECT_ORIGIN="$CALLER_GOETZ_EXPECT_ORIGIN"
    environment_args+=(-e GOETZ_EXPECT_ORIGIN)
  fi
  if [[ -n "$CALLER_GOETZ_EXPECT_PRODUCTION_SET" ]]; then
    local GOETZ_EXPECT_PRODUCTION="$CALLER_GOETZ_EXPECT_PRODUCTION"
    environment_args+=(-e GOETZ_EXPECT_PRODUCTION)
  fi

  if [[ "$authenticated" == 'yes' ]]; then
    local GOETZ_E2E_USER="$username"
    local GOETZ_E2E_PASSWORD="$password"
    environment_args+=(-e GOETZ_E2E_USER -e GOETZ_E2E_PASSWORD)

    if [[ -n "$CALLER_GOETZ_E2E_ALLOW_REMOTE_SET" ]]; then
      local GOETZ_E2E_ALLOW_REMOTE="$CALLER_GOETZ_E2E_ALLOW_REMOTE"
      environment_args+=(-e GOETZ_E2E_ALLOW_REMOTE)
    fi
  fi

  compose run --rm -w /work/e2e "${environment_args[@]}" "$playwright_service" npm run "$script" -- "$@"
}

validate_remote_verification_origin() {
  local origin
  origin="$(canonical_http_origin "$1")" || {
    echo 'Remote authenticated verification origin validation failed.' >&2
    return 2
  }

  case "$origin" in
    https://goetzgoetz.kinsta.cloud|https://goetzlegal.com) ;;
    *)
      echo 'Remote authenticated verification is restricted to the approved staging and production origins.' >&2
      return 2
      ;;
  esac
}

validate_ephemeral_remote_playwright_args() {
  if (( $# != 1 )) || [[ "$1" != 'production-read-only.spec.ts' ]]; then
    echo 'Ephemeral remote verification requires exactly: production-read-only.spec.ts' >&2
    return 2
  fi
}

generate_remote_verification_credentials() {
  local -n username_output="$1"
  local -n password_output="$2"
  local username_suffix
  local generated_password
  username_suffix="$(/usr/bin/openssl rand -hex 8)" || {
    echo 'Could not generate the temporary verification username.' >&2
    return 1
  }
  generated_password="$(/usr/bin/openssl rand -hex 32)" || {
    echo 'Could not generate the temporary verification password.' >&2
    return 1
  }
  [[ "$username_suffix" =~ ^[a-f0-9]{16}$ && "$generated_password" =~ ^[a-f0-9]{64}$ ]] || {
    echo 'Temporary verification credential generation returned an invalid value.' >&2
    return 1
  }

  username_output="goetz_verify_${username_suffix}"
  password_output="$generated_password"
}

remote_verification_wp() (
  local operation="$1"
  local username="$2"
  local password="${3-}"
  [[ "$username" =~ ^goetz_verify_[a-f0-9]{16}$ ]] || {
    echo 'Temporary verification username validation failed.' >&2
    return 2
  }

  export SSH_AUTH_SOCK="$CALLER_SSH_AUTH_SOCK"
  export KINSTA_SSH_USER KINSTA_SSH_HOST KINSTA_SSH_PORT KINSTA_SITE_PATH KINSTA_KNOWN_HOSTS_FILE
  GOETZ_COMMAND_NAME='remote-auth-verification'
  # shellcheck disable=SC1091
  source "${ROOT_DIR}/scripts/release/common.sh"
  goetz_require_kinsta

  case "$operation" in
    create)
      [[ "$password" =~ ^[a-f0-9]{64}$ ]] || {
        echo 'Temporary verification password validation failed.' >&2
        return 2
      }
      printf '%s\n' "$password" | goetz_ssh \
        wp --path="$GOETZ_EXPECTED_SITE" --no-color \
        user create "$username" "${username}@goetz-verification.invalid" \
        --role=administrator --porcelain --prompt=user_pass
      ;;
    cleanup)
      goetz_ssh bash -s -- "$GOETZ_EXPECTED_SITE" "$username" <<'REMOTE'
# GOETZ_REMOTE_VERIFICATION_USER_CLEANUP
set -euo pipefail
site_path="$1"
username="$2"
[[ "$site_path" == '/www/goetzgoetz_755/public' ]]
[[ "$username" =~ ^goetz_verify_[a-f0-9]{16}$ ]]

matching_users="$(wp --path="$site_path" --no-color user list \
  --login="$username" --format=count)"
[[ "$matching_users" == '0' || "$matching_users" == '1' ]]
if [[ "$matching_users" == '1' ]]; then
  wp --path="$site_path" --no-color user delete "$username" --yes >/dev/null
fi

remaining_users="$(wp --path="$site_path" --no-color user list \
  --login="$username" --format=count)"
[[ "$remaining_users" == '0' ]]
REMOTE
      ;;
    *)
      echo 'Unknown remote verification WP-CLI operation.' >&2
      return 2
      ;;
  esac
)

invoke_remote_authenticated_playwright_child() {
  local script="$1"
  local playwright_service="$2"
  local base_url="$3"
  local username="$4"
  local password="$5"
  shift 5

  local GOETZ_BASE_URL="$base_url"
  local GOETZ_E2E_ALLOW_REMOTE=1
  local -a environment_args=(-e GOETZ_BASE_URL -e GOETZ_E2E_ALLOW_REMOTE)
  if [[ -n "$CALLER_GOETZ_EXPECT_ORIGIN_SET" ]]; then
    local GOETZ_EXPECT_ORIGIN="$CALLER_GOETZ_EXPECT_ORIGIN"
    environment_args+=(-e GOETZ_EXPECT_ORIGIN)
  fi
  if [[ -n "$CALLER_GOETZ_EXPECT_PRODUCTION_SET" ]]; then
    local GOETZ_EXPECT_PRODUCTION="$CALLER_GOETZ_EXPECT_PRODUCTION"
    environment_args+=(-e GOETZ_EXPECT_PRODUCTION)
  fi

  printf '%s\n%s\n' "$username" "$password" | compose run --rm -T -w /work/e2e \
    "${environment_args[@]}" "$playwright_service" /bin/sh -ceu '
      script="$1"
      shift
      IFS= read -r GOETZ_E2E_USER
      IFS= read -r GOETZ_E2E_PASSWORD
      if [ -z "$GOETZ_E2E_USER" ] || [ -z "$GOETZ_E2E_PASSWORD" ]; then
        printf "%s\n" "Authenticated Playwright credentials were not supplied." >&2
        exit 2
      fi
      export GOETZ_E2E_USER GOETZ_E2E_PASSWORD
      exec npm run "$script" -- "$@"
    ' goetz-playwright-auth "$script" "$@"
}

run_playwright() {
  local script="$1"
  local authenticated="$2"
  shift 2

  local base_url
  if [[ -n "$CALLER_GOETZ_BASE_URL_SET" ]]; then
    base_url="$CALLER_GOETZ_BASE_URL"
  else
    base_url="${WP_URL:-http://localhost:${WP_PORT:-8080}}"
  fi

  validate_browser_origin_policy "$base_url" "$authenticated" || return

  local username=''
  local password=''
  local local_test='no'
  local remote_ephemeral='no'
  if is_local_test_url "$base_url"; then
    local_test='yes'
  fi

  if [[ "$authenticated" == 'yes' ]]; then
    if [[ "$local_test" == 'yes' ]]; then
      if [[ -n "$CALLER_GOETZ_E2E_USER_SET" ]]; then
        username="$CALLER_GOETZ_E2E_USER"
      else
        username="${WP_ADMIN_USER:-admin}"
      fi
      if [[ -n "$CALLER_GOETZ_E2E_PASSWORD_SET" ]]; then
        password="$CALLER_GOETZ_E2E_PASSWORD"
      else
        password="${WP_ADMIN_PASSWORD:-admin}"
      fi
    else
      [[ -n "$CALLER_GOETZ_E2E_ALLOW_REMOTE_SET" && "$CALLER_GOETZ_E2E_ALLOW_REMOTE" == '1' ]] || {
        echo 'Remote authenticated tests require GOETZ_E2E_ALLOW_REMOTE=1.' >&2
        return 2
      }
      if [[ -n "$CALLER_GOETZ_E2E_USER_SET" || -n "$CALLER_GOETZ_E2E_PASSWORD_SET" ]]; then
        [[ -n "$CALLER_GOETZ_E2E_USER_SET" && -n "$CALLER_GOETZ_E2E_USER" &&
          -n "$CALLER_GOETZ_E2E_PASSWORD_SET" && -n "$CALLER_GOETZ_E2E_PASSWORD" ]] || {
          echo 'Remote authenticated caller credentials must be supplied as a complete pair.' >&2
          return 2
        }
        username="$CALLER_GOETZ_E2E_USER"
        password="$CALLER_GOETZ_E2E_PASSWORD"
      else
        validate_remote_verification_origin "$base_url"
        validate_ephemeral_remote_playwright_args "$@"
        require_kinsta_config
        remote_ephemeral='yes'
      fi
    fi
  fi

  local playwright_service='playwright'
  if [[ "$authenticated" == 'yes' ]]; then
    if [[ "$local_test" == 'yes' ]]; then
      playwright_service='playwright-auth-local'
    else
      playwright_service='playwright-auth'
    fi
  elif [[ "$local_test" == 'yes' ]]; then
    playwright_service='playwright-local'
  fi

  need_docker
  prepare_playwright_paths
  if [[ "$authenticated" == 'yes' ]]; then
    local authenticated_runner_pid=0
    local authenticated_signal_name=''
    local authenticated_signal_status=0
    forward_authenticated_signal() {
      local signal_name="$1"
      local signal_status="$2"
      authenticated_signal_name="$signal_name"
      authenticated_signal_status="$signal_status"
      if (( authenticated_runner_pid > 0 )); then
        kill -s "$signal_name" -- "-$authenticated_runner_pid" 2>/dev/null || true
      fi
    }
    trap 'forward_authenticated_signal HUP 129' HUP
    trap 'forward_authenticated_signal INT 130' INT
    trap 'forward_authenticated_signal TERM 143' TERM

    local monitor_mode_was_enabled='no'
    [[ "$-" == *m* ]] && monitor_mode_was_enabled='yes'
    if [[ "$monitor_mode_was_enabled" == 'no' ]]; then
      set -m
    fi
    (
      local settings_snapshot=''
      local restore_settings='no'
      local remote_cleanup='no'
      restore_playwright_state() {
        local run_status=$?
        local restore_status=0
        local remote_cleanup_failed='no'
        trap - EXIT HUP INT TERM
        set +e
        if [[ "$remote_cleanup" == 'yes' ]]; then
          if ! remote_verification_wp cleanup "$username" >/dev/null 2>&1; then
            remote_cleanup_failed='yes'
            printf '%s\n' \
              'CRITICAL: temporary remote verification administrator cleanup failed; follow the emergency cleanup runbook immediately.' >&2
          fi
        fi
        if [[ "$restore_settings" == 'yes' ]]; then
          restore_local_site_settings "$settings_snapshot" || restore_status=$?
        fi
        if [[ -n "$settings_snapshot" ]]; then
          rm -f -- "$settings_snapshot"
        fi
        cleanup_playwright_auth_state
        password=''
        username=''
        if [[ "$remote_cleanup_failed" == 'yes' ]]; then
          run_status=70
        elif (( run_status == 0 && restore_status != 0 )); then
          run_status=$restore_status
        fi
        exit "$run_status"
      }
      trap restore_playwright_state EXIT
      trap 'exit 129' HUP
      trap 'exit 130' INT
      trap 'exit 143' TERM
      cleanup_playwright_auth_state
      if [[ "$local_test" == 'yes' ]]; then
        settings_snapshot="$(mktemp "${PLAYWRIGHT_STATE_DIR}/site-settings-option.XXXXXX")"
        snapshot_local_site_settings "$settings_snapshot"
        restore_settings='yes'
      fi
      compose run --rm -w /work/e2e "$playwright_service" npm ci
      if [[ "$local_test" == 'yes' || "$remote_ephemeral" == 'no' ]]; then
        invoke_playwright_child \
          "$script" "$authenticated" "$playwright_service" "$base_url" "$username" "$password" "$@"
      else
        generate_remote_verification_credentials username password
        remote_cleanup='yes'
        remote_verification_wp create "$username" "$password" >/dev/null
        invoke_remote_authenticated_playwright_child \
          "$script" "$playwright_service" "$base_url" "$username" "$password" "$@"
      fi
    ) &
    authenticated_runner_pid=$!
    if [[ "$monitor_mode_was_enabled" == 'no' ]]; then
      set +m
    fi
    if [[ -n "$authenticated_signal_name" ]]; then
      kill -s "$authenticated_signal_name" -- "-$authenticated_runner_pid" 2>/dev/null || true
    fi

    local authenticated_runner_status=0
    if wait "$authenticated_runner_pid"; then
      authenticated_runner_status=0
    else
      authenticated_runner_status=$?
      if kill -0 "$authenticated_runner_pid" 2>/dev/null; then
        if wait "$authenticated_runner_pid"; then
          authenticated_runner_status=0
        else
          authenticated_runner_status=$?
        fi
      fi
    fi
    trap - HUP INT TERM
    if (( authenticated_signal_status != 0 )); then
      return "$authenticated_signal_status"
    fi
    return "$authenticated_runner_status"
  fi

  compose run --rm -w /work/e2e "$playwright_service" npm ci
  invoke_playwright_child \
    "$script" "$authenticated" "$playwright_service" "$base_url" "$username" "$password" "$@"
}

test_e2e_auth() {
  run_playwright test:auth yes "$@"
}

test_public() {
  run_playwright test:public no "$@"
}

visual_compare() {
  (( $# == 0 )) || {
    echo 'visual:compare does not accept additional arguments.' >&2
    return 2
  }

  # Keep visual verification on the same validated base/expected-origin path as
  # every other unauthenticated public test. The Playwright services expose the
  # frozen legacy fixture read-only and write only below artifacts/.
  test_public --grep 'visual comparator contract|visual parity'
}

invoke_capture_child() {
  local mode="$1"
  local reference_url="$2"
  local expected_origin="$3"
  local override_approved="$4"
  shift 4

  local GOETZ_CAPTURE_MODE="$mode"
  local -a environment_args=(-e GOETZ_CAPTURE_MODE)
  local playwright_service='playwright-capture'
  if [[ "$mode" == 'write' ]]; then
    playwright_service='playwright-capture-write'
    local GOETZ_REFERENCE_URL="$reference_url"
    local GOETZ_REFERENCE_EXPECT_ORIGIN="$expected_origin"
    environment_args+=(-e GOETZ_REFERENCE_URL -e GOETZ_REFERENCE_EXPECT_ORIGIN)
    if [[ "$override_approved" == '1' ]]; then
      local GOETZ_REFERENCE_OVERRIDE_APPROVED=1
      environment_args+=(-e GOETZ_REFERENCE_OVERRIDE_APPROVED)
    fi
  fi

  compose run --rm -w /work/e2e "${environment_args[@]}" \
    "$playwright_service" npm run test:capture -- "$@"
}

validate_capture_test_args() {
  local seen_grep=''
  local seen_list=''

  while (( $# > 0 )); do
    case "$1" in
      --list)
        [[ -z "$seen_list" ]] || {
          echo 'test:capture accepts --list at most once.' >&2
          return 2
        }
        seen_list=1
        shift
        ;;
      --grep)
        [[ -z "$seen_grep" && $# -ge 2 && -n "${2-}" && "${2-}" != --* ]] || {
          echo 'test:capture requires one non-empty --grep value.' >&2
          return 2
        }
        seen_grep=1
        shift 2
        ;;
      --grep=*)
        [[ -z "$seen_grep" && -n "${1#--grep=}" ]] || {
          echo 'test:capture requires one non-empty --grep value.' >&2
          return 2
        }
        seen_grep=1
        shift
        ;;
      *)
        echo 'test:capture accepts only --list and one non-empty --grep value.' >&2
        return 2
        ;;
    esac
  done
}

test_capture() {
  validate_capture_test_args "$@" || return
  need_docker
  prepare_playwright_capture_paths
  compose run --rm -w /work/e2e playwright-capture npm ci
  local GOETZ_CAPTURE_MODE=contract
  invoke_capture_child "$GOETZ_CAPTURE_MODE" '' '' '' "$@"
}

canonical_reference_url() {
  local value="$1"
  local origin

  [[ "$value" =~ ^[Hh][Tt][Tt][Pp][Ss]://[^/?#]+/?$ ]] || return 1
  origin="$(canonical_http_origin "$value")" || return 1
  [[ "$origin" == https://* ]] || return 1
  printf '%s/\n' "$origin"
}

visual_capture_reference() {
  (( $# == 0 )) || {
    echo 'visual:capture-reference does not accept additional arguments.' >&2
    return 2
  }

  local reference_url='https://goetzlegal.com/'
  if [[ -n "$CALLER_GOETZ_REFERENCE_URL_SET" ]]; then
    reference_url="$(canonical_reference_url "$CALLER_GOETZ_REFERENCE_URL")" || {
      echo 'Reference capture URL validation failed.' >&2
      return 2
    }
  fi

  if [[ "$reference_url" != 'https://goetzlegal.com/' ]]; then
    [[ -n "$CALLER_GOETZ_REFERENCE_ALLOW_OVERRIDE_SET" &&
      "$CALLER_GOETZ_REFERENCE_ALLOW_OVERRIDE" == '1' ]] || {
      echo 'A non-default reference requires explicit override approval.' >&2
      return 2
    }
  fi

  local expected_origin="${reference_url%/}"
  local override_approved=''
  if [[ "$reference_url" != 'https://goetzlegal.com/' ]]; then
    [[ -n "$CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN_SET" ]] || {
      echo 'A non-default reference requires an explicit expected origin.' >&2
      return 2
    }
    local caller_expected_url
    caller_expected_url="$(canonical_reference_url "$CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN")" || {
      echo 'Reference expected-origin validation failed.' >&2
      return 2
    }
    [[ "${caller_expected_url%/}" == "$expected_origin" ]] || {
      echo 'Reference URL and expected origin must match.' >&2
      return 2
    }
    override_approved='1'
  elif [[ -n "$CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN_SET" ]]; then
    local caller_default_expected_url
    caller_default_expected_url="$(canonical_reference_url "$CALLER_GOETZ_REFERENCE_EXPECT_ORIGIN")" || {
      echo 'Reference expected-origin validation failed.' >&2
      return 2
    }
    [[ "${caller_default_expected_url%/}" == "$expected_origin" ]] || {
      echo 'Reference URL and expected origin must match.' >&2
      return 2
    }
  fi
  need_docker
  prepare_playwright_capture_paths
  compose run --rm -w /work/e2e playwright-capture npm ci
  local GOETZ_CAPTURE_MODE=write
  invoke_capture_child "$GOETZ_CAPTURE_MODE" "$reference_url" "$expected_origin" "$override_approved"
}

test_e2e() {
  local base_url
  if [[ -n "$CALLER_GOETZ_BASE_URL_SET" ]]; then
    base_url="$CALLER_GOETZ_BASE_URL"
  else
    base_url="${WP_URL:-http://localhost:${WP_PORT:-8080}}"
  fi
  is_local_test_url "$base_url" || {
    echo 'test:e2e is local-only; use focused commands for explicitly opted-in remote checks.' >&2
    return 2
  }
  test_e2e_auth "$@"
  test_public "$@"
}

test_all() {
  (( $# == 0 )) || {
    echo 'test:all does not accept additional arguments.' >&2
    return 2
  }
  bash tests/contracts/repository-release.sh
  bash tests/contracts/remote-auth-verification.sh
  test_unit
  test_integration
  test_compat full
  test_e2e
}

migrate_scan() {
  (( $# == 0 )) || {
    echo 'migrate:scan does not accept additional arguments.' >&2
    return 2
  }
  wp goetz-migration scan --source="${SOURCE_URL:-https://goetzlegal.com}"
}

db_export() {
  (( $# <= 1 )) || {
    echo 'db:export accepts at most one target URL.' >&2
    return 2
  }
  need_docker
  compose up -d db wordpress wpcli >/dev/null
  wait_for_wordpress_files

  if ! wp core is-installed >/dev/null 2>&1; then
    echo "WordPress is not installed; cannot export the database." >&2
    exit 1
  fi

  local target_url="${1:-}"
  local export_dir="${ROOT_DIR}/__dev"
  local timestamp
  timestamp="$(date '+%Y-%m-%d_%H-%M-%S')"
  local output_file="${export_dir}/goetzlegal-db-${timestamp}.sql"

  mkdir -p "${export_dir}"

  if [[ -n "${target_url}" ]]; then
    local local_url
    local_url="$(wp option get siteurl)"
    local target_host
    target_host="$(printf '%s' "${target_url}" | sed -E 's#^https?://##; s#/.*$##; s#[^A-Za-z0-9.-]#-#g')"
    output_file="${export_dir}/goetzlegal-db-${timestamp}-for-${target_host}.sql"

    compose exec -T wpcli wp --path="${WP_PATH}" --allow-root \
      search-replace "${local_url}" "${target_url%/}" --all-tables-with-prefix --export=/tmp/goetz-export.sql
    compose exec -T wpcli cat /tmp/goetz-export.sql > "${output_file}"
    compose exec -T wpcli rm -f /tmp/goetz-export.sql

    printf 'Database exported with URLs rewritten (%s -> %s): %s\n' "${local_url}" "${target_url%/}" "${output_file}"
    return 0
  fi

  compose exec -T wpcli wp --path="${WP_PATH}" --allow-root --quiet db export - --add-drop-table > "${output_file}"

  printf 'Database exported: %s\n' "${output_file}"
}

# Production release commands run outside Docker and receive a deliberately
# small environment. In particular, no release child can re-read .env or
# inherit SSH_KEY_PW, admin credentials, database credentials, proxy settings,
# or unrelated caller variables.
release_command_path() {
  local result='/usr/local/bin:/usr/bin:/bin'
  local command_name command_path command_dir
  for command_name in node npm composer docker; do
    command_path="$(PATH="$CALLER_RELEASE_PATH" command -v "$command_name" 2>/dev/null || true)"
    [[ "$command_path" == /* ]] || continue
    command_dir="${command_path%/*}"
    case ":$result:" in
      *":$command_dir:"*) ;;
      *) result="$command_dir:$result" ;;
    esac
  done
  printf '%s\n' "$result"
}

release_clean_exec() {
  local script="$1"
  shift
  [[ "$script" == "${ROOT_DIR}/scripts/release/"*.sh && -x "$script" ]] || {
    echo "Release script is unavailable: $script" >&2
    return 2
  }
  /usr/bin/env -i \
    "HOME=$CALLER_RELEASE_HOME" \
    "PATH=$(release_command_path)" \
    "$script" "$@"
}

require_kinsta_config() {
  local required_name
  for required_name in \
    KINSTA_SSH_USER KINSTA_SSH_HOST KINSTA_SSH_PORT KINSTA_SITE_PATH KINSTA_KNOWN_HOSTS_FILE; do
    [[ -n "${!required_name:-}" ]] || {
      echo "Missing Kinsta release configuration: $required_name" >&2
      return 2
    }
  done
  [[ "$KINSTA_SSH_USER" == 'goetzgoetz' ]] || { echo 'Unexpected Kinsta SSH user.' >&2; return 2; }
  [[ "$KINSTA_SSH_HOST" == '163.192.209.112' ]] || { echo 'Unexpected Kinsta SSH host.' >&2; return 2; }
  [[ "$KINSTA_SSH_PORT" == '43854' ]] || { echo 'Unexpected Kinsta SSH port.' >&2; return 2; }
  [[ "$KINSTA_SITE_PATH" == '/www/goetzgoetz_755/public' ]] || { echo 'Unexpected Kinsta site path.' >&2; return 2; }
  [[ "$KINSTA_KNOWN_HOSTS_FILE" == /* && "$KINSTA_KNOWN_HOSTS_FILE" != *[[:space:]]* ]] || {
    echo 'KINSTA_KNOWN_HOSTS_FILE must be an absolute path without whitespace.' >&2
    return 2
  }
  [[ -f "$KINSTA_KNOWN_HOSTS_FILE" && ! -L "$KINSTA_KNOWN_HOSTS_FILE" && -s "$KINSTA_KNOWN_HOSTS_FILE" ]] || {
    echo 'The pinned Kinsta known-host file is missing, empty, or a symlink.' >&2
    return 2
  }
  [[ -n "$CALLER_SSH_AUTH_SOCK_SET" && -n "$CALLER_SSH_AUTH_SOCK" && -e "$CALLER_SSH_AUTH_SOCK" ]] || {
    echo 'An already-unlocked isolated SSH_AUTH_SOCK is required.' >&2
    return 2
  }
}

release_remote_exec() {
  local script="$1"
  shift
  require_kinsta_config
  [[ "$script" == "${ROOT_DIR}/scripts/release/"*.sh && -x "$script" ]] || {
    echo "Release script is unavailable: $script" >&2
    return 2
  }
  /usr/bin/env -i \
    "HOME=$CALLER_RELEASE_HOME" \
    "PATH=$(release_command_path)" \
    "SSH_AUTH_SOCK=$CALLER_SSH_AUTH_SOCK" \
    "KINSTA_SSH_USER=$KINSTA_SSH_USER" \
    "KINSTA_SSH_HOST=$KINSTA_SSH_HOST" \
    "KINSTA_SSH_PORT=$KINSTA_SSH_PORT" \
    "KINSTA_SITE_PATH=$KINSTA_SITE_PATH" \
    "KINSTA_KNOWN_HOSTS_FILE=$KINSTA_KNOWN_HOSTS_FILE" \
    "$script" "$@"
}

release_build() { release_clean_exec "$ROOT_DIR/scripts/release/build.sh" "$@"; }
release_verify() { release_clean_exec "$ROOT_DIR/scripts/release/verify.sh" "$@"; }
remote_backup() { release_remote_exec "$ROOT_DIR/scripts/release/remote-backup.sh" "$@"; }
remote_deploy() { release_remote_exec "$ROOT_DIR/scripts/release/remote-apply.sh" "$@"; }
remote_cutover() { release_remote_exec "$ROOT_DIR/scripts/release/cutover.sh" "$@"; }
remote_rollback() { release_remote_exec "$ROOT_DIR/scripts/release/rollback.sh" "$@"; }
verify_remote() { release_remote_exec "$ROOT_DIR/scripts/release/verify-remote.sh" "$@"; }

case "${1:-help}" in
  start) shift; start "$@" ;;
  stop) shift; stop "$@" ;;
  restart) shift; restart_services "$@" ;;
  compose) shift; need_docker; compose "$@" ;;
  logs) shift; logs_command "$@" ;;
  shell) shift; shell_command "$@" ;;
  wp) shift; wp "$@" ;;
  db) shift; db_shell "$@" ;;
  install) shift; install_site "$@" ;;
  deps:install) shift; deps_install "$@" ;;
  db:export) shift; db_export "$@" ;;
  theme:dev) shift; theme_dev "$@" ;;
  theme:build) shift; theme_build "$@" ;;
  site:build) shift; site_build "$@" ;;
  phpunit:test) shift; phpunit_test "$@" ;;
  site:test) shift; site_test "$@" ;;
  test:unit) shift; test_unit "$@" ;;
  test:integration) shift; test_integration "$@" ;;
  test:compat) shift; test_compat "$@" ;;
  e2e:install) shift; e2e_install "$@" ;;
  test:e2e:auth) shift; test_e2e_auth "$@" ;;
  test:public) shift; test_public "$@" ;;
  test:capture) shift; test_capture "$@" ;;
  visual:compare) shift; visual_compare "$@" ;;
  visual:capture-reference) shift; visual_capture_reference "$@" ;;
  test:e2e) shift; test_e2e "$@" ;;
  test:all) shift; test_all "$@" ;;
  migrate:scan) shift; migrate_scan "$@" ;;
  release:build) shift; release_build "$@" ;;
  release:verify) shift; release_verify "$@" ;;
  remote:backup) shift; remote_backup "$@" ;;
  remote:deploy) shift; remote_deploy "$@" ;;
  remote:cutover) shift; remote_cutover "$@" ;;
  remote:rollback) shift; remote_rollback "$@" ;;
  verify:remote) shift; verify_remote "$@" ;;
  *)
    cat <<'MSG'
Usage: ./manager.sh <command>

Commands:
  compose <args>   Run Docker Compose with only approved environment substitutions
  start            Start local WordPress services
  stop             Stop local services
  restart          Restart local services
  logs [service]   Tail logs, default: wordpress
  shell            Open a shell in the WordPress container
  wp <args>        Run WP-CLI against the local site
  db               Open the local database shell
  db:export [url]  Export DB to __dev (timestamped); pass a URL to rewrite site URLs for that host
  install          Install/configure WordPress, theme, Yoast, WPForms
  deps:install     Install locked production PHP and Node dependencies
  theme:dev        Start Vite dev server for the Tailpress theme
  theme:build      Build locked production theme assets
  site:build       Build locked goetz-site editor assets
  phpunit:test     Run PHPUnit; accepts focused PHPUnit arguments
  site:test        Run Jest in-band; accepts focused Jest arguments
  test:unit        Run all PHP and JavaScript unit tests
  test:integration Run WordPress integration scripts
  test:compat      Run WordPress 6.9.4/7.0.1 compatibility; accepts --bootstrap-only
  e2e:install      Install locked browser dependencies and Chromium
  test:e2e:auth    Run authenticated Playwright tests
  test:public      Run unauthenticated frontend/SEO/accessibility/visual tests
  test:capture     Run only the read-only legacy capture tests
  visual:compare   Compare the homepage with the immutable legacy fixture
  visual:capture-reference  Write the one-time immutable legacy homepage baseline
  test:e2e         Run authenticated and public tests against local WordPress
  test:all         Run all local contracts, unit, integration, compat, and E2E tests
  migrate:scan     Dry-run source discovery/create-only preview
  release:build    Build the exact clean pushed commit into a checksum payload
  release:verify   Verify a local release payload and strict metadata schema
  remote:backup    Create/download a coupled checksum Kinsta rollback packet
  remote:deploy    Upload and apply one allowlisted release with in-lock recovery
  remote:cutover   Dry-run/apply the exact staging-to-production URL cutover
  remote:rollback  Dry-run/apply one verified coupled rollback packet
  verify:remote    Verify release digest, runtime state, logs, dumps, and routes
MSG
    ;;
esac
