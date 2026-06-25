#!/usr/bin/env bash
# Собрать установочный архив модуля PrestaShop: onecatalog-prestashop-<ver>.zip
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep -oE "version = '[0-9.]+'" onecatalogimport/onecatalogimport.php | head -1 | grep -oE '[0-9.]+')
out="onecatalog-prestashop-${ver:-dev}.zip"
rm -f "$out"
zip -rq "$out" onecatalogimport -x '*.DS_Store'
echo "✔ $out"
