#!/usr/bin/env bash
set -euo pipefail

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
    "WORDPRESS_IMAGE=${WORDPRESS_IMAGE:-wordpress:php8.3-apache}"
    "WPCLI_IMAGE=${WPCLI_IMAGE:-wordpress:cli-php8.3}"
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
  echo 'Locked dependency installation is introduced in Task 2.' >&2
  return 1
}

build_locked_theme() {
  echo 'Locked theme builds are introduced in Task 2.' >&2
  return 1
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
  wp theme activate goetz-legal
  wp plugin activate goetz-migration || true
  wp plugin activate wordpress-seo wpforms-lite
  wp rewrite structure '/%postname%/' --hard
  wp rewrite flush
}

theme_build() {
  build_locked_theme
}

theme_dev() {
  echo 'Locked theme development dependencies are introduced in Task 2.' >&2
  return 1
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
  start) start ;;
  stop) stop ;;
  restart) stop; start ;;
  compose) shift; need_docker; compose "$@" ;;
  logs) need_docker; compose logs -f "${2:-wordpress}" ;;
  shell) need_docker; compose exec wordpress bash ;;
  wp) shift; wp "$@" ;;
  db) need_docker; compose exec db mariadb -u"${MYSQL_USER:-wordpress}" -p"${MYSQL_PASSWORD:-wordpress}" "${MYSQL_DATABASE:-wordpress}" ;;
  install) install_site ;;
  db:export) shift; db_export "${1:-}" ;;
  theme:dev) theme_dev ;;
  theme:build) theme_build ;;
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
  theme:dev        Start Vite dev server for the Tailpress theme
  theme:build      Install theme deps and build production assets
  migrate:scan     Dry-run source discovery/import preview
  migrate:import   Import/update live-site pages and media
MSG
    ;;
esac
