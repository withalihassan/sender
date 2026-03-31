#!/usr/bin/env bash
set -euo pipefail

cd /var/www/sender
git fetch origin main
git reset --hard origin/main
git clean -fd