#!/bin/bash
set -e

echo "=== Running migrations ==="
php artisan migrate --force

echo "=== Importing db_koperasi.sql ==="
mysql -h $DB_HOST -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE < db_koperasi.sql

echo "=== Import complete ==="

