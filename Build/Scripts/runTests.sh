#!/usr/bin/env bash
set -euo pipefail

SUITE="ci"

while getopts "s:p:" OPT; do
  case "${OPT}" in
    s)
      SUITE="${OPTARG}"
      ;;
    p)
      ;;
    *)
      echo "Usage: $0 [-s lint|phpstan|ci] [-p php-version]" >&2
      exit 2
      ;;
  esac
done

run_lint() {
  find Classes Configuration ext_emconf.php -name '*.php' -print0 | xargs -0 -n1 php -l
}

case "${SUITE}" in
  lint)
    run_lint
    ;;
  phpstan)
    vendor/bin/phpstan analyse --configuration=Build/phpstan/phpstan.neon --memory-limit=1G
    ;;
  ci)
    run_lint
    vendor/bin/phpstan analyse --configuration=Build/phpstan/phpstan.neon --memory-limit=1G
    ;;
  *)
    echo "Unknown suite '${SUITE}'. Expected lint, phpstan, or ci." >&2
    exit 2
    ;;
esac
