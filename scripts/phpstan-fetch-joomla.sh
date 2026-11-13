#!/usr/bin/env bash
# Populate ./joomla with the Joomla release the PHPStan job of code-quality.yml analyses against, so
# PHPStan resolves the framework classes locally and reports what CI reports. Keep the version and the
# checksum equal to the ones in that workflow.
set -euo pipefail
cd "$(dirname "$0")/.."
version=6.2.0
sha256=eaafc68b5990d7b9dd9156656d4c3c3547743d1d7a85682a92e2f9121b58f018
url="https://github.com/joomla/joomla-cms/releases/download/${version}/Joomla_${version}-Stable-Full_Package.zip"
zip=$(mktemp)
trap 'rm -f "$zip"' EXIT
echo "Fetching $url"
curl -fsSL "$url" -o "$zip"
actual=$( (sha256sum "$zip" 2>/dev/null || shasum -a 256 "$zip") | cut -d ' ' -f 1)
if [ "$actual" != "$sha256" ]; then
  echo "Checksum mismatch: expected $sha256, got $actual" >&2
  exit 1
fi
rm -rf joomla && mkdir joomla && unzip -q "$zip" -d joomla
echo "Joomla $version ready in ./joomla"
