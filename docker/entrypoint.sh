#!/bin/sh
set -e

# Opt-in: mirror the production deploy step (doctrine:schema:update --force).
if [ "${RUN_SCHEMA_UPDATE:-0}" = "1" ]; then
    echo "[entrypoint] doctrine:schema:update --force"
    php bin/console doctrine:schema:update --force --no-interaction
fi

exec "$@"
