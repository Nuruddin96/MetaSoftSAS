#!/bin/bash

set -e

export HOME=/home/u162248930
export COMPOSER_HOME=/home/u162248930/.composer

GIT_PROJECT="/home/u162248930/domains/metasoftbd.com/apps/shopsaas-git"
LIVE_PROJECT="/home/u162248930/domains/metasoftbd.com/apps/shopsaas"

echo "===== MetaSoft SaaS Deployment Started ====="

cd "$GIT_PROJECT"

echo "Pulling latest code..."
git pull origin main

echo "Installing Composer packages..."
/usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction

echo "Syncing files to LIVE..."
rsync -av --delete \
  --exclude=".git/" \
  --exclude=".env" \
  --exclude="storage/" \
  --exclude="node_modules/" \
  --exclude="public/storage" \
  "$GIT_PROJECT/" \
  "$LIVE_PROJECT/"

echo "Optimizing Laravel..."
cd "$LIVE_PROJECT"

# Hostinger's PHP-FPM pool for this account disables both exec() and
# symlink() (see disable_functions in this account's php.ini — not
# something this script touches). Illuminate\Filesystem::link(), which
# `php artisan storage:link` calls, tries symlink() first and only falls
# back to exec('ln -s ...') when symlink() is unavailable — with both
# disabled, that fallback itself fails with "Call to undefined function
# Illuminate\Filesystem\exec()" and aborts the whole deploy (set -e).
# Bash's own `ln` is a shell builtin/binary, not a PHP function, so it is
# completely unaffected by PHP's disable_functions — when PHP can't make
# the symlink itself, skip the artisan command entirely and create/repair
# the exact same link (public/storage -> storage/app/public, the sole
# entry in config/filesystems.php's `links`) directly here instead.
if php -r "exit(function_exists('symlink') ? 0 : 1);" 2>/dev/null; then
    php artisan storage:link
else
    echo "NOTE: PHP's symlink()/exec() are disabled on this host — skipping 'php artisan storage:link' (it would fail) and creating the storage symlink directly via the shell instead."
    ln -sfn "$LIVE_PROJECT/storage/app/public" "$LIVE_PROJECT/public/storage"
    echo "public/storage -> storage/app/public symlink created/refreshed via shell ln."
fi

php artisan optimize:clear
php artisan optimize

echo "===== Deployment Complete ====="
