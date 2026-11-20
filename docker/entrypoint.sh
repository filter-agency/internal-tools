#!/bin/sh
# Container start: load app settings, build Laravel caches with the runtime values, run the command.
set -e

# App settings arrive as one JSON secret; export each key as an environment variable.
if [ -n "${APP_SECRETS:-}" ]; then
  eval "$(php -r '
    foreach (json_decode(getenv("APP_SECRETS"), true, 16, JSON_THROW_ON_ERROR) as $k => $v) {
      if (!preg_match("/^[A-Z][A-Z0-9_]*$/", $k)) { fwrite(STDERR, "Skipping invalid key\n"); continue; }
      echo "export " . $k . "=" . escapeshellarg((string) $v) . "\n";
    }')"
  unset APP_SECRETS
fi

# Built here, not in the image, so the route cache matches the APP_KEY in use (Livewire).
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
