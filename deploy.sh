#!/usr/bin/env bash
#
# Deploy Our Coffee Shop to alwaysdata.
#
#   bash deploy.sh           upload the code
#   bash deploy.sh --db      upload, and load database/install.sql as well
#
# Credentials come from .deploy-env, which is never committed. Nothing secret
# is written into this file or printed to the screen.
#
# The upload is deliberately a full push rather than a diff: the project is
# about a megabyte, so working out what changed costs more than just sending
# it. Files the server owns, such as .env and uploaded pictures, are left
# alone.

set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f .deploy-env ]; then
    echo "No .deploy-env. Copy the credentials in there first." >&2
    exit 1
fi

# shellcheck disable=SC1091
set -a; . ./.deploy-env; set +a

: "${DEPLOY_SSH_HOST:?missing in .deploy-env}"
: "${DEPLOY_SSH_USER:?missing in .deploy-env}"
: "${DEPLOY_SSH_PASS:?missing in .deploy-env}"
: "${DEPLOY_PATH:?missing in .deploy-env}"

LOAD_DB=false
[ "${1:-}" = "--db" ] && LOAD_DB=true

SFTP="sftp://${DEPLOY_SSH_HOST}"
AUTH="${DEPLOY_SSH_USER}:${DEPLOY_SSH_PASS}"

say() { printf '  %s\n' "$1"; }

# Everything that belongs on the server. Anything not listed never leaves the
# machine, which is how the recording, the client documents and the internal
# notes stay off a public server as well as out of git.
PATHS=(
    ".htaccess"
    "customer-site"
    "admin-site"
    "assets"
    "shared"
    "database"
    "storage"
)

echo
echo "Deploying to ${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}:${DEPLOY_PATH}"
echo

# --- 1. Collect the file list ------------------------------------------------

mapfile -t FILES < <(
    for p in "${PATHS[@]}"; do
        if [ -f "$p" ]; then
            printf '%s\n' "$p"
        elif [ -d "$p" ]; then
            find "$p" -type f \
                ! -path '*/.git/*' \
                ! -name '*.log' \
                ! -name '.DS_Store' \
                ! -name 'Thumbs.db'
        fi
    done | sed 's|\\|/|g' | sort
)

say "${#FILES[@]} files to send"

# --- 2. Make the directories -------------------------------------------------
#
# SFTP will not create a parent on the fly, so every directory has to exist
# before the first file lands in it.

mapfile -t DIRS < <(
    for f in "${FILES[@]}"; do
        d="$(dirname "$f")"
        while [ "$d" != "." ] && [ "$d" != "/" ]; do
            printf '%s\n' "$d"
            d="$(dirname "$d")"
        done
    done | sort -u
)

say "ensuring ${#DIRS[@]} directories"

for d in "${DIRS[@]}"; do
    # An existing directory makes mkdir fail, which is fine and expected.
    curl -s --insecure -u "$AUTH" "$SFTP/" -Q "-mkdir ${DEPLOY_PATH}/${d}" >/dev/null 2>&1 || true
done

# --- 3. Upload ----------------------------------------------------------------

sent=0
failed=0

for f in "${FILES[@]}"; do
    if curl -s --insecure --max-time 120 -u "$AUTH" -T "$f" "$SFTP${DEPLOY_PATH}/$f" >/dev/null 2>&1; then
        sent=$((sent + 1))
        printf '\r  uploaded %d/%d' "$sent" "${#FILES[@]}"
    else
        failed=$((failed + 1))
        printf '\n  FAILED: %s\n' "$f" >&2
    fi
done

printf '\n'
say "sent ${sent}, failed ${failed}"

[ "$failed" -gt 0 ] && { echo "Some files did not upload. Fix those before testing." >&2; exit 1; }

# --- 4. The server's own .env -------------------------------------------------
#
# Uploaded only if it is not already there, so a deploy never overwrites the
# live database password with the local one.

if [ -f .env.production ]; then
    if curl -s --insecure -u "$AUTH" "$SFTP${DEPLOY_PATH}/.env" -o /dev/null 2>/dev/null; then
        say ".env already on the server, left alone"
    else
        curl -s --insecure -u "$AUTH" -T .env.production "$SFTP${DEPLOY_PATH}/.env" >/dev/null 2>&1 \
            && say ".env uploaded"
    fi
fi

# --- 5. Database --------------------------------------------------------------

if [ "$LOAD_DB" = true ]; then
    : "${DB_NAME:?missing in .deploy-env}"
    : "${DB_USER:?missing in .deploy-env}"
    : "${DB_PASS:?missing in .deploy-env}"
    : "${DB_HOST:?missing in .deploy-env}"

    say "loading database/install.sql"

    # Run it on the server, so no database port has to be open to the world.
    ssh -o StrictHostKeyChecking=no -o BatchMode=no \
        "${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}" \
        "mysql -h '${DB_HOST}' -u '${DB_USER}' -p'${DB_PASS}' '${DB_NAME}' < ${DEPLOY_PATH}/database/install.sql" \
        && say "database loaded" \
        || echo "  Database load failed. Import database/install.sql by hand in phpMyAdmin." >&2
fi

echo
say "Done."
say "Customer site: https://coffeeshop.alwaysdata.net"
say "Admin site:    https://coffeeshop.alwaysdata.net/admin-site/login.php"
echo
