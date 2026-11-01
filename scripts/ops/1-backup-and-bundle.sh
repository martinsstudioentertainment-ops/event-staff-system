#!/bin/bash
# Run in cPanel Terminal BEFORE deploying. Prints only paths and commit ids (no secrets).
set -euo pipefail
TS=$(date +%Y%m%d-%H%M)
B=/home/olastofx/backups/pre-merge-$TS
REPO=/home/olastofx/repositories/event-staff-system
mkdir -p "$B" && chmod 700 "$B"
cd "$REPO"
git rev-parse HEAD > "$B/server-repo-HEAD.txt"
git log --oneline -15 > "$B/server-repo-log.txt"
git status --short > "$B/server-repo-status.txt"
# Kimi's server-only commits -> bundle (to push to GitHub branch kimi-homepage-og)
git fetch -q https://github.com/martinsstudioentertainment-ops/event-staff-system.git main:refs/remotes/gh/main || true
git bundle create "$B/kimi-commits.bundle" gh/main..HEAD || git bundle create "$B/kimi-commits.bundle" HEAD
git log --oneline gh/main..HEAD > "$B/kimi-commits.txt" || true
# Files
tar -czf "$B/public_html.tgz" -C /home/olastofx public_html
tar -czf "$B/server-repo.tgz" -C /home/olastofx/repositories event-staff-system
crontab -l > "$B/crontab.txt" 2>/dev/null || true
# DB (credentials read from config.php into a 600 temp file, never printed)
CNF="$B/.my.cnf"; umask 077
php -r 'define("EVENTSTAFF_CLI",1); $c=file_get_contents("/home/olastofx/public_html/config.php"); foreach(["DB_HOST","DB_USER","DB_PASS"] as $k){ preg_match("/define\(\s*.".$k.".\s*,\s*([\x27\x22])(.*?)\\1\s*\)/s",$c,$m); $v[$k]=$m[2]??""; } echo "[client]\nhost=".$v["DB_HOST"]."\nuser=".$v["DB_USER"]."\npassword=\"".addcslashes($v["DB_PASS"],"\\\"")."\"\n";' > "$CNF"
mysqldump --defaults-extra-file="$CNF" --single-transaction --routines --triggers olastofx_eventstaff | gzip > "$B/olastofx_eventstaff.sql.gz"
rm -f "$CNF"
ls -la "$B"; echo "BACKUP_DIR=$B"; cat "$B/kimi-commits.txt"
