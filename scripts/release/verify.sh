#!/usr/bin/env bash
set -euo pipefail

fail() {
  printf 'release-verify: %s\n' "$1" >&2
  exit 1
}

[[ $# -ge 1 && $# -le 2 ]] || fail 'usage: verify.sh <release-directory-or-payload> [expected-commit-sha]'
input="$1"
expected_sha="${2:-}"
[[ -d "$input" ]] || fail "release path is not a directory: $input"

if [[ -f "$input/RELEASE-MANIFEST.sha256" ]]; then
  payload="$input"
elif [[ -f "$input/payload/RELEASE-MANIFEST.sha256" ]]; then
  payload="$input/payload"
else
  fail 'release manifest is missing'
fi

[[ ! -L "$payload" ]] || fail 'payload directory must not be a symbolic link'
if [[ -n "$expected_sha" && ! "$expected_sha" =~ ^[0-9a-f]{40}$ ]]; then
  fail 'expected commit must be a full lowercase SHA-1'
fi

mapfile -t top_level < <(find "$payload" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${top_level[*]}" == 'RELEASE-MANIFEST.sha256 release.json wp-content' ]] ||
  fail "payload has unexpected top-level entries: ${top_level[*]}"
[[ -d "$payload/wp-content/themes" && -d "$payload/wp-content/plugins" ]] || fail 'payload is missing WordPress runtime roots'
mapfile -t content_roots < <(find "$payload/wp-content" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${content_roots[*]}" == 'plugins themes' ]] || fail "wp-content has unexpected roots: ${content_roots[*]}"
mapfile -t themes < <(find "$payload/wp-content/themes" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${themes[*]}" == 'goetz-legal' ]] || fail "payload has unexpected themes: ${themes[*]}"
mapfile -t plugins < <(find "$payload/wp-content/plugins" -mindepth 1 -maxdepth 1 -printf '%f\n' | LC_ALL=C sort)
[[ "${plugins[*]}" == 'goetz-migration goetz-site wordpress-seo wpforms-lite' ]] ||
  fail "payload has unexpected plugins: ${plugins[*]}"

if find "$payload" -type l -print -quit | grep -q .; then
  fail 'payload contains a symbolic link'
fi
if find "$payload" \
  \( -name '.env*' -o -name '.git' -o -name node_modules -o -name tests -o -name screenshots \
     -o -name __tests__ -o -name artifacts -o -name '*.sql' -o -name '*.map' \
     -o -name '*.test.*' -o -name '*.spec.*' \) -print -quit | grep -q .; then
  fail 'payload contains a forbidden secret, VCS, development, SQL, or source-map entry'
fi
[[ ! -e "$payload/wp-content/uploads" ]] || fail 'runtime uploads must never ship in a code release'
[[ ! -e "$payload/vendor" ]] || fail 'root development vendor must never ship in a release'

for generated in \
  wp-content/themes/goetz-legal/dist/.vite/manifest.json \
  wp-content/themes/goetz-legal/vendor/autoload.php \
  wp-content/plugins/goetz-site/build/index.js \
  wp-content/plugins/goetz-site/build/index.asset.php; do
  [[ -s "$payload/$generated" ]] || fail "required generated runtime file is missing: $generated"
done
find "$payload/wp-content/themes/goetz-legal/dist/assets" -maxdepth 1 -type f -name '*.css' -print -quit | grep -q . ||
  fail 'theme release has no generated CSS'
find "$payload/wp-content/themes/goetz-legal/dist/assets" -maxdepth 1 -type f -name '*.js' -print -quit | grep -q . ||
  fail 'theme release has no generated JavaScript'

header_value() {
  local header="$1"
  local path="$2"
  awk -F: -v wanted="$header" '
    BEGIN { IGNORECASE = 1 }
    {
      key = $1
      gsub(/^[[:space:]/*#]+|[[:space:]]+$/, "", key)
      if (tolower(key) == tolower(wanted)) {
        value = substr($0, index($0, ":") + 1)
        gsub(/^[[:space:]]+|[[:space:]*/]+$/, "", value)
        print value
        exit
      }
    }
  ' "$path"
}

[[ "$(header_value 'Version' "$payload/wp-content/themes/goetz-legal/style.css")" == '1.0.0' ]] || fail 'goetz-legal version is not 1.0.0'
[[ "$(header_value 'Version' "$payload/wp-content/plugins/goetz-site/goetz-site.php")" == '1.0.0' ]] || fail 'goetz-site version is not 1.0.0'
[[ "$(header_value 'Version' "$payload/wp-content/plugins/goetz-migration/goetz-migration.php")" == '1.1.0' ]] || fail 'goetz-migration version is not 1.1.0'
[[ "$(header_value 'Version' "$payload/wp-content/plugins/wordpress-seo/wp-seo.php")" == '28.0' ]] || fail 'Yoast version is not 28.0'
[[ "$(header_value 'Version' "$payload/wp-content/plugins/wpforms-lite/wpforms.php")" == '1.10.0.4' ]] || fail 'WPForms Lite version is not 1.10.0.4'

release_json="$payload/release.json"
manifest="$payload/RELEASE-MANIFEST.sha256"
[[ -s "$release_json" && -s "$manifest" ]] || fail 'release metadata files are empty'
command -v node >/dev/null 2>&1 || fail 'Node.js is required for strict release.json validation'
release_sha="$(node - "$release_json" <<'NODE'
const fs = require('fs');
const path = process.argv[2];
const die = (message) => { console.error(message); process.exit(1); };
let data;
try {
  data = JSON.parse(fs.readFileSync(path, 'utf8'));
} catch {
  die('release.json is not valid JSON');
}
const object = (value) => value && typeof value === 'object' && !Array.isArray(value);
const exactKeys = (value, keys, label) => {
  if (!object(value)) die(`${label} must be an object`);
  const actual = Object.keys(value).sort();
  const expected = [...keys].sort();
  if (actual.length !== expected.length || actual.some((key, index) => key !== expected[index])) {
    die(`${label} has unexpected or missing keys`);
  }
};
exactKeys(data, [
  'schema_version', 'commit', 'branch', 'commit_time_utc', 'source_date_epoch',
  'wordpress_compatibility', 'php', 'plugin_versions', 'lock_hashes'
], 'release.json');
if (data.schema_version !== 1) die('schema_version must be integer 1');
if (!/^[0-9a-f]{40}$/.test(data.commit)) die('commit must be a full lowercase SHA-1');
if (data.branch !== 'main') die('branch must be main');
if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(data.commit_time_utc)) die('commit_time_utc must be canonical UTC');
if (!Number.isSafeInteger(data.source_date_epoch) || data.source_date_epoch <= 0) die('source_date_epoch must be a positive integer');
if (new Date(data.source_date_epoch * 1000).toISOString().replace('.000Z', 'Z') !== data.commit_time_utc) die('commit time fields disagree');
exactKeys(data.wordpress_compatibility, ['minimum', 'tested'], 'wordpress_compatibility');
if (data.wordpress_compatibility.minimum !== '6.9') die('unexpected minimum WordPress version');
if (JSON.stringify(data.wordpress_compatibility.tested) !== JSON.stringify(['6.9.4', '7.0.1'])) die('unexpected tested WordPress versions');
if (data.php !== '8.3') die('unexpected PHP compatibility');
const versions = {
  'goetz-legal': '1.0.0',
  'goetz-site': '1.0.0',
  'goetz-migration': '1.1.0',
  'wordpress-seo': '28.0',
  'wpforms-lite': '1.10.0.4'
};
exactKeys(data.plugin_versions, Object.keys(versions), 'plugin_versions');
for (const [name, version] of Object.entries(versions)) if (data.plugin_versions[name] !== version) die(`unexpected ${name} version`);
const locks = [
  'composer.lock',
  'wp-content/themes/goetz-legal/composer.lock',
  'wp-content/themes/goetz-legal/package-lock.json',
  'wp-content/plugins/goetz-site/package-lock.json',
  'tests/e2e/package-lock.json'
];
exactKeys(data.lock_hashes, locks, 'lock_hashes');
for (const lock of locks) if (!/^[0-9a-f]{64}$/.test(data.lock_hashes[lock])) die(`invalid lock hash for ${lock}`);
process.stdout.write(data.commit);
NODE
)" || fail 'release.json failed strict schema validation'
[[ -z "$expected_sha" || "$release_sha" == "$expected_sha" ]] || fail 'release.json commit does not match expected commit'

awk '
  length($1) != 64 || $1 !~ /^[0-9a-f]+$/ { exit 1 }
  $2 !~ /^\.\// || $2 ~ /(^|\/)\.\.($|\/)/ || $2 ~ /^\// { exit 1 }
' "$manifest" || fail 'release manifest has an unsafe or malformed entry'
grep -Fq '  ./release.json' "$manifest" || fail 'release manifest does not hash release.json'
! grep -Fq 'RELEASE-MANIFEST.sha256' "$manifest" || fail 'release manifest hashes itself'

actual_files="$(mktemp "${TMPDIR:-/tmp}/goetz-release-files.actual.XXXXXX")"
manifest_files="$(mktemp "${TMPDIR:-/tmp}/goetz-release-files.manifest.XXXXXX")"
cleanup_lists() {
  rm -f "$actual_files" "$manifest_files"
}
trap cleanup_lists EXIT
find "$payload" -type f ! -name RELEASE-MANIFEST.sha256 -printf './%P\n' | LC_ALL=C sort > "$actual_files"
awk '{print $2}' "$manifest" | LC_ALL=C sort > "$manifest_files"
if ! cmp -s "$actual_files" "$manifest_files"; then
  fail 'release manifest file list does not exactly match the payload'
fi
(
  cd "$payload"
  sha256sum --check --strict RELEASE-MANIFEST.sha256 >/dev/null
) || fail 'release payload hash verification failed'

printf 'release_commit=%s\n' "$release_sha"
printf 'manifest_sha256=%s\n' "$(sha256sum "$manifest" | cut -d' ' -f1)"
