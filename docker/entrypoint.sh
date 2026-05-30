#!/bin/bash
set -e

# Set permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Generate app key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
    php artisan key:generate --force
fi

# Migrations are idempotent — always bring the schema up to date.
php artisan migrate --force

# Seed only on first boot. Re-seeding a populated database can duplicate demo
# rows, so we gate it on an empty `users` table. Querying the table directly
# (not an Eloquent model) keeps this independent of model namespaces.
HAS_USERS=$(php artisan tinker --execute="echo (\Illuminate\Support\Facades\Schema::hasTable('users') && \Illuminate\Support\Facades\DB::table('users')->exists()) ? 1 : 0;" 2>/dev/null | tr -dc '01' | tail -c1)
if [ "$HAS_USERS" != "1" ]; then
    echo "First boot detected (empty users table) — seeding database."
    php artisan db:seed --force
else
    echo "Existing data detected — skipping seed."
fi

# storage:link maps /public/storage -> /storage/app/public, which is how the
# Medialibrary-managed avatar / chat / file URLs resolve on a fresh clone.
# Re-run unconditionally: the command is a no-op when the symlink already
# points at the right target.
php artisan storage:link --force || true

# Config/route/view caching. In production, pre-build the caches so each boot
# (rolling deploys, autoscaling) and request skips re-parsing config/routes/
# views. In local/dev we CLEAR instead, so .env and route edits take effect
# without a rebuild.
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
else
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
fi

exec "$@"
