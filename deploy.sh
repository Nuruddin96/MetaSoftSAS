#!/bin/bash

export HOME=/home/u162248930
export COMPOSER_HOME=/home/u162248930/.composer

PROJECT="/home/u162248930/domains/metasoftbd.com/apps/shopsaas-git"

echo "===== MetaSoft SaaS Deployment Started ====="

cd "$PROJECT" || exit 1

echo "Pulling latest code..."
git pull origin main

echo "Installing Composer packages..."
/usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction

echo "Optimizing Laravel..."
php artisan optimize:clear
php artisan optimize

echo "===== Deployment Complete ====="
