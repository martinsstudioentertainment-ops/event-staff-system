#!/bin/bash
# cPanel Git — deploy to public_html (+ optional mirror folders for subdomains).
set -u

REPO="$(cd "$(dirname "$0")/.." && pwd)"
CPANEL_USER="${CPANEL_USER:-olastofx}"

# Namecheap often uses /home/USER/register and /home/USER/admin (not register.olasentra.com).
DESTS=(
  "/home/${CPANEL_USER}/public_html"
  "/home/${CPANEL_USER}/register"
  "/home/${CPANEL_USER}/admin"
  "/home/${CPANEL_USER}/register.olasentra.com"
  "/home/${CPANEL_USER}/admin.olasentra.com"
)

deploy_to() {
  local DEST="$1"
  if ! /bin/mkdir -p "${DEST}" 2>/dev/null; then
    echo "SKIP (cannot create): ${DEST}"
    return 1
  fi

  /bin/mkdir -p "${DEST}/storage/logs" "${DEST}/storage/backups/database" \
    "${DEST}/storage/backups/weekly" "${DEST}/storage/branding" "${DEST}/storage/google" 2>/dev/null || true

  for dir in admin api assets cron database docs includes lang vendor; do
    if [ -d "${REPO}/${dir}" ]; then
      /bin/cp -R "${REPO}/${dir}" "${DEST}/" 2>/dev/null || {
        echo "WARN: failed to copy ${dir} -> ${DEST}"
        return 1
      }
    fi
  done

  if [ -d "${REPO}/storage" ]; then
    /bin/cp -R "${REPO}/storage/." "${DEST}/storage/" 2>/dev/null || true
  fi

  /bin/chmod 755 "${DEST}/storage" "${DEST}/storage/logs" "${DEST}/storage/backups" \
    "${DEST}/storage/backups/database" "${DEST}/storage/backups/weekly" 2>/dev/null || true

  for f in .htaccess sw.js config.production.example.php; do
    [ -f "${REPO}/${f}" ] && /bin/cp -f "${REPO}/${f}" "${DEST}/" 2>/dev/null || true
  done

  /bin/cp -f "${REPO}"/*.php "${DEST}/" 2>/dev/null || true
  echo "OK: ${DEST}"
  return 0
}

FAILED=0
for DEST in "${DESTS[@]}"; do
  deploy_to "${DEST}" || FAILED=1
done

MAIN="/home/${CPANEL_USER}/public_html"

if [ -f "${MAIN}/config.php" ]; then
  for DEST in "${DESTS[@]}"; do
    [ "${DEST}" = "${MAIN}" ] && continue
    /bin/cp -f "${MAIN}/config.php" "${DEST}/config.php" 2>/dev/null || true
  done
  echo "Synced config.php from public_html (where possible)."
else
  echo "WARNING: ${MAIN}/config.php missing — create it in public_html before deploy."
  FAILED=1
fi

if [ "${FAILED}" -ne 0 ]; then
  echo "NOTE: If register/admin folders are empty, point both subdomains to document root public_html in cPanel (see docs/SUBDOMAIN-FIX.md)."
fi

# After files are deployed: apply database/live-events-2026.php → MySQL (idempotent).
# Set SKIP_ROSTER_IMPORT=1 in deploy env to disable. If this fails, use Admin → Import master roster.
if [ "${SKIP_ROSTER_IMPORT:-0}" != "1" ] && [ -f "${MAIN}/database/sync-live-events.php" ] && [ -f "${MAIN}/config.php" ]; then
  PHP_BIN="php"
  if [ -x "/usr/local/bin/php" ]; then
    PHP_BIN="/usr/local/bin/php"
  elif [ -x "/opt/cpanel/ea-php82/root/usr/bin/php" ]; then
    PHP_BIN="/opt/cpanel/ea-php82/root/usr/bin/php"
  fi
  ROSTER_LOG="${MAIN}/storage/logs/roster-import.log"
  /bin/mkdir -p "${MAIN}/storage/logs" 2>/dev/null || true
  echo "Importing summer roster (live-events-2026.php) into database..."
  if (cd "${MAIN}" && "${PHP_BIN}" database/sync-live-events.php >> "${ROSTER_LOG}" 2>&1); then
    echo "OK: roster imported (events, venues, staff needed, times)."
    /bin/tail -n 3 "${ROSTER_LOG}" 2>/dev/null || true
  else
    echo "WARN: auto roster import failed — see ${ROSTER_LOG}"
    echo "WARN: log in to admin and open import-roster.php"
    /bin/tail -n 8 "${ROSTER_LOG}" 2>/dev/null || true
  fi
else
  echo "SKIP roster import (missing config/sync script or SKIP_ROSTER_IMPORT=1)."
fi

echo "Deploy finished."
