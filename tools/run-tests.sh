#!/bin/sh
# Runs the suite on several PHP versions in Docker, one container per version.
#
# Each version gets its own throwaway copy of the package in .tests-run, so
# Composer picks the PHPUnit that version supports without the package's own
# vendor/ and composer.lock being touched. The tests then run with the network
# switched off, which proves the suite never reaches api.cryptoway.com or
# anything else.
#
#     sh tools/run-tests.sh                 # 8.1 8.2 8.3 8.4 8.5
#     sh tools/run-tests.sh 8.1 8.4         # only those

set -eu

# Git Bash on Windows rewrites container paths such as -w /app into Windows ones,
# and docker then refuses the working directory.
export MSYS_NO_PATHCONV=1

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VERSIONS=${*:-"8.1 8.2 8.3 8.4 8.5"}
WORK="$ROOT/.tests-run"
FAILED=""

for version in $VERSIONS; do
	printf '\n=== PHP %s ===\n' "$version"

	rm -rf "$WORK"
	mkdir -p "$WORK"
	cp "$ROOT/composer.json" "$ROOT/phpunit.xml.dist" "$WORK/"
	cp -R "$ROOT/src" "$ROOT/tests" "$WORK/"

	# The target version is declared in a throwaway global config, so Composer
	# resolves for it without composer.json being edited.
	docker run --rm -v "$WORK:/app" -w /app -e COMPOSER_HOME=/tmp/composer composer:2 sh -c \
		"mkdir -p /tmp/composer \
		 && printf '{\"config\":{\"platform\":{\"php\":\"%s.99\"}}}' '${version}' > /tmp/composer/config.json \
		 && composer update --no-interaction --no-progress --quiet"

	if docker run --rm --network none -v "$WORK:/app" -w /app "php:${version}-cli" \
		php vendor/bin/phpunit; then
		printf 'PHP %s: pass\n' "$version"
	else
		printf 'PHP %s: FAIL\n' "$version"
		FAILED="$FAILED $version"
	fi
done

rm -rf "$WORK"

if [ -n "$FAILED" ]; then
	printf '\nFailed on:%s\n' "$FAILED"
	exit 1
fi

printf '\nAll versions passed.\n'
