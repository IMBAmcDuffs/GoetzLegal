#!/usr/bin/env bash

# This file is sourced by manager.sh so every Docker Compose operation remains
# inside the manager's sanitized compose wrapper.

readonly GOETZ_WORDPRESS_694_IMAGE='wordpress:6.9.4-php8.3-apache@sha256:5d2c212561c4b5442ebc4d98933a9cbadcf3dee8888ed3fd9ed44667c27cc905'
readonly GOETZ_WORDPRESS_701_IMAGE='wordpress:7.0.1-php8.3-apache@sha256:d40b86dbdfcfad808a2029acf6543c670c4a61c29f70b9d24605e7d0b31ab83d'

goetz_compat_wait_for_files() {
  local attempt
  for attempt in $(seq 1 60); do
    if compose exec -T wordpress test -f /var/www/html/wp-load.php >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done

  echo 'Timed out waiting for compatibility WordPress files.' >&2
  return 1
}

goetz_compat_wp() {
  compose exec -T wpcli wp --path=/var/www/html --allow-root "$@"
}

goetz_compat_full_assertions() {
  local migration_second
  local seo_second

  goetz_compat_wp eval-file /app/tests/fixtures/compat-site.php
  goetz_compat_wp theme activate goetz-legal
  goetz_compat_wp plugin activate goetz-site goetz-migration wordpress-seo wpforms-lite
  goetz_compat_wp eval '
    $expected = [
      "goetz/attorney-card", "goetz/cta", "goetz/faq-list",
      "goetz/hero", "goetz/resource-links", "goetz/welcome",
      "goetz/practice-areas", "goetz/practice-area-item", "goetz/attorney-grid"
    ];
    $registry = WP_Block_Type_Registry::get_instance();
    foreach ($expected as $name) {
      if (! $registry->is_registered($name)) {
        throw new RuntimeException("Missing compatibility block: {$name}");
      }
    }
  '
  goetz_compat_wp goetz-site migrate homepage --apply --format=json >/dev/null
  migration_second="$(goetz_compat_wp goetz-site migrate homepage --apply --format=json)"
  grep -Eq '"status"[[:space:]]*:[[:space:]]*"noop"' <<< "$migration_second" || {
    echo 'Second compatibility homepage migration was not a no-op.' >&2
    return 1
  }

  goetz_compat_wp goetz-site seo configure --strict --format=json >/dev/null
  seo_second="$(goetz_compat_wp goetz-site seo configure --strict --format=json)"
  grep -Eq '"changed_options"[[:space:]]*:[[:space:]]*0' <<< "$seo_second" &&
    grep -Eq '"changed_pages"[[:space:]]*:[[:space:]]*0' <<< "$seo_second" || {
      echo 'Second compatibility SEO configuration was not a no-op.' >&2
      return 1
    }
}

goetz_run_wordpress_version_matrix() {
  local mode="${1:-full}"
  local entry
  local version
  local image
  local port
  local -a matrix=(
    "6.9.4|${GOETZ_WORDPRESS_694_IMAGE}|18069"
    "7.0.1|${GOETZ_WORDPRESS_701_IMAGE}|18070"
  )

  [[ "$mode" == 'full' || "$mode" == '--bootstrap-only' ]] || {
    printf 'Unknown compatibility mode: %s\n' "$mode" >&2
    return 2
  }

  for entry in "${matrix[@]}"; do
    IFS='|' read -r version image port <<< "$entry"
    local COMPOSE_PROJECT_NAME="goetzcompat${version//./}"
    local WORDPRESS_IMAGE="$image"
    local WP_PORT="$port"
    local MYSQL_DATABASE="goetz_compat_${version//./_}"
    local MYSQL_USER='goetz_compat'
    local MYSQL_PASSWORD='goetz_compat_password'
    local MYSQL_ROOT_PASSWORD='goetz_compat_root_password'

    (
      trap 'compose down -v --remove-orphans >/dev/null 2>&1 || true' EXIT
      compose down -v --remove-orphans >/dev/null 2>&1 || true
      compose up -d db wordpress wpcli
      goetz_compat_wait_for_files

      if ! goetz_compat_wp core is-installed >/dev/null 2>&1; then
        goetz_compat_wp core install \
          --url="http://localhost:${WP_PORT}" \
          --title="Goetz compatibility ${version}" \
          --admin_user=compat_admin \
          --admin_password=compat_password \
          --admin_email=compat@example.invalid \
          --skip-email
      fi

      [[ "$(goetz_compat_wp core version)" == "$version" ]]
      goetz_compat_wp eval 'if (PHP_VERSION_ID < 80300 || PHP_VERSION_ID >= 80400) { throw new RuntimeException("Compatibility requires PHP 8.3"); }'

      if [[ "$mode" == 'full' ]]; then
        goetz_compat_full_assertions
      fi
    )
    printf 'compatibility bootstrap: WordPress %s / PHP 8.3 PASS\n' "$version"
  done
}
