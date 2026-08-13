#!/usr/bin/env bash
echo "Setting storage permissions..."
chmod -R 777 /var/www/html/storage /var/www/html/bootstrap/cache

echo "Running composer"
composer install --no-dev --working-dir=/var/www/html

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Running migrations..."
php artisan migrate --force

