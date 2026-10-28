#!/bin/bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
source scripts/runtime.sh
panel_check_runtime

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

current_branch=$(git branch --show-current)
if [ -z "$current_branch" ]; then
    echo "Unable to determine the current Git branch."
    exit 1
fi
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Tracked files have local changes. Review them before updating." >&2
    exit 1
fi
git fetch origin "$current_branch"
git merge --ff-only "origin/$current_branch"
source scripts/runtime.sh
panel_check_runtime
panel_install_dependencies

"$PHP_BIN" artisan v2board:update
"$PHP_BIN" scripts/apply-community-site.php

if [ -f "/etc/init.d/bt" ]; then
  chown -R www "$PWD"
fi
