#!/bin/bash
set -e
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -f "vendor/autoload.php" ]; then
    composer install --no-dev
fi

php -dphar.readonly=0 vendor/bin/box compile
echo "Built: zai-srt-translate.phar"
