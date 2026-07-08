#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE=(docker compose --env-file "${ROOT_DIR}/.env")
WP_PATH=/var/www/html

if [[ ! -f "${ROOT_DIR}/.env" ]]; then
  cp "${ROOT_DIR}/.env.example" "${ROOT_DIR}/.env"
fi

cd "${ROOT_DIR}"
set -a
# shellcheck disable=SC1091
source "${ROOT_DIR}/.env"
set +a

need_docker() {
  if ! command -v docker >/dev/null 2>&1 || ! docker version >/dev/null 2>&1; then
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
  "${COMPOSE[@]}" exec -T wpcli wp --path="${WP_PATH}" --allow-root "$@"
}

start() {
  need_docker
  "${COMPOSE[@]}" up -d db wordpress wpcli
  printf 'WordPress: %s\n' "${WP_URL:-http://localhost:${WP_PORT:-8080}}"
}

wait_for_wordpress_files() {
  need_docker
  for _ in $(seq 1 30); do
    if "${COMPOSE[@]}" exec -T wordpress test -f "${WP_PATH}/wp-load.php" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done

  echo "Timed out waiting for WordPress core files." >&2
  return 1
}

stop() {
  need_docker
  "${COMPOSE[@]}" stop
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

  "${COMPOSE[@]}" run --rm composer sh -lc 'git config --global --add safe.directory /app || true; composer install'
  wp theme activate goetz-legal
  wp plugin activate goetz-migration || true
  wp plugin install wordpress-seo wpforms-lite --activate || true
  wp rewrite structure '/%postname%/' --hard
  wp rewrite flush
}

theme_build() {
  need_docker
  "${COMPOSE[@]}" run --rm composer sh -lc 'git config --global --add safe.directory /app || true; composer install'
  "${COMPOSE[@]}" run --rm node sh -lc 'npm install && npm run build'
}

theme_dev() {
  need_docker
  "${COMPOSE[@]}" run --rm --service-ports node sh -lc 'npm install && npm run dev -- --host 0.0.0.0'
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
  "${COMPOSE[@]}" up -d db wordpress wpcli >/dev/null
  wait_for_wordpress_files

  if ! wp core is-installed >/dev/null 2>&1; then
    echo "WordPress is not installed; cannot export the database." >&2
    exit 1
  fi

  local export_dir="${ROOT_DIR}/__dev"
  local timestamp
  timestamp="$(date '+%Y-%m-%d_%H-%M-%S')"
  local output_file="${export_dir}/goetzlegal-db-${timestamp}.sql"

  mkdir -p "${export_dir}"
  "${COMPOSE[@]}" exec -T wpcli wp --path="${WP_PATH}" --allow-root --quiet db export - --add-drop-table > "${output_file}"

  printf 'Database exported: %s\n' "${output_file}"
}

case "${1:-help}" in
  start) start ;;
  stop) stop ;;
  restart) stop; start ;;
  logs) need_docker; "${COMPOSE[@]}" logs -f "${2:-wordpress}" ;;
  shell) need_docker; "${COMPOSE[@]}" exec wordpress bash ;;
  wp) shift; wp "$@" ;;
  db) need_docker; "${COMPOSE[@]}" exec db mariadb -u"${MYSQL_USER:-wordpress}" -p"${MYSQL_PASSWORD:-wordpress}" "${MYSQL_DATABASE:-wordpress}" ;;
  install) install_site ;;
  db:export) db_export ;;
  theme:dev) theme_dev ;;
  theme:build) theme_build ;;
  migrate:scan) migrate_scan ;;
  migrate:import) migrate_import ;;
  *)
    cat <<'MSG'
Usage: ./manager.sh <command>

Commands:
  start            Start local WordPress services
  stop             Stop local services
  restart          Restart local services
  logs [service]   Tail logs, default: wordpress
  shell            Open a shell in the WordPress container
  wp <args>        Run WP-CLI against the local site
  db               Open the local database shell
  db:export        Export the local database to __dev with a timestamped SQL filename
  install          Install/configure WordPress, theme, Yoast, WPForms
  theme:dev        Start Vite dev server for the Tailpress theme
  theme:build      Install theme deps and build production assets
  migrate:scan     Dry-run source discovery/import preview
  migrate:import   Import/update live-site pages and media
MSG
    ;;
esac
