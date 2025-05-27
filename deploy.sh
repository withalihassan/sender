#!/bin/bash
# Navigate to your repository
cd /var/www/html

# Mark this directory as safe for Git (if not already set)
git config --global --add safe.directory /var/www/html

# Execute git pull and capture output
output=$(/usr/bin/git pull origin main 2>&1)

# Append the output and timestamp to deploy.txt
{
  echo "---- Deployment executed on $(date) ----"
  echo "$output"
  echo "-------------------------------------------"
} >> /var/www/html/deploy.txt

