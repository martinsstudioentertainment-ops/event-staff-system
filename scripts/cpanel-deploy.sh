#!/bin/bash
# cPanel Git — deploy to main site + register subdomain (one click, shared config).
set -e

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CPANEL_USER="${CPANEL_USER:-olastofx}"

DESTS=(
  "/home/${CPANEL_USER}/public_html"
  "/home/${CPANEL_USER}/register.olasentra.com"
  "/home/${CPANEL_USER}/admin.olasentra.com"
)

for DEST in "${DESTS[@]}"; do
  /bin/mkdir -p "${DEST}"
  /bin/mkdir -p "${DEST}/storage/logs" "${DEST}/storage/backups/database" "${DEST}/storage/backups/weekly" "${DEST}/storage/branding" "${DEST}/storage/google"

  for dir in admin api assets cron database docs includes lang vendor; do
    if [ -d "${REPO}/${dir}" ]; then
      /bin/cp -R "${REPO}/${dir}" "${DEST}/"
    fi
  done

  if [ -d "${REPO}/storage" ]; then
    /bin/cp -R "${REPO}/storage/." "${DEST}/storage/"
  fi

  /bin/chmod 755 "${DEST}/storage" "${DEST}/storage/logs" "${DEST}/storage/backups" \
    "${DEST}/storage/backups/database" "${DEST}/storage/backups/weekly" 2>/dev/null || true

  for f in .htaccess sw.js config.production.example.php; do
    [ -f "${REPO}/${f}" ] && /bin/cp -f "${REPO}/${f}" "${DEST}/"
  done

  /bin/cp -f "${REPO}"/*.php "${DEST}/" 2>/dev/null || true
done

MAIN="/home/${CPANEL_USER}/public_html"
REG="/home/${CPANEL_USER}/register.olasentra.com"
ADM="/home/${CPANEL_USER}/admin.olasentra.com"

# Never overwrite config.php with the example template (that breaks the database).
if [ -f "${MAIN}/config.php" ]; then
  /bin/cp -f "${MAIN}/config.php" "${REG}/config.php"
  /bin/cp -f "${MAIN}/config.php" "${ADM}/config.php"
  echo "Synced config.php from public_html to register + admin."
else
  echo "WARNING: ${MAIN}/config.php is missing — create it manually (see config.production.example.php). Deploy did not create config.php."
fi

echo "Deployed to public_html, register.olasentra.com, and admin.olasentra.com"
