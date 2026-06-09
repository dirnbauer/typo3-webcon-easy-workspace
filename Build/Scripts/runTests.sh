#!/usr/bin/env bash

#
# Test runner for webcon_easy_workspace.
#
# Runs against the locally installed composer dependencies — no
# container orchestration needed. Functional tests default to sqlite
# (export typo3DatabaseDriver etc. to target a real DB server). The
# CI matrix covers PHP versions; -p selects a locally installed PHP
# binary (e.g. php8.3) when one exists under that name.
#
# Usage: Build/Scripts/runTests.sh [-s suite] [-p php-version]
#
#   -s  unit | functional | lint | phpstan | ci   (default: ci)
#   -p  PHP version, e.g. 8.3 — uses the "php8.3" binary if found,
#       otherwise falls back to "php" with a warning.
#

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SUITE="ci"
PHP_BIN="php"

while getopts "s:p:h" OPT; do
  case "${OPT}" in
    s)
      SUITE="${OPTARG}"
      ;;
    p)
      if command -v "php${OPTARG}" >/dev/null 2>&1; then
        PHP_BIN="php${OPTARG}"
      else
        echo "WARNING: php${OPTARG} not found, using PHP $(php -r 'echo PHP_VERSION;') instead." >&2
      fi
      ;;
    h)
      grep '^#' "$0" | cut -c 3-
      exit 0
      ;;
    *)
      echo "Usage: $0 [-s unit|functional|lint|phpstan|ci] [-p php-version]" >&2
      exit 2
      ;;
  esac
done

cd "${ROOT_DIR}"

run_lint() {
  find Classes Configuration Tests -name '*.php' -print0 | xargs -0 -n1 -P4 "${PHP_BIN}" -l >/dev/null
  echo "PHP lint OK"
}

run_phpstan() {
  "${PHP_BIN}" vendor/bin/phpstan analyse --configuration=Build/phpstan/phpstan.neon --memory-limit=1G
}

run_unit() {
  "${PHP_BIN}" vendor/bin/phpunit -c Build/phpunit/UnitTests.xml
}

run_functional() {
  "${PHP_BIN}" vendor/bin/phpunit -c Build/phpunit/FunctionalTests.xml
}

case "${SUITE}" in
  lint)
    run_lint
    ;;
  phpstan)
    run_phpstan
    ;;
  unit)
    run_unit
    ;;
  functional)
    run_functional
    ;;
  ci)
    run_lint
    run_phpstan
    run_unit
    run_functional
    ;;
  *)
    echo "Unknown suite '${SUITE}'. Expected unit, functional, lint, phpstan, or ci." >&2
    exit 2
    ;;
esac
