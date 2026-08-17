#!/bin/bash

set -e

export HOME=/home/u162248930
export COMPOSER_HOME=/home/u162248930/.composer

GIT_PROJECT="/home/u162248930/domains/metasoftbd.com/apps/shopsaas-git"
LIVE_PROJECT="/home/u162248930/domains/metasoftbd.com/apps/shopsaas"

# node/npm are NOT installed on this shared-hosting host (confirmed: `which
# node npm` finds neither), so this script can only ever install Composer
# packages and rsync whatever is already committed — it can never run
# `npm run build` itself. If you changed any Blade/CSS Tailwind classes,
# you MUST run `npm run build` locally and commit the resulting
# public/build/ files BEFORE pushing, or production silently keeps serving
# the old compiled CSS/JS with your new classes missing from it (this
# already happened once — see BuiltAssetsTest.php for the regression
# guard). The manifest check below only catches a missing/uncommitted
# build entirely; it cannot detect a build that's merely stale.
echo "===== MetaSoft SaaS Deployment Started ====="

cd "$GIT_PROJECT"

echo "Pulling latest code..."
git pull origin main

echo "Verifying frontend assets were built and committed..."
if [ -f "public/build/manifest.json" ]; then
    php -r '
        $m = json_decode(file_get_contents("public/build/manifest.json"), true);
        foreach ($m as $entry) {
            if (isset($entry["file"]) && ! is_file("public/build/" . $entry["file"])) {
                fwrite(STDERR, "Manifest references missing built asset: " . $entry["file"] . "\n");
                exit(1);
            }
        }
    '
else
    echo "ERROR: public/build/manifest.json not found — run \`npm run build\` locally and commit public/build/ before deploying." >&2
    exit 1
fi

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
