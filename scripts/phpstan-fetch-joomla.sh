#!/usr/bin/env bash
# Populate ./joomla with the latest Joomla so PHPStan can resolve the framework
# classes locally (CI does this automatically — see code-quality.yml).
set -euo pipefail
cd "$(dirname "$0")/.."
url=$(curl -sL https://api.github.com/repos/joomla/joomla-cms/releases/latest \
  | python3 -c "import sys,json;print(next(a['browser_download_url'] for a in json.load(sys.stdin)['assets'] if a['name'].endswith('Full_Package.zip')))")
echo "Fetching $url"
curl -sL "$url" -o /tmp/joomla.zip
rm -rf joomla && mkdir joomla && unzip -q /tmp/joomla.zip -d joomla
echo "Joomla ready in ./joomla"
