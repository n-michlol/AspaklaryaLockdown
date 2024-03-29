#!/bin/bash
export $(grep -v '^#' /var/secret/.env | xargs)

echo "Starting bad-word-service Service..."

/var/www/html/w/extensions/AspaklaryaLockdown/cli/bad-word-service