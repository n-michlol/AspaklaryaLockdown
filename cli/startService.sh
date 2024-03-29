#!/bin/bash
export $(grep -v '^#' .env | xargs)

echo "Starting bad-word-service Service..."

/var/www/html/w/extensions/AspaklaryaLockdown/cli/bad-word-service