#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -f "vendor/autoload.php" ]; then
    echo "Installing dependencies..."
    composer install --no-interaction
fi

echo "Dumping autoload..."
composer dump-autoload --no-interaction 2>/dev/null

if [ ! -f "box.phar" ]; then
    echo "Downloading box.phar..."
    curl -sL https://github.com/box-project/box/releases/download/4.5.1/box.phar -o box.phar
    chmod +x box.phar
fi

echo "Building PHAR..."
php box.phar compile --allow-composer-check-failure 2>&1 | grep -v "ComposerOrchestrator\|dump-autoload\|JsonFile\|In Composer\|UndetectableComposerVersion"

if [ ! -f "zai-srt-translate.phar" ]; then
    echo "Error: PHAR build failed"
    exit 1
fi

if ! php zai-srt-translate.phar --version >/dev/null 2>&1; then
    echo "Error: PHAR build produced invalid file"
    exit 1
fi

echo ""
echo "Build complete: zai-srt-translate.phar (v$(cat VERSION))"
echo "Test with: php zai-srt-translate.phar --list-models"
