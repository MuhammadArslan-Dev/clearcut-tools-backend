#!/bin/bash

# Container entrypoint (set as [start] cmd in nixpacks.toml).

# Ensure storage and bootstrap cache are writable by www-data
chown -R www-data:www-data /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache

# Transform the nginx configuration
node /assets/scripts/prestart.mjs /app/deploy/nginx.template.conf /etc/nginx.conf

# Start supervisor in the foreground (PID 1) so Coolify's stop/redeploy
# signals reach it and it can stop the child processes gracefully.
exec supervisord -c /etc/supervisord.conf -n
