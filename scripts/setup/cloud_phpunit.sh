#!/usr/bin/env bash
# PHPUnit in a Claude cloud thread (B3): PHP 8.5 + PostgreSQL 16 from conda-forge, no Docker, no apt.
#
# The cloud host has PHP 8.3 (PHPUnit 13 needs >= 8.4) and blocks PPAs/Docker images; conda.anaconda.org
# is reachable. Installs into /opt/cloudphp (about 3-5 minutes, re-runs skip what exists), starts Postgres on
# 127.0.0.1:5432 as user `pgrun` (initdb refuses root), writes .env.test.local if missing and creates the
# test schema like CI. Postgres differs from CI's MySQL in raw SQL details — CI stays the reference.
#
# Needs vendor/ first: python3 scripts/setup/cloud_vendor.py
# Then:  export PATH=/opt/cloudphp/wrap:$PATH && php bin/phpunit tests/…
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE=/opt/cloudphp
MICROMAMBA_VERSION="${MICROMAMBA_VERSION:-2.10.0}"
PGDATA=/opt/pgdata

mkdir -p "$BASE"
cd "$BASE"
if [ ! -x bin/micromamba ]; then
    echo "micromamba ${MICROMAMBA_VERSION} ..."
    curl -sSfO "https://conda.anaconda.org/conda-forge/linux-64/micromamba-${MICROMAMBA_VERSION}-0.tar.bz2"
    tar xjf "micromamba-${MICROMAMBA_VERSION}-0.tar.bz2" bin/micromamba
fi
export MAMBA_ROOT_PREFIX="$BASE/mamba"
[ -x php85/bin/php ] || { echo "PHP 8.5 ..."; bin/micromamba create -y -q -p "$BASE/php85" -c conda-forge php=8.5 >/dev/null; }
[ -x pg/bin/postgres ] || { echo "PostgreSQL 16 ..."; bin/micromamba create -y -q -p "$BASE/pg" -c conda-forge postgresql=16 >/dev/null; }

mkdir -p wrap
cat > wrap/php <<EOF
#!/bin/sh
exec $BASE/php85/bin/php -d memory_limit=2G "\$@"
EOF
chmod +x wrap/php

id pgrun >/dev/null 2>&1 || useradd -M pgrun
chmod o+rx /opt "$BASE"
if [ ! -f "$PGDATA/PG_VERSION" ]; then
    mkdir -p "$PGDATA" && chown pgrun "$PGDATA"
    su pgrun -s /bin/bash -c "$BASE/pg/bin/initdb -D $PGDATA -U postgres --auth=trust >/dev/null"
fi
if ! su pgrun -s /bin/bash -c "$BASE/pg/bin/pg_ctl -D $PGDATA status" >/dev/null 2>&1; then
    su pgrun -s /bin/bash -c "$BASE/pg/bin/pg_ctl -D $PGDATA -o '-p 5432 -k /tmp' -l $PGDATA/log start" >/dev/null
fi

cd "$ROOT"
if [ ! -f .env.test.local ]; then
    cat > .env.test.local <<'EOF'
DATABASE_URL="postgresql://postgres@127.0.0.1:5432/isms?serverVersion=16&charset=utf8"
MAILER_DSN=null://null
MESSENGER_TRANSPORT_DSN=sync://
EOF
fi
[ -f config/active_modules.yaml ] || cp config/active_modules.yaml.dist config/active_modules.yaml
touch config/setup_complete.lock
export PATH="$BASE/wrap:$PATH"
# importmap:install needs jsdelivr (blocked): empty stand-ins where AssetMapper looks, so pages render in tests.
php -r '
foreach (require "importmap.php" as $name => $entry) {
    if (!isset($entry["version"])) { continue; }
    $spec = $entry["package_specifier"] ?? $name;
    $type = $entry["type"] ?? "js";
    $slash = str_starts_with($spec, "@") ? strpos($spec, "/", strpos($spec, "/") + 1) : strpos($spec, "/");
    [$pkg, $sub] = $slash === false ? [$spec, ""] : [substr($spec, 0, $slash), substr($spec, $slash + 1)];
    $file = "assets/vendor/" . $pkg . "/" . ($sub !== "" ? $sub : basename($pkg) . ".index");
    if (!str_ends_with($file, "." . $type)) { $file .= "." . $type; }
    if (!is_file($file)) { @mkdir(dirname($file), 0777, true); file_put_contents($file, ""); }
}'
php bin/console cache:clear --env=test >/dev/null
php bin/console doctrine:database:create --if-not-exists --env=test >/dev/null
php bin/console doctrine:schema:update --force --complete --env=test >/dev/null
echo "Ready: export PATH=$BASE/wrap:\$PATH && php bin/phpunit tests/…"
