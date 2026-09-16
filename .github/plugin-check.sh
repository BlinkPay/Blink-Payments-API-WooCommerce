#!/bin/sh
# Runs Plugin Check locally against the same tree the CI plugin-check job
# checks, so a release-blocking finding surfaces here rather than in CI.
#
# Three traps this exists to cover:
#  - `wp plugin check` exits 0 even when it reports errors, so the findings are
#    parsed here and turned into a non-zero exit.
#  - A run that fails outright (plugin not installed, container gone) prints
#    nothing to parse, which reads exactly like a clean run. Its exit status is
#    captured and reported rather than discarded, so a broken run cannot pass.
#  - Checking the repository root instead of the build output raises findings
#    about development files the zip never contains, so the mounted tree is
#    verified to be the built one before anything is reported.
set -eu
cd "$(dirname "$0")/.."

PLUGIN_SLUG=blinkpay-nz-for-woocommerce
WP_ENV="npx @wordpress/env"

CHECK_OUT=$(mktemp)
CHECK_ERR=$(mktemp)
trap 'rm -f "$CHECK_OUT" "$CHECK_ERR"' EXIT

.github/build-plugin.sh

# wp-env supplies the WordPress install Plugin Check needs. Starting an already
# started environment is a no-op, so this is safe to run every time.
$WP_ENV start >/dev/null 2>&1 || {
	echo "::error::plugin-check.sh: could not start wp-env. Is Docker running?" >&2
	exit 1
}

# The plugin directory wp-env serves must be the build output. .wp-env.json
# alone mounts the repository root, whose tests/ and phpunit.xml.dist would
# produce findings against files no release contains.
if $WP_ENV run cli test -e "wp-content/plugins/$PLUGIN_SLUG/tests" >/dev/null 2>&1; then
	echo "::error::plugin-check.sh: wp-env is serving the repository root, not the build output." >&2
	echo "Create .wp-env.override.json (it is gitignored) so the built tree is checked:" >&2
	echo '' >&2
	echo '  {' >&2
	echo '    "plugins": [' >&2
	echo '      "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip",' >&2
	echo '      "https://downloads.wordpress.org/plugin/plugin-check.latest-stable.zip",' >&2
	echo "      \"./build/$PLUGIN_SLUG\"" >&2
	echo '    ]' >&2
	echo '  }' >&2
	echo '' >&2
	echo "Then run: $WP_ENV start --update" >&2
	exit 1
fi

echo "Running Plugin Check against build/$PLUGIN_SLUG ..."

# set -e would abort here without a chance to explain, so the status is taken
# by hand. A non-zero status is the command failing, never a finding: Plugin
# Check reports findings on a zero exit, which is the whole reason this script
# parses its output.
set +e
$WP_ENV run cli wp plugin check "$PLUGIN_SLUG" --format=json >"$CHECK_OUT" 2>"$CHECK_ERR"
check_status=$?
set -e

if [ "$check_status" -ne 0 ]; then
	echo "::error::plugin-check.sh: 'wp plugin check' failed with exit status $check_status; no conclusion can be drawn about the plugin." >&2
	echo '--- stderr ---' >&2
	cat "$CHECK_ERR" >&2
	echo '--- stdout ---' >&2
	cat "$CHECK_OUT" >&2
	exit 1
fi

# Plugin Check emits one "FILE: <path>" line followed by a JSON array per file,
# so the stream is walked rather than decoded whole: a single array spanning two
# files is not valid JSON and would silently parse as nothing.
php -r '
	$raw = file_get_contents( $argv[1] );
	$file = "?";
	$errors = array();
	$warnings = array();
	$parse_failed = false;
	foreach ( preg_split( "/\r?\n/", $raw ) as $line ) {
		$line = trim( $line );
		if ( 0 === strpos( $line, "FILE:" ) ) {
			$file = trim( substr( $line, 5 ) );
			continue;
		}
		if ( "" === $line || "[" !== $line[0] ) {
			continue;
		}
		$findings = json_decode( $line, true );
		if ( ! is_array( $findings ) ) {
			$parse_failed = true;
			continue;
		}
		foreach ( $findings as $f ) {
			$entry = sprintf(
				"  %s:%s:%s  %s  %s",
				$file,
				isset( $f["line"] ) ? $f["line"] : "?",
				isset( $f["column"] ) ? $f["column"] : "?",
				isset( $f["code"] ) ? $f["code"] : "?",
				isset( $f["message"] ) ? $f["message"] : ""
			);
			if ( isset( $f["type"] ) && "ERROR" === strtoupper( $f["type"] ) ) {
				$errors[] = $entry;
			} else {
				$warnings[] = $entry;
			}
		}
	}
	if ( $parse_failed ) {
		fwrite( STDERR, "::error::plugin-check.sh: could not parse part of the Plugin Check output; treating as a failure.\n" );
		exit( 1 );
	}
	if ( $warnings ) {
		echo "Plugin Check warnings (", count( $warnings ), "):\n", implode( "\n", $warnings ), "\n";
	}
	if ( $errors ) {
		fwrite( STDERR, "::error::plugin-check.sh: Plugin Check reported " . count( $errors ) . " error(s); the WordPress.org release is gated on these:\n" );
		fwrite( STDERR, implode( "\n", $errors ) . "\n" );
		exit( 1 );
	}
	echo "Plugin Check: no errors.\n";
' -- "$CHECK_OUT"
