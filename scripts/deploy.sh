#!/usr/bin/env bash
# Deploys the latest main on the VPS. Run as the site user:
#   sudo -u apipr9038 bash -c 'cd /home/api.provokenow.com/public_html && ./scripts/deploy.sh'
# Ends by rebuilding Laravel's and Filament's caches (config, routes, views, components, icons):
# without them every request, and especially every admin click, is noticeably slower.
set -euo pipefail
cd "$(dirname "$0")/.."

git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force

php artisan optimize:clear
php artisan optimize
php artisan filament:optimize

# Let the queue worker pick up the new code on its next run.
php artisan queue:restart

echo "Deployed $(git rev-parse --short HEAD)"
