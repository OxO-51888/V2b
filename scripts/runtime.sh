#!/bin/bash

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_PHAR="${COMPOSER_PHAR:-$PWD/composer.phar}"

panel_check_runtime() {
    if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
        echo "PHP 8.5.12+ is required. Set PHP_BIN to the PHP 8.5 executable." >&2
        return 1
    fi
    "$PHP_BIN" bootstrap/runtime.php
    if [ ! -s composer.lock ]; then
        echo "composer.lock is required; refusing an untested dependency update." >&2
        return 1
    fi
}

panel_install_dependencies() {
    if [ ! -s "$COMPOSER_PHAR" ]; then
        local installer expected
        installer=$(mktemp)
        if ! curl --fail --silent --show-error --location https://getcomposer.org/installer -o "$installer" ||
            ! expected=$(curl --fail --silent --show-error https://composer.github.io/installer.sig) ||
            ! "$PHP_BIN" -r 'exit(hash_equals(trim($argv[2]), hash_file("sha384", $argv[1])) ? 0 : 1);' "$installer" "$expected" ||
            ! "$PHP_BIN" "$installer" --install-dir="$PWD" --filename=composer.phar --quiet; then
            rm -f -- "$installer"
            return 1
        fi
        rm -f -- "$installer"
        COMPOSER_PHAR="$PWD/composer.phar"
    fi
    "$PHP_BIN" "$COMPOSER_PHAR" validate --strict --no-check-publish
    "$PHP_BIN" "$COMPOSER_PHAR" check-platform-reqs --lock --no-dev
    "$PHP_BIN" "$COMPOSER_PHAR" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    "$PHP_BIN" "$COMPOSER_PHAR" check-platform-reqs --no-dev
}
