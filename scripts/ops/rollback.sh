#!/bin/bash
# Usage: bash rollback.sh /home/olastofx/backups/pre-merge-YYYYMMDD-HHMM [--db]
# Restores public_html files and the server repo HEAD; with --db also restores the database dump.
set -euo pipefail
B=${1:?backup dir}; DB=${2:-}
REPO=/home/olastofx/repositories/event-staff-system
cd "$REPO" && git reset --hard "$(cat "$B/server-repo-HEAD.txt")"
mkdir -p /tmp/rb && tar -xzf "$B/public_html.tgz" -C /tmp/rb
rsync -a --delete --exclude 'storage/logs/' --exclude 'uploads/' /tmp/rb/public_html/ /home/olastofx/public_html/
rm -rf /tmp/rb
if [ "$DB" = "--db" ]; then
  umask 077; CNF=$(mktemp)
  php -r '$c=file_get_contents("/home/olastofx/public_html/config.php"); foreach(["DB_HOST","DB_USER","DB_PASS"] as $k){ preg_match("/define\(\s*.".$k.".\s*,\s*([\x27\x22])(.*?)\\1\s*\)/s",$c,$m); $v[$k]=$m[2]??""; } echo "[client]\nhost=".$v["DB_HOST"]."\nuser=".$v["DB_USER"]."\npassword=\"".addcslashes($v["DB_PASS"],"\\\"")."\"\n";' > "$CNF"
  gunzip -c "$B/olastofx_eventstaff.sql.gz" | mysql --defaults-extra-file="$CNF" olastofx_eventstaff
  rm -f "$CNF"
fi
echo "Rolled back to $(cat "$B/server-repo-HEAD.txt")"
