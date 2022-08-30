#!/usr/bin/env bash

source ./bin/set_vars.sh

# Public: zips up the contents of the src/ directory into dist/nettle-pay-for-woocommerce.zip
#
# Returns exit code 0
function main {
  set_vars

  printf "%b removing any previous dist/ directory \n" "${INFO_PREFIX}"
  rm -rf dist && mkdir dist

  printf "%b zipping up src/ directory \n" "${INFO_PREFIX}"
  (cd ./src && zip -r ../dist/nettle-pay-for-woocommerce.zip .)

  exit 0
}

# And so, it begins...
main
