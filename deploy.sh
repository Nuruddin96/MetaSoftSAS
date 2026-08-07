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

echo "Installing npm packages..."
# If this fails with "npm: command not found", this cPanel account's Node.js
# Selector app needs its activate script sourced first, e.g.:
#   source /home/u162248930/nodevenv/domains/metasoftbd.com/apps/shopsaas-git/<node-version>/bin/activate
npm ci

echo "Building frontend assets (Tailwind/Vite)..."
npm run build

echo "Syncing files to LIVE..."
rsync -av --delete \
  --exclude=".git/" \
  --exclude=".env" \
  --exclude="storage/" \
  --exclude="node_modules/" \
  "$GIT_PROJECT/" \
  "$LIVE_PROJECT/"

echo "Optimizing Laravel..."
cd "$LIVE_PROJECT"

php artisan optimize:clear
php artisan optimize

echo "===== Deployment Complete ====="
