#!/bin/bash
# Pre-deploy backup of the live app: DB dump + code tarball + live commit + asset manifest.
# Run ON THE SERVER, and only as part of an approved release (DEPLOYMENT.md step 4).
# It changes nothing except creating a new folder under ~/backups:
#   scp docs/ai-workflow/scripts/predeploy-backup.sh metasoft-production:predeploy-backup.sh
#   ssh metasoft-production 'bash ~/predeploy-backup.sh <label>'
#
# Prints no secrets. DB credentials are read from the app's own config into a
# mode-600 option file inside the (mode-700) backup folder and shredded on exit,
# whether the script succeeds or fails. Note: code-live.tar.gz contains the live
# .env, so the backup folder itself is sensitive. Never copy it off the server
# or into the repo.
# The last line is "BACKUP OK: <dir>" on success; anything else means failure.
set -euo pipefail

LABEL="${1:?usage: predeploy-backup.sh <label>  (letters, digits, . _ - only)}"
[[ "$LABEL" =~ ^[A-Za-z0-9._-]{1,60}$ ]] || { echo "BACKUP FAILED: invalid label" >&2; exit 1; }

LIVE=~/domains/metasoftbd.com/apps/shopsaas
GIT=~/domains/metasoftbd.com/apps/shopsaas-git
B=~/backups/"$LABEL"-$(date -u +%Y%m%d-%H%M%S)
CNF="$B/.my.cnf"
DBF="$B/.dbname"

fail() { echo "BACKUP FAILED: $*" >&2; exit 1; }
cleanup() {
    if [ -e "$CNF" ]; then shred -u -z "$CNF" 2>/dev/null || rm -f "$CNF"; fi
    rm -f "$DBF"
    if [ -e "$CNF" ]; then echo "WARNING: $CNF could not be removed" >&2; fi
}
trap cleanup EXIT

umask 077
[ -d "$LIVE" ] && [ -d "$GIT" ] || fail "live or deploy checkout not found"
mkdir -p ~/backups "$B"
chmod 700 "$B"
cd "$LIVE"

git -C "$GIT" rev-parse HEAD > "$B/live-commit.txt"
cp public/build/manifest.json "$B/manifest.live.json"

# Credentials straight into the backup folder (never into $HOME), via env-provided paths.
MSBD_CNF="$CNF" MSBD_DBF="$DBF" php artisan tinker --execute='
$c = config("database.connections.".config("database.default"));
file_put_contents(getenv("MSBD_CNF"), "[client]\nhost=".$c["host"]."\nport=".$c["port"]."\nuser=".$c["username"]."\npassword=\"".addcslashes($c["password"], "\"\\\\")."\"\n");
file_put_contents(getenv("MSBD_DBF"), $c["database"]);
' < /dev/null > /dev/null 2>&1 || fail "could not read DB config"
[ -s "$CNF" ] && [ -s "$DBF" ] || fail "DB option file not created"
chmod 600 "$CNF"
DB=$(cat "$DBF")

mariadb-dump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --hex-blob "$DB" \
    | gzip -9 > "$B/db-full.sql.gz" || fail "mariadb-dump failed"
tar --exclude="./storage/logs" --exclude="./storage/framework/cache" --exclude="./storage/framework/sessions" --exclude="./storage/framework/views" \
    -czf "$B/code-live.tar.gz" -C "$LIVE" . || fail "code tar failed"

# Verify. Every check must pass; none of them is optional.
gzip -t "$B/db-full.sql.gz" || fail "db dump is not a valid gzip"
echo "db dump gzip: OK"
tar -tzf "$B/code-live.tar.gz" > /dev/null || fail "code tar is unreadable"
echo "code tar: OK"
zcat "$B/db-full.sql.gz" | tail -n 1 | grep -q 'Dump completed' || fail "dump trailer missing (dump incomplete)"
echo "dump trailer: complete"
LIVE_TABLES=$(mariadb --defaults-extra-file="$CNF" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'" "$DB") \
    || fail "could not count live tables"
DUMP_TABLES=$(zcat "$B/db-full.sql.gz" | grep -c '^CREATE TABLE' || true)
echo "tables live/dump: $LIVE_TABLES/$DUMP_TABLES"
[ "$LIVE_TABLES" -gt 0 ] && [ "$LIVE_TABLES" = "$DUMP_TABLES" ] || fail "table count mismatch"

echo "live commit: $(cat "$B/live-commit.txt")"
ls -la "$B" | awk 'NR>1 && $9 !~ /^\.my\.cnf$|^\.dbname$/ {print $1, $5, $9}'
echo "BACKUP OK: $B"
