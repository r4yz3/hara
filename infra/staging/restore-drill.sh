#!/usr/bin/env bash
# Restore drill for the staging backups (backlog E-03; Phase 1 gate: "staging
# database backup restored successfully"). Downloads an off-server copy,
# decrypts it, restores it into a SCRATCH database inside the postgres
# container, runs sanity queries, drops the scratch database. It never writes
# to the live database.
#
# Usage: restore-drill.sh [--file <name>.dump.age] [--local <path>.dump.age]
#   no argument     the newest file in the off-server store
#   --file NAME     a given file in the off-server store
#   --local PATH    an encrypted file already on this machine (no download)
#
# The age PRIVATE key is not kept on the server. Supply it only for the drill:
#   AGE_IDENTITY=/dev/shm/backup.age.key  (copy it there, delete it afterwards)
# Other settings: COMPOSE_DIR, RCLONE_REMOTE, RCLONE, AGE as in backup.sh.
set -Eeuo pipefail
umask 077

COMPOSE_DIR=${COMPOSE_DIR:-/srv/hara/infra/staging}
RCLONE_REMOTE=${RCLONE_REMOTE:-r2:hara-staging-backups}
AGE_IDENTITY=${AGE_IDENTITY:-}
RCLONE=${RCLONE:-rclone}
AGE=${AGE:-age}

FILE=""
LOCAL=""
while [ $# -gt 0 ]; do
    case "$1" in
        --file) FILE=${2:?--file needs a name}; shift 2 ;;
        --local) LOCAL=${2:?--local needs a path}; shift 2 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

log() { printf '%s drill: %s\n' "$(date -u +%FT%TZ)" "$*"; }
die() { log "FAILED: $*" >&2; exit 1; }

command -v docker >/dev/null || die "docker not found"
command -v "$AGE" >/dev/null || die "age not found (sudo apt install age)"
if [ -z "$AGE_IDENTITY" ] || [ ! -r "$AGE_IDENTITY" ]; then
    die "set AGE_IDENTITY to a readable age private key file (see staging-setup.md)"
fi

WORK=$(mktemp -d "${TMPDIR:-/tmp}/hara-drill.XXXXXX")
SCRATCH="hara_drill_$(date -u +%Y%m%d%H%M%S)"
CREATED=0
cd "$COMPOSE_DIR"
dc() { docker compose "$@"; }

cleanup() {
    rc=$?
    # Drop only a database this run created, and only one with the drill prefix.
    if [ "$CREATED" -eq 1 ]; then
        case "$SCRATCH" in
            hara_drill_*)
                if dc exec -T postgres sh -c "dropdb -U \"\$POSTGRES_USER\" --if-exists $SCRATCH" >/dev/null 2>&1; then
                    log "scratch database $SCRATCH dropped"
                else
                    log "WARNING: could not drop $SCRATCH; drop it by hand" >&2
                fi ;;
        esac
    fi
    dc exec -T postgres rm -f /tmp/hara-drill.dump >/dev/null 2>&1 || true
    rm -rf -- "$WORK"
    if [ "$rc" -ne 0 ]; then log "DRILL FAILED (exit $rc)" >&2; fi
    exit "$rc"
}
trap cleanup EXIT

if [ -n "$LOCAL" ]; then
    ENC=$LOCAL
    [ -s "$ENC" ] || die "$ENC not found"
else
    command -v "$RCLONE" >/dev/null || die "rclone not found (sudo apt install rclone)"
    if [ -z "$FILE" ]; then
        FILE=$("$RCLONE" lsf --files-only "$RCLONE_REMOTE" | { grep -E '^hara-staging-[0-9]{8}T[0-9]{6}Z-.*\.dump\.age$' || true; } | sort | tail -n 1)
        [ -n "$FILE" ] || die "no backup found in $RCLONE_REMOTE"
    fi
    case "$FILE" in */* | *..*) die "file name must not contain a path" ;; esac
    log "downloading $FILE"
    "$RCLONE" copyto "$RCLONE_REMOTE/$FILE" "$WORK/in.age" --s3-no-check-bucket
    ENC=$WORK/in.age
fi

log "decrypting"
"$AGE" --decrypt --identity "$AGE_IDENTITY" --output "$WORK/db.dump" "$ENC"
[ -s "$WORK/db.dump" ] || die "decrypted file is empty"

LIVE=$(dc exec -T postgres sh -c 'printf %s "$POSTGRES_DB"')
[ "$SCRATCH" != "$LIVE" ] || die "scratch name equals the live database"

log "restoring into scratch database $SCRATCH"
dc exec -T postgres sh -c 'cat > /tmp/hara-drill.dump' <"$WORK/db.dump"
dc exec -T postgres sh -c "createdb -U \"\$POSTGRES_USER\" $SCRATCH"
CREATED=1
START=$(date +%s)
dc exec -T postgres sh -c "pg_restore -U \"\$POSTGRES_USER\" -d $SCRATCH --no-owner --no-privileges --exit-on-error /tmp/hara-drill.dump"
log "restore time: $(( $(date +%s) - START ))s"

q() { dc exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d $SCRATCH --no-psqlrc -tAq -v ON_ERROR_STOP=1 -c \"$1\""; }
TABLES=$(q "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' AND table_name<>'spatial_ref_sys'")
MIGRATIONS=$(q "SELECT count(*) FROM migrations")
LAST=$(q "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")
log "tables in public: $TABLES; migrations recorded: $MIGRATIONS; latest: $LAST"
[ "$TABLES" -gt 0 ] || die "no tables restored"
[ "$MIGRATIONS" -gt 0 ] || die "migration ledger is empty"
log "RESTORE DRILL PASSED"
