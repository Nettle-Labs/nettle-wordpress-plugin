#!/usr/bin/env bash

source ./bin/set_vars.sh

# Public: Updates the src/README.txt version
#
# $1 - The version to bump.
#
# Examples
#
#   ./bin/update_readme.sh "1.0.0"
#
# Returns exit code 0 if successful, or 1 if the semantic version is incorrectly formatted.
function main {
  set_vars

  if [ -z "${1}" ]; then
    printf "%b no version specified, use: ./bin/update_readme.sh [version] \n" "${ERROR_PREFIX}"
    exit 1
  fi

  # check the input is in semantic version format
  if [[ ! "${1}" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
    printf "%b invalid semantic version, got '${1}', but should be in the format '1.0.0' \n" "${ERROR_PREFIX}"
    exit 1
  fi

  printf "%b updating readme.txt to version '%s' \n" "${INFO_PREFIX}" "${1}"
  sed -i -e "s/Stable tag:.*/Stable tag: ${1}/" src/README.txt

  exit 0
}

# And so, it begins...
main "$1"
