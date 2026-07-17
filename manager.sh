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
unset GOETZ_BASE_URL GOETZ_EXPECT_ORIGIN GOETZ_EXPECT_PRODUCTION
unset GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD
unset SSH_KEY_PW
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_PATH=/var/www/html

if [[ ! -f "${ROOT_DIR}/.env" ]]; then
  (
    umask 077
    cp "${ROOT_DIR}/.env.example" "${ROOT_DIR}/.env"
  )
fi
chmod 600 "${ROOT_DIR}/.env"

cd "${ROOT_DIR}"
set +a
# shellcheck disable=SC1091
source "${ROOT_DIR}/.env"
unset SSH_KEY_PW

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
    GOETZ_E2E_ALLOW_REMOTE GOETZ_E2E_USER GOETZ_E2E_PASSWORD; do
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
  need_docker
  compose stop
}

install_locked_dependencies() {
  need_docker
  compose run --rm -w /app composer composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
  compose run --rm -w /app/wp-content/themes/goetz-legal composer composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress
}

build_locked_theme() {
  need_docker
  compose run --rm -w /app/wp-content/themes/goetz-legal node npm ci
  compose run --rm -w /app/wp-content/themes/goetz-legal node npm run build
}

build_locked_site() {
  need_docker
  compose run --rm -w /app/wp-content/plugins/goetz-site node npm ci
  compose run --rm -w /app/wp-content/plugins/goetz-site node npm run build
}

deps_install() {
  (( $# == 0 )) || {
    echo 'deps:install does not accept additional arguments.' >&2
    return 2
  }

  install_locked_dependencies
  compose run --rm -w /app/wp-content/themes/goetz-legal node npm ci
  compose run --rm -w /app/wp-content/plugins/goetz-site node npm ci
}

install_site() {
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
  compose run --rm --service-ports -w /app/wp-content/themes/goetz-legal node npm ci
  compose run --rm --service-ports -w /app/wp-content/themes/goetz-legal node npm run dev -- "$@"
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
  compose run --rm -w /app composer composer install --prefer-dist --no-interaction --no-progress
  compose run --rm -w /app composer vendor/bin/phpunit "$@"
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

  compose run --rm -w /app/wp-content/plugins/goetz-site node npm ci
  compose run --rm -w /app/wp-content/plugins/goetz-site node npm run test:unit -- "${test_args[@]}"
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

  install_site
  local script
  local ran=0
  while IFS= read -r -d '' script; do
    ran=1
    wp eval-file "/var/www/html/${script}"
  done < <(find wp-content/plugins/goetz-site/tests/php -type f -name '*.php' -print0 2>/dev/null | sort -z)

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
  compose run --rm -w /app/tests/e2e playwright npm ci
  compose run --rm -w /app/tests/e2e playwright npx playwright install --with-deps chromium
}

is_local_test_url() {
  local url="$1"
  [[ "$url" =~ ^https?://(localhost|127\.0\.0\.1|\[::1\])([:/]|$) ]]
}

run_playwright() {
  local script="$1"
  local authenticated="$2"
  shift 2

  need_docker

  local GOETZ_BASE_URL
  if [[ -n "$CALLER_GOETZ_BASE_URL_SET" ]]; then
    GOETZ_BASE_URL="$CALLER_GOETZ_BASE_URL"
  else
    GOETZ_BASE_URL="${WP_URL:-http://localhost:${WP_PORT:-8080}}"
  fi

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
    if ! is_local_test_url "$GOETZ_BASE_URL"; then
      [[ -n "$CALLER_GOETZ_E2E_ALLOW_REMOTE_SET" && "$CALLER_GOETZ_E2E_ALLOW_REMOTE" == '1' ]] || {
        echo 'Remote authenticated tests require GOETZ_E2E_ALLOW_REMOTE=1.' >&2
        return 2
      }
    fi

    local GOETZ_E2E_USER
    local GOETZ_E2E_PASSWORD
    if [[ -n "$CALLER_GOETZ_E2E_USER_SET" ]]; then
      GOETZ_E2E_USER="$CALLER_GOETZ_E2E_USER"
    else
      GOETZ_E2E_USER="${WP_ADMIN_USER:-admin}"
    fi
    if [[ -n "$CALLER_GOETZ_E2E_PASSWORD_SET" ]]; then
      GOETZ_E2E_PASSWORD="$CALLER_GOETZ_E2E_PASSWORD"
    else
      GOETZ_E2E_PASSWORD="${WP_ADMIN_PASSWORD:-admin}"
    fi
    environment_args+=(-e GOETZ_E2E_USER -e GOETZ_E2E_PASSWORD)

    if [[ -n "$CALLER_GOETZ_E2E_ALLOW_REMOTE_SET" ]]; then
      local GOETZ_E2E_ALLOW_REMOTE="$CALLER_GOETZ_E2E_ALLOW_REMOTE"
      environment_args+=(-e GOETZ_E2E_ALLOW_REMOTE)
    fi
  fi

  compose run --rm -w /app/tests/e2e playwright npm ci
  compose run --rm -w /app/tests/e2e "${environment_args[@]}" playwright npm run "$script" -- "$@"
}

test_e2e_auth() {
  run_playwright test:auth yes "$@"
}

test_public() {
  run_playwright test:public no "$@"
}

test_capture() {
  run_playwright test:capture no "$@"
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
  test_unit
  test_integration
  test_compat full
  test_e2e
}

migrate_scan() {
  wp plugin activate goetz-migration || true
  wp goetz-migration scan --source="${SOURCE_URL:-https://goetzlegal.com}"
}

migrate_import() {
  wp plugin activate goetz-migration || true
  wp goetz-migration import --source="${SOURCE_URL:-https://goetzlegal.com}"
}

db_export() {
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

case "${1:-help}" in
  start) shift; start "$@" ;;
  stop) shift; stop "$@" ;;
  restart) shift; stop; start "$@" ;;
  compose) shift; need_docker; compose "$@" ;;
  logs) need_docker; compose logs -f "${2:-wordpress}" ;;
  shell) need_docker; compose exec wordpress bash ;;
  wp) shift; wp "$@" ;;
  db) need_docker; compose exec db mariadb -u"${MYSQL_USER:-wordpress}" -p"${MYSQL_PASSWORD:-wordpress}" "${MYSQL_DATABASE:-wordpress}" ;;
  install) shift; install_site "$@" ;;
  deps:install) shift; deps_install "$@" ;;
  db:export) shift; db_export "${1:-}" ;;
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
  test:e2e) shift; test_e2e "$@" ;;
  test:all) shift; test_all "$@" ;;
  migrate:scan) migrate_scan ;;
  migrate:import) migrate_import ;;
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
  test:e2e         Run authenticated and public tests against local WordPress
  test:all         Run all local contracts, unit, integration, compat, and E2E tests
  migrate:scan     Dry-run source discovery/import preview
  migrate:import   Import/update live-site pages and media
MSG
    ;;
esac
