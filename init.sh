#!/bin/bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source scripts/runtime.sh
panel_check_runtime
panel_install_dependencies

"$PHP_BIN" artisan v2board:install
"$PHP_BIN" scripts/apply-community-site.php

if [ -f "/etc/init.d/bt" ]; then
  chown -R www "$PWD"
fi
