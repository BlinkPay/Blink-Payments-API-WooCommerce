#!/bin/sh
# Assembles the shippable plugin tree into build/blinkpay-nz-for-woocommerce,
# the plugin's canonical directory name regardless of the repository name.
# Shared by the package and plugin-check jobs so both operate on the same
# tree, with development files excluded.
set -eu
cd "$(dirname "$0")/.."

mkdir -p build/blinkpay-nz-for-woocommerce
rsync -a --exclude='.git' --exclude='.claude' --exclude='.github' --exclude='.gitignore' --exclude='.phpunit.result.cache' --exclude='.wp-env.json' --exclude='build' --exclude='composer.json' --exclude='composer.lock' --exclude='phpunit.xml.dist' --exclude='tests' --exclude='vendor' ./ build/blinkpay-nz-for-woocommerce/
