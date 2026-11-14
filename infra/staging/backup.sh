#!/usr/bin/env bash
# Nightly staging database backup with an encrypted off-server copy (backlog E-03).
# Runs on the server as user `deploy` (systemd: hara-backup.timer). Read
# docs/operations/staging-setup.md, section 11, before changing anything.
#
# Steps: pg_dump (custom format) -> verify with pg_restore --list -> encrypt
# with age (public key only; the private key is never on this server) ->
# upload with rclone -> verify the upload -> prune off-server copies older than
# 30 days (by the date in the file name) -> keep the newest 7 dumps locally.
#
# Usage: backup.sh [--no-upload]
# Exit status: 0 only if every step passed. Output goes to stdout/stderr, which
# systemd sends to the journal. No secret is printed: the database password
# never leaves the container, and the only file read from /etc is a PUBLIC key.
#
# Settings (environment; the defaults are the staging layout):
#   COMPOSE_DIR        /srv/hara/infra/staging     directory with compose.yaml and .env
#   BACKUP_DIR         /srv/hara-backups           local copies (must exist, mode 0700)
#   AGE_RECIPIENT_FILE /etc/hara/backup.age.pub    age public key (one line, age1...)
#   RCLONE_REMOTE      r2:hara-staging-backups     rclone remote:bucket[/path]
#   KEEP_LOCAL         7                           local dumps to keep
#   KEEP_REMOTE_DAYS   30                          days to keep off-server
#   RCLONE, AGE        command names (override for tests)
set -Eeuo pipefail
umask 077

COMPOSE_DIR=${COMPOSE_DIR:-/srv/hara/infra/staging}
BACKUP_DIR=${BACKUP_DIR:-/srv/hara-backups}
AGE_RECIPIENT_FILE=${AGE_RECIPIENT_FILE:-/etc/hara/backup.age.pub}
RCLONE_REMOTE=${RCLONE_REMOTE:-r2:hara-staging-backups}
KEEP_LOCAL=${KEEP_LOCAL:-7}
KEEP_REMOTE_DAYS=${KEEP_REMOTE_DAYS:-30}
RCLONE=${RCLONE:-rclone}
AGE=${AGE:-age}
UPLOAD=1
PARTIAL=""
ENCRYPTING=""

log() { printf '%s backup: %s\n' "$(date -u +%FT%TZ)" "$*"; }
die() { log "FAILED: $*" >&2; exit 1; }
on_exit() {
    rc=$?
    rm -f -- "$PARTIAL" "$ENCRYPTING"
    if [ "$rc" -ne 0 ]; then log "FAILED (exit $rc); do not rely on this run" >&2; fi
    exit "$rc"
}
trap on_exit EXIT

for arg in "$@"; do
    case "$arg" in
        --no-upload) UPLOAD=0 ;;
        *) die "unknown argument: $arg" ;;
    esac
done

command -v docker >/dev/null || die "docker not found"
command -v flock >/dev/null || die "flock not found (util-linux)"
command -v "$AGE" >/dev/null || die "age not found (sudo apt install age)"
if [ "$UPLOAD" -eq 1 ]; then
    command -v "$RCLONE" >/dev/null || die "rclone not found (sudo apt install rclone)"
fi
[ -d "$BACKUP_DIR" ] || die "$BACKUP_DIR does not exist"
[ -s "$AGE_RECIPIENT_FILE" ] || die "age public key $AGE_RECIPIENT_FILE is missing or empty"
if grep -q 'AGE-SECRET-KEY' "$AGE_RECIPIENT_FILE"; then die "$AGE_RECIPIENT_FILE holds a PRIVATE key. Remove it from the server."; fi
grep -q '^age1' "$AGE_RECIPIENT_FILE" || die "$AGE_RECIPIENT_FILE does not hold an age public key (it must start with age1)"

# One run at a time.
exec 9>"$BACKUP_DIR/.backup.lock"
flock -n 9 || die "another backup is running"

cd "$COMPOSE_DIR"
dc() { docker compose "$@"; }

