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
! grep -Eq 'wp plugin install (wordpress-seo|wpforms-lite)([[:space:]]|$)' manager.sh
! grep -Eq 'deploy:db|wp db import' manager.sh
! grep -Fq '${ROOT_DIR}/wp-content/uploads/' manager.sh
declare -F scan_repository_deployment_scripts >/dev/null || fail 'repository rsync deletion scanner is missing'
scan_repository_deployment_scripts || fail 'repository deployment code violates the zero-delete baseline'

inspect_release() {
  local release_dir="$1"
  local entry

  [[ -d "$release_dir" ]] || fail "GOETZ_RELEASE_DIR is not a directory: $release_dir"

  while IFS= read -r entry; do
    case "$entry" in
      .env*|*/.env*|*.sql|*/.git|*/.git/*|.git|.git/*|node_modules|*/node_modules|node_modules/*|*/node_modules/*|tests|*/tests|tests/*|*/tests/*)
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
SSH_KEY_PW=never-forward-this-test-value
ENV
chmod 0644 "$fixture/.env"

cat > "$fixture/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail
record="${0%/*}/docker-record"
{
  printf 'argv:'
  printf ' <%s>' "$@"
  printf '\n'
  /usr/bin/env | /usr/bin/sort
} >> "$record"
DOCKER
chmod 700 "$fixture/bin/docker"

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
)
for name in "${disallowed[@]}"; do
  ! grep -q "^${name}=" "$record" || fail "non-allowlisted variable reached Docker: $name"
done

! grep -Fq 'never-forward-this-test-value' "$record" || fail 'synthetic SSH passphrase reached Docker'
! grep -Fq 'never-forward-inherited-ssh-value' "$record" || fail 'inherited synthetic SSH passphrase reached Docker'
! grep -Fq 'never-forward-non-allowlisted' "$record" || fail 'synthetic non-allowlisted value reached Docker'
! grep -Fq 'never-forward-preexported-value' "$record" || fail 'pre-exported synthetic value reached Docker'
! grep -Fq "$fixture/.env" "$record" || fail 'synthetic .env path reached Docker'

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
