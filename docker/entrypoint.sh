#!/bin/sh
# Entrypoint of the app service in docker-compose.yml: prepares the bind-mounted project, then serves it.
set -e

composer install --no-interaction --no-progress --prefer-dist

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -Eq '^APP_KEY=.+' .env; then
    php artisan key:generate
fi

# A database without a migrations table has never been set up, so it is seeded once, right after migrating.
if php artisan migrate:status > /dev/null 2>&1; then
    first_run=false
else
    first_run=true
fi

php artisan migrate --force

if [ "$first_run" = true ]; then
    php artisan db:seed --force
fi

# --no-reload: otherwise serve drops every environment variable not in its passthrough list before
# starting the web server, so the DB settings from docker-compose.yml would lose to .env.
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
