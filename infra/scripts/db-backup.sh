#!/bin/sh
# Runs INSIDE the postgres container. The task runner copies it in and runs it:
#   .\infra\scripts\dev.ps1 backup   (or the same make target)
#
# Writes a compressed, custom-format dump of the application database to
# /tmp/backup.dump inside the container and prints its size and checksum.
# The task runner then copies the file out to infra/backups/ (git-ignored),
# records its checksum beside it, and deletes the copy inside the container.
#
# The dump contains personal data (accounts, addresses). Treat the file like
# the database itself: do not commit it, share it or leave copies around.
set -eu

# Readable by this user only: the dump is as sensitive as the database.
umask 077

OUT=/tmp/backup.dump
rm -f "$OUT"

pg_dump --format=custom --no-owner --no-privileges \
    --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --file "$OUT"

echo "database: $POSTGRES_DB"
echo "bytes: $(wc -c < "$OUT" | tr -d ' ')"
echo "sha256: $(sha256sum "$OUT" | cut -d' ' -f1)"