# Release label: the RELEASE value of the running stack (only this one key is
# read from .env), restricted to safe file-name characters.
RELEASE_LABEL=unknown
if [ -r .env ]; then
    r=$(sed -n 's/^RELEASE=//p' .env | tail -n 1 | tr -cd 'A-Za-z0-9._-')
    if [ -n "$r" ]; then RELEASE_LABEL=$r; fi
fi

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
NAME="hara-staging-${STAMP}-${RELEASE_LABEL}.dump"
FINAL="$BACKUP_DIR/$NAME"
PARTIAL="$FINAL.partial"

log "dumping database (release $RELEASE_LABEL) to $NAME"
dc exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' >"$PARTIAL"
[ -s "$PARTIAL" ] || die "the dump is empty"

# A table of contents that lists is the cheapest proof the archive is readable.
ENTRIES=$(dc exec -T postgres pg_restore --list <"$PARTIAL" | grep -vc '^;' || true)
[ "${ENTRIES:-0}" -gt 0 ] || die "pg_restore --list found no entries in the dump"
mv -- "$PARTIAL" "$FINAL"
PARTIAL=""
chmod 600 -- "$FINAL"
SIZE=$(wc -c <"$FINAL" | tr -d ' ')
SHA=$(sha256sum "$FINAL" | cut -d' ' -f1)
log "dump ok: $SIZE bytes, $ENTRIES archive entries, sha256 $SHA"

# Keep the newest KEEP_LOCAL files matching a glob (names sort by UTC time).
prune_local() {
    # shellcheck disable=SC2012
    ls -1 "$BACKUP_DIR"/$1 2>/dev/null | sort -r | tail -n +"$((KEEP_LOCAL + 1))" | while read -r old; do
        rm -f -- "$old" && log "removed old local file $(basename "$old")"
    done
}

ENCRYPTING="$FINAL.age.partial"
"$AGE" --encrypt --recipients-file "$AGE_RECIPIENT_FILE" --output "$ENCRYPTING" "$FINAL"
[ -s "$ENCRYPTING" ] || die "encryption produced an empty file"
mv -- "$ENCRYPTING" "$FINAL.age"
ENCRYPTING=""
log "encrypted: $NAME.age"

if [ "$UPLOAD" -eq 0 ]; then
    log "--no-upload: skipping the off-server copy and its pruning"
    prune_local 'hara-staging-*.dump'
    prune_local 'hara-staging-*.dump.age'
    log "done (LOCAL ONLY: no off-server copy was made)"
    exit 0
fi

"$RCLONE" copyto "$FINAL.age" "$RCLONE_REMOTE/$NAME.age" --s3-no-check-bucket
# copyto compares size and checksum; also confirm the name is listed.
"$RCLONE" lsf --files-only "$RCLONE_REMOTE" | grep -Fxq "$NAME.age" || die "uploaded file not found in $RCLONE_REMOTE"
log "uploaded and listed: $NAME.age"
rm -f -- "$FINAL.age"

# Off-server retention by the date in the name. Never prune when the listing
# fails, and always leave the 3 newest files whatever their age.
CUTOFF=$(date -u -d "-${KEEP_REMOTE_DAYS} days" +%Y%m%d)
LISTING=$("$RCLONE" lsf --files-only "$RCLONE_REMOTE") || die "could not list $RCLONE_REMOTE for pruning"
printf '%s\n' "$LISTING" | { grep -E '^hara-staging-[0-9]{8}T[0-9]{6}Z-.*\.dump\.age$' || true; } | sort -r | tail -n +4 | while read -r f; do
    d=${f#hara-staging-}
    d=${d%%T*}
    if [ "$d" -lt "$CUTOFF" ]; then
        "$RCLONE" deletefile "$RCLONE_REMOTE/$f" && log "removed off-server $f (older than ${KEEP_REMOTE_DAYS} days)"
    fi
done

prune_local 'hara-staging-*.dump'
log "done: $NAME uploaded to the off-server store"
