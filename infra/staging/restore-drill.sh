#!/usr/bin/env bash
# Restore drill for the staging backups (backlog E-03; Phase 1 gate: "staging
# database backup restored successfully"). Downloads an off-server copy,
# VERIFIES ITS SIGNATURE, decrypts it, restores it into a THROWAWAY container
# (no network, own data directory, removed afterwards), runs sanity queries.
# It never touches the live postgres container, its volume or its network.
#
# Why: the R2 token lives on the server and the age recipient is public, so
# whoever gets the token can upload a crafted archive. A restore as superuser in
# the live container could then run code (COPY ... FROM PROGRAM). Two defences:
#   1. authenticity: backup.sh signs every .dump.age with a dedicated SSH key
#      (ssh-keygen -Y sign, namespace hara-backup). This script refuses a file
#      with a missing or invalid signature BEFORE decrypting it.
#   2. isolation: the restore runs in `docker run --network none` from the same
#      postgis image as production, as a NON-superuser role (see below).
# Not covered: an attacker with access to the server can read the signing key
# and sign a crafted file. That is accepted; the isolation in 2 still applies.
#
# Usage: restore-drill.sh [--file <name>.dump.age] [--local <path>.dump.age]
#   no argument     the newest file in the off-server store
#   --file NAME     a given file in the off-server store
#   --local PATH    an encrypted file already on this machine (PATH.sig must exist)
#
# The age PRIVATE key is not kept on the server. Supply it only for the drill:
#   AGE_IDENTITY=/dev/shm/backup.age.key  (copy it there, delete it afterwards)
# Settings (environment):
#   ALLOWED_SIGNERS  /etc/hara/backup-signing/allowed_signers  (public key; required)
#   COMPOSE_DIR      /srv/hara/infra/staging  (only compose.yaml is read, for the image tag)
#   DRILL_IMAGE      override the image (default: the postgis image in compose.yaml)
#   RCLONE_REMOTE, RCLONE, AGE as in backup.sh.
#
# Restore as a non-superuser: the database is owned by role drill_owner
# (NOSUPERUSER). The postgis extension needs superuser, so it is created first,
# as the container's superuser, in the empty database (the only superuser step
# and it uses no dump content). The archive's EXTENSION entries are then left
# out with pg_restore -L; an archive that needs any other extension is refused.
# pg_restore runs as drill_owner with --no-owner --no-privileges --exit-on-error,
# so an archive cannot use superuser-only commands (COPY ... PROGRAM, file
# access, CREATE EXTENSION, ALTER SYSTEM) and anything else stays inside a
# container that has no network.
set -Eeuo pipefail
umask 077

COMPOSE_DIR=${COMPOSE_DIR:-/srv/hara/infra/staging}
RCLONE_REMOTE=${RCLONE_REMOTE:-r2:hara-staging-backups}
AGE_IDENTITY=${AGE_IDENTITY:-}
ALLOWED_SIGNERS=${ALLOWED_SIGNERS:-/etc/hara/backup-signing/allowed_signers}
DRILL_IMAGE=${DRILL_IMAGE:-}
RCLONE=${RCLONE:-rclone}
AGE=${AGE:-age}
SIGN_NAMESPACE=hara-backup
SIGN_IDENTITY=hara-backup

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
command -v ssh-keygen >/dev/null || die "ssh-keygen not found (sudo apt install openssh-client)"
command -v "$AGE" >/dev/null || die "age not found (sudo apt install age)"
[ -s "$ALLOWED_SIGNERS" ] || die "ALLOWED_SIGNERS file $ALLOWED_SIGNERS is missing or empty; refusing to restore an unsigned backup"
if [ -z "$AGE_IDENTITY" ] || [ ! -r "$AGE_IDENTITY" ]; then
    die "set AGE_IDENTITY to a readable age private key file (see staging-setup.md)"
fi

# Image: the same postgis image the live database runs (read from compose.yaml).
if [ -z "$DRILL_IMAGE" ]; then
    DRILL_IMAGE=$(sed -n 's/^[[:space:]]*image:[[:space:]]*\(postgis\/postgis:[^[:space:]]*\).*/\1/p' "$COMPOSE_DIR/compose.yaml" | head -n 1)
fi
[ -n "$DRILL_IMAGE" ] || die "could not read the postgis image tag from $COMPOSE_DIR/compose.yaml"

WORK=$(mktemp -d "${TMPDIR:-/tmp}/hara-drill.XXXXXX")
CONTAINER="hara-drill-$(date -u +%Y%m%d%H%M%S)-$$"
STARTED=0

cleanup() {
    rc=$?
    # Remove only the container this run started, and only one with the drill prefix.
    if [ "$STARTED" -eq 1 ]; then
        case "$CONTAINER" in
            hara-drill-*)
                if docker rm -f -v "$CONTAINER" >/dev/null 2>&1; then
                    log "throwaway container $CONTAINER removed"
                else
                    log "WARNING: could not remove $CONTAINER; run: docker rm -f -v $CONTAINER" >&2
                fi ;;
        esac
    fi
    rm -rf -- "$WORK"
    if [ "$rc" -ne 0 ]; then log "DRILL FAILED (exit $rc)" >&2; fi
    exit "$rc"
}
trap cleanup EXIT

if [ -n "$LOCAL" ]; then
    ENC=$LOCAL
    SIG=$LOCAL.sig
    [ -s "$ENC" ] || die "$ENC not found"
