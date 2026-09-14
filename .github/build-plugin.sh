#!/bin/sh
# Assembles the shippable plugin tree into build/blinkpay-nz-for-woocommerce,
# the plugin's canonical directory name regardless of the repository name.
# Shared by the package and plugin-check jobs so both operate on the same
# tree, with development files excluded.
set -eu
cd "$(dirname "$0")/.."

BUILD_DIR=build/blinkpay-nz-for-woocommerce

# Every PHP file the plugin bootstrap requires. Checked up front so a file
# that exists locally but was never committed fails here, naming itself,
# rather than as an opaque "failed to open stream" from the smoke test below
# or — worse — as a fatal on a merchant's site after a release.
REQUIRED_FILES="
blinkpay-nz-for-woocommerce.php
uninstall.php
includes/class-wc-blinkpay-api-client.php
includes/class-wc-blinkpay-blocks-support.php
includes/class-wc-blinkpay-gateway.php
includes/class-wc-blinkpay-http-transport.php
includes/class-wc-blinkpay-refund-blocked-exception.php
includes/class-wc-blinkpay-token-cache.php
"
missing=''
for file in $REQUIRED_FILES; do
	[ -f "$file" ] || missing="$missing  $file
"
done
if [ -n "$missing" ]; then
	echo "::error::build-plugin.sh: required plugin files are missing from the checkout (untracked, or not committed):"
	printf '%s' "$missing"
	exit 1
fi

# Emptied rather than removed and recreated, so a stale file from a previous
# build cannot survive into a release while the directory keeps its inode —
# wp-env bind-mounts this path, and a recreated directory leaves that mount
# pointing at nothing until the containers are rebuilt.
mkdir -p "$BUILD_DIR"
find "$BUILD_DIR" -mindepth 1 -delete
# vendor is excluded and reinstalled below rather than copied: the working
# tree's vendor carries dev dependencies (PHPUnit and its tree), which must
# never reach a release.
rsync -a --exclude='.git' --exclude='.claude' --exclude='.github' --exclude='.gitignore' --exclude='.idea' --exclude='.DS_Store' --exclude='.phpunit.result.cache' --exclude='.wp-env.json' --exclude='.wp-env.override.json' --exclude='.wordpress-org' --exclude='build' --exclude='composer.json' --exclude='composer.lock' --exclude='phpunit.xml.dist' --exclude='tests' --exclude='vendor' ./ "$BUILD_DIR/"

# The Blink Debit SDK is a runtime dependency, so it ships inside the zip:
# WordPress installs plugins as files, with no Composer step on the site.
# composer.json and composer.lock stay in the zip: Plugin Check requires a
# manifest beside a shipped vendor directory, and they document exactly which
# dependency versions a released build contains.
cp composer.json composer.lock "$BUILD_DIR/"
composer install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir="$BUILD_DIR"

# Drop what the plugin never loads. The SDK has no .gitattributes, so
# Composer's dist carries its test suites and framework config; and once this
# plugin injects its own transport and token cache, the framework glue and the
# cURL transport are unreachable. Shipping neither keeps the zip small and
# keeps code the plugin never executes out of a payments release.
SDK_DIR="$BUILD_DIR/vendor/blinkpay-nz/blink-debit-api-client-php"
rm -rf "$SDK_DIR/test" "$SDK_DIR/test-frameworks" "$SDK_DIR/config" "$SDK_DIR/.github"
rm -rf "$SDK_DIR/src/Laravel" "$SDK_DIR/src/Symfony" "$SDK_DIR/src/CakePHP" "$SDK_DIR/src/Psr"
rm -f "$SDK_DIR/src/CurlTransport.php"
rm -f "$SDK_DIR/.gitignore" "$SDK_DIR/phpstan.neon.dist" "$SDK_DIR/phpunit.xml.dist" "$SDK_DIR/sonar-project.properties"

# Fail the build rather than ship a plugin that fatals on activation, should a
# future SDK version start depending on something pruned above.
php -r '
    define( "ABSPATH", __DIR__ );
    require "'"$BUILD_DIR"'/vendor/autoload.php";
    require "'"$BUILD_DIR"'/includes/class-wc-blinkpay-http-transport.php";
    require "'"$BUILD_DIR"'/includes/class-wc-blinkpay-token-cache.php";
    new BlinkPay\BlinkDebit\BlinkDebitClient(
        "id",
        "secret",
        true,
        new WC_BlinkPay_Token_Cache(),
        new WC_BlinkPay_HTTP_Transport()
    );
'
