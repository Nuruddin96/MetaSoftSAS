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

php artisan storage:link
php artisan optimize:clear
php artisan optimize

echo "===== Deployment Complete ====="