else
    command -v "$RCLONE" >/dev/null || die "rclone not found (sudo apt install rclone)"
    if [ -z "$FILE" ]; then
        FILE=$("$RCLONE" lsf --files-only "$RCLONE_REMOTE" | { grep -E '^hara-staging-[0-9]{8}T[0-9]{6}Z-.*\.dump\.age$' || true; } | sort | tail -n 1)
        [ -n "$FILE" ] || die "no backup found in $RCLONE_REMOTE"
    fi
    case "$FILE" in */* | *..*) die "file name must not contain a path" ;; esac
    log "downloading $FILE and $FILE.sig"
    "$RCLONE" copyto "$RCLONE_REMOTE/$FILE" "$WORK/in.age" --s3-no-check-bucket
    "$RCLONE" copyto "$RCLONE_REMOTE/$FILE.sig" "$WORK/in.age.sig" --s3-no-check-bucket || die "signature $FILE.sig could not be downloaded; refusing an unsigned backup"
    ENC=$WORK/in.age
    SIG=$WORK/in.age.sig
fi

# Authenticity first. Nothing below runs on a file that fails this.
[ -s "$SIG" ] || die "signature file $SIG is missing; refusing an unsigned backup"
if ssh-keygen -Y verify -f "$ALLOWED_SIGNERS" -I "$SIGN_IDENTITY" -n "$SIGN_NAMESPACE" -s "$SIG" <"$ENC" >/dev/null 2>&1; then
    log "signature verified against $ALLOWED_SIGNERS"
else
    die "signature check FAILED for ${FILE:-$ENC}; refusing to decrypt or restore it"
fi

log "decrypting"
"$AGE" --decrypt --identity "$AGE_IDENTITY" --output "$WORK/db.dump" "$ENC"
[ -s "$WORK/db.dump" ] || die "decrypted file is empty"

# Throwaway server: no network, random superuser password nobody keeps. The data
# directory is the image anonymous volume; --rm and rm -f -v in the trap delete it.
PGPASS=$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')
log "starting throwaway container $CONTAINER from $DRILL_IMAGE (--network none)"
docker run --rm -d --network none --name "$CONTAINER" \
    -e POSTGRES_PASSWORD="$PGPASS" \
    "$DRILL_IMAGE" >/dev/null
STARTED=1
unset PGPASS

# Ready means the FINAL server accepts TCP (the init-phase server listens on the
# socket only, so a socket check would return too early).
READY=0
for _ in $(seq 1 90); do
    if docker exec "$CONTAINER" pg_isready -q -h 127.0.0.1 -U postgres 2>/dev/null; then READY=1; break; fi
    sleep 1
done
[ "$READY" -eq 1 ] || die "throwaway postgres did not become ready in 90 s"

# In the container: as the OS user postgres (local socket, superuser only for setup).
SU() { docker exec -i -u postgres "$CONTAINER" "$@"; }

SU sh -c 'umask 077; cat > /tmp/db.dump' <"$WORK/db.dump"

# Only the postgis extension is acceptable in the archive.
SU pg_restore --list /tmp/db.dump >"$WORK/toc.txt" || die "pg_restore --list could not read the archive"
BAD=$({ grep -E '^[0-9]+; [0-9]+ [0-9]+ EXTENSION ' "$WORK/toc.txt" || true; } | grep -Evc ' EXTENSION - (postgis|plpgsql) *$' || true)
[ "${BAD:-0}" -eq 0 ] || die "the archive wants an extension other than postgis/plpgsql; refusing"
# Also left out: the rows of postgis's own spatial_ref_sys (CREATE EXTENSION fills it; it is owned
# by the superuser, so drill_owner may not write to it).
grep -vE '^[0-9]+; [0-9]+ [0-9]+ ((COMMENT - )?EXTENSION |TABLE DATA public spatial_ref_sys )' "$WORK/toc.txt" >"$WORK/restore.list"
SU sh -c 'umask 077; cat > /tmp/restore.list' <"$WORK/restore.list"
ENTRIES=$(grep -vc '^;' "$WORK/restore.list" || true)
[ "${ENTRIES:-0}" -gt 0 ] || die "the archive has no restorable entries"

log "creating non-superuser role drill_owner and database drill"
SU psql -U postgres -d postgres --no-psqlrc -q -v ON_ERROR_STOP=1 \
    -c "CREATE ROLE drill_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS" \
    -c "CREATE DATABASE drill OWNER drill_owner TEMPLATE template0"
SU psql -U postgres -d drill --no-psqlrc -q -v ON_ERROR_STOP=1 \
    -c "CREATE EXTENSION IF NOT EXISTS postgis"

log "restoring as drill_owner (no superuser) with --no-owner --no-privileges --exit-on-error"
START=$(date +%s)
SU pg_restore -U drill_owner -d drill --no-owner --no-privileges --exit-on-error -L /tmp/restore.list /tmp/db.dump
log "restore time: $(( $(date +%s) - START ))s"

q() { SU psql -U drill_owner -d drill --no-psqlrc -tAq -v ON_ERROR_STOP=1 -c "$1"; }
SUPER=$(q "SELECT rolsuper FROM pg_roles WHERE rolname = current_user")
[ "$SUPER" = "f" ] || die "restore role is a superuser; the drill is not isolated as designed"
TABLES=$(q "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' AND table_name<>'spatial_ref_sys'")
MIGRATIONS=$(q "SELECT count(*) FROM migrations")
LAST=$(q "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")
log "tables in public: $TABLES; migrations recorded: $MIGRATIONS; latest: $LAST"
[ "$TABLES" -gt 0 ] || die "no tables restored"
[ "$MIGRATIONS" -gt 0 ] || die "migration ledger is empty"
log "RESTORE DRILL PASSED"
