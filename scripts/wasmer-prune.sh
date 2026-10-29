#!/bin/sh
set -eu

# Run only inside the disposable Anybuild build directory, before /app is exported.
test "$(pwd)" = /app || {
    echo "Wasmer pruning must run in the /app build directory." >&2
    exit 1
}

# Anybuild initially installs development packages with scripts disabled.
# Remove them and regenerate Laravel's package manifest for production.
composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-scripts --no-interaction
composer dump-autoload --no-dev --optimize --no-interaction

# Frontend build tools are not needed by the PHP runtime.
rm -rf node_modules
du -sh vendor .
