#!/usr/bin/env bash

LOG="/var/www/sender/update.log"

{
  echo "----- $(date) -----"
  cd /var/www/sender || exit 1

  git fetch origin main
  git reset --hard origin/main
  git clean -fd

  echo "Updated successfully"
} >> "$LOG" 2>&1

