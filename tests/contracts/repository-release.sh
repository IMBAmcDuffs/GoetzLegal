#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

fail() {
  printf 'repository-release: %s\n' "$1" >&2
  exit 1
}

# Repository ignore and manager safety invariants.
git check-ignore -q --no-index .env
! git check-ignore -q --no-index .env.example
grep -Fqx '/.env*' .gitignore
grep -Fqx '/.env' .gitignore
grep -Fqx '!.env.example' .gitignore
grep -q '^unset SSH_KEY_PW$' manager.sh
grep -q 'COMPOSE_DISABLE_ENV_FILE=1' manager.sh
grep -q -- '--env-file /dev/null' manager.sh
! grep -q -- '--env-file .*\.env' manager.sh
! grep -Eq 'npm install([[:space:]]|$)' manager.sh
! grep -Eq 'wp plugin install (wordpress-seo|wpforms-lite)([[:space:]]|$)' manager.sh
! grep -Eq 'deploy:db|wp db import' manager.sh
! grep -Fq '${ROOT_DIR}/wp-content/uploads/' manager.sh
! grep -Eq "wp-content/plugins/([\"'])" manager.sh

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
  /bin/bash "$fixture/manager.sh" compose config

record="$fixture/bin/docker-record"
[[ -s "$record" ]] || fail 'fake Docker did not record the Compose invocation'
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
)
for name in "${disallowed[@]}"; do
  ! grep -q "^${name}=" "$record" || fail "non-allowlisted variable reached Docker: $name"
done

! grep -Fq 'never-forward-this-test-value' "$record" || fail 'synthetic SSH passphrase reached Docker'
! grep -Fq 'never-forward-non-allowlisted' "$record" || fail 'synthetic non-allowlisted value reached Docker'
! grep -Fq "$fixture/.env" "$record" || fail 'synthetic .env path reached Docker'

printf 'repository-release: PASS\n'
