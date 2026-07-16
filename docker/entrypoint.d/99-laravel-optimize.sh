#!/usr/bin/env sh
#
# Runtime Laravel optimizations, run at container boot by the serversideup
# entrypoint (it sources /etc/entrypoint.d/*.sh) — at which point Render has
# injected the environment variables the cached config depends on.
#
# NOT done here:
#   * migrations   -> Render Pre-Deploy Command (`php artisan migrate --force`),
#                     so they run once per deploy, not once per instance/boot.
#   * route:cache  -> routes/web.php registers closure routes, which cannot be
#                     serialized; caching them would throw at boot.
#
# Each step is best-effort (|| true) so a caching hiccup never blocks startup.
set -e

cd /var/www/html

php artisan package:discover --ansi || true
php artisan config:cache || true
php artisan view:cache || true
