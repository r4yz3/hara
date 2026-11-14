#!/usr/bin/env bash
# Fault-injection test for infra/staging/restore-drill.sh (backlog E-03).
#
# Proves the drill REFUSES a bad backup (non-zero exit, no restore started, no
# throwaway container or temp directory left behind) and still PASSES a good one.
# Runs on a workstation against the local Docker engine. It needs no server, no
# R2 and no real key: it builds its own tiny signed, encrypted backups.
#
# It never touches the development stack: the fixture database is built in its own
# throwaway container (no network, name hara-fixture-*), and every docker call the
# drill makes is recorded and checked to name only a hara-drill-* container.
#
# Stand-ins (used when the real tool is missing; the drill only calls these
# interfaces): `age --decrypt --identity F --output OUT IN` (openssl AES-256, the
# identity file is the passphrase) and `rclone lsf --files-only DIR` /
# `rclone copyto DIR/NAME DEST` (DIR is a local directory). Set USE_REAL_AGE=1 to
# use real age + age-keygen when both are installed.
#
# Usage: infra/staging/tests/restore-drill-faults.sh
# Environment: DRILL_IMAGE (default postgis/postgis:17-3.5-alpine; must be local),
#              KEEP=1 keeps the work directory. Exit 0 only if every check passed.
# On Windows run it through restore-drill-faults.ps1 (Git Bash, MSYS_NO_PATHCONV).
set -u
umask 077
HERE=$(cd "$(dirname "$0")" && pwd)
DRILL=${DRILL:-"$HERE/../restore-drill.sh"}   # DRILL=<mutant> proves the test can fail
DRILL_IMAGE=${DRILL_IMAGE:-postgis/postgis:17-3.5-alpine}
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'

REAL_DOCKER=$(command -v docker) || { echo "docker not found" >&2; exit 2; }
command -v ssh-keygen >/dev/null || { echo "ssh-keygen not found" >&2; exit 2; }
command -v openssl >/dev/null || { echo "openssl not found" >&2; exit 2; }
"$REAL_DOCKER" image inspect "$DRILL_IMAGE" >/dev/null 2>&1 || { echo "image $DRILL_IMAGE is not present locally" >&2; exit 2; }

T=$(mktemp -d "${TMPDIR:-/tmp}/hara-faults.XXXXXX")
[ -n "$T" ] && [ -d "$T" ] || { echo "mktemp failed" >&2; exit 2; }
# Native Windows tools (openssl, docker) cannot resolve /tmp under MSYS_NO_PATHCONV: use mixed paths.
if command -v cygpath >/dev/null; then T=$(cygpath -m "$T"); fi
FIXTURE=hara-fixture-$$
cleanup() {
    "$REAL_DOCKER" rm -f -v "$FIXTURE" >/dev/null 2>&1
    if [ "${KEEP:-0}" = 1 ]; then echo "work directory kept: $T"; else rm -rf -- "${T:?}"; fi
}
trap cleanup EXIT
mkdir -p "$T/bin" "$T/cases" "$T/tmp" "$T/keys"

PASS=0; FAIL=0
ok() { PASS=$((PASS+1)); printf '  PASS  %s\n' "$*"; }
no() { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$*"; }
check() { # check "description" command...
    local d=$1; shift
    if "$@"; then ok "$d"; else no "$d"; fi
}

# ---- stand-ins and the docker recorder ------------------------------------
if [ "${USE_REAL_AGE:-0}" = 1 ] && command -v age >/dev/null && command -v age-keygen >/dev/null; then
    AGE_MODE=real
    age-keygen -o "$T/keys/age.key" >/dev/null 2>&1
    age-keygen -y "$T/keys/age.key" >"$T/keys/age.pub"
    age-keygen -o "$T/keys/age-wrong.key" >/dev/null 2>&1
    AGE_CMD=age
else
    AGE_MODE=stand-in
    head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' >"$T/keys/age.key"
    head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' >"$T/keys/age-wrong.key"
    AGE_CMD=$T/bin/age
    cat >"$AGE_CMD" <<'SH'
#!/usr/bin/env bash
# stand-in for: age --decrypt --identity FILE --output OUT IN
[ "$1" = "--decrypt" ] && [ "$2" = "--identity" ] && [ "$4" = "--output" ] || { echo "age stand-in: unsupported arguments" >&2; exit 2; }
exec openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$3" -in "$6" -out "$5"
SH
fi
echo "age: $AGE_MODE"
encrypt() { # encrypt IN OUT
    if [ "$AGE_MODE" = real ]; then age -R "$T/keys/age.pub" -o "$2" "$1"
    else openssl enc -aes-256-cbc -pbkdf2 -pass "file:$T/keys/age.key" -in "$1" -out "$2"; fi
}

RCLONE_CMD=$T/bin/rclone
cat >"$RCLONE_CMD" <<'SH'
#!/usr/bin/env bash
# stand-in for rclone with a local directory as the remote
case "$1" in
    lsf) shift; for a in "$@"; do case "$a" in --*) ;; *) d=$a ;; esac; done; ls -1 "$d" ;;
    copyto) src=$2; dst=$3; [ -f "$src" ] || { echo "rclone stand-in: $src not found" >&2; exit 3; }; cp -- "$src" "$dst" ;;
    *) echo "rclone stand-in: unsupported: $1" >&2; exit 2 ;;
esac
SH
cat >"$T/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >>"\$DOCKER_LOG"
exec "$REAL_DOCKER" "\$@"
SH
chmod +x "$AGE_CMD" "$RCLONE_CMD" "$T/bin/docker" 2>/dev/null

# ---- signing keys ----------------------------------------------------------
ssh-keygen -q -t ed25519 -N '' -C test-good -f "$T/keys/good" >/dev/null
ssh-keygen -q -t ed25519 -N '' -C test-other -f "$T/keys/other" >/dev/null
printf 'hara-backup %s\n' "$(cut -d' ' -f1,2 "$T/keys/good.pub")" >"$T/keys/allowed_signers"

# ---- fixture dumps, built in their own throwaway container -----------------
echo "building fixture dumps in $FIXTURE (--network none)"
"$REAL_DOCKER" run --rm -d --network none --name "$FIXTURE" -e POSTGRES_PASSWORD=fixture "$DRILL_IMAGE" >/dev/null || { echo "could not start fixture container" >&2; exit 2; }
for _ in $(seq 1 90); do "$REAL_DOCKER" exec "$FIXTURE" pg_isready -q -h 127.0.0.1 -U postgres 2>/dev/null && READY=1 && break; sleep 1; done
[ "${READY:-0}" = 1 ] || { echo "fixture postgres not ready" >&2; exit 2; }
FX() { "$REAL_DOCKER" exec -i -u postgres "$FIXTURE" "$@"; }
FX psql -U postgres --no-psqlrc -q -v ON_ERROR_STOP=1 -c "CREATE DATABASE fx_good" -c "CREATE DATABASE fx_ext" || exit 2
SEED="CREATE EXTENSION postgis;
CREATE TABLE migrations (id serial PRIMARY KEY, migration text NOT NULL, batch int NOT NULL);
INSERT INTO migrations (migration, batch) VALUES ('2026_01_01_000000_create_demo', 1), ('2026_01_02_000000_create_zones', 1);
CREATE TABLE zones (id text PRIMARY KEY, geom geometry(Polygon,4326) NOT NULL);
INSERT INTO zones VALUES ('z1', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))',4326));
INSERT INTO zones SELECT 'pad' || g, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))',4326) FROM generate_series(1,3000) g;"
FX psql -U postgres -d fx_good --no-psqlrc -q -v ON_ERROR_STOP=1 -c "$SEED" || exit 2
FX psql -U postgres -d fx_ext --no-psqlrc -q -v ON_ERROR_STOP=1 -c "$SEED" -c "CREATE EXTENSION hstore" || exit 2
FX pg_dump -U postgres -Fc fx_good >"$T/good.dump"
FX pg_dump -U postgres -Fc fx_ext >"$T/ext.dump"
"$REAL_DOCKER" rm -f -v "$FIXTURE" >/dev/null 2>&1
SIZE=$(wc -c <"$T/good.dump")
echo "fixture dump: $SIZE bytes (good), $(wc -c <"$T/ext.dump") bytes (hstore)"
head -c 64 "$T/good.dump" >"$T/trunc-head.dump"
{ printf 'XXXXX'; tail -c +6 "$T/good.dump"; } >"$T/corrupt-magic.dump"
head -c $((SIZE / 2)) "$T/good.dump" >"$T/trunc-half.dump"

# ---- case builder and runner -----------------------------------------------
NAME=hara-staging-20261115T000000Z-faulttest.dump.age
mkcase() { # mkcase CASE PLAIN [SIGNKEY] [NAMESPACE]   (SIGNKEY none = no .sig)
    local c=$T/cases/$1 key=${3:-good} ns=${4:-hara-backup}
    mkdir -p "$c"
    encrypt "$2" "$c/$NAME" || { echo "encrypt failed" >&2; exit 2; }
    if [ "$key" != none ]; then
        ssh-keygen -Y sign -q -f "$T/keys/$key" -n "$ns" "$c/$NAME" || { echo "sign failed" >&2; exit 2; }
    fi
}
LIVE_BEFORE=$("$REAL_DOCKER" ps -a --format '{{.Names}} {{.Status}}' | sed 's/ (.*//; s/Up .*/Up/' | sort | md5sum)
drills() { "$REAL_DOCKER" ps -a --filter name=hara-drill- --format '{{.Names}}' | sort; }

run() { # run CASE MODE [IDENTITY]  -> sets RC, LOG, DLOG
    local c=$1 mode=$2 ident=${3:-$T/keys/age.key}
    LOG=$T/$c-$mode.log; DLOG=$T/$c-$mode.docker; : >"$DLOG"
    rm -rf "${T:?}/tmp" && mkdir -p "$T/tmp"
    local args=()
    if [ "$mode" = local ]; then args=(--local "$T/cases/$c/$NAME"); fi
    PATH="$T/bin:$PATH" DOCKER_LOG="$DLOG" TMPDIR="$T/tmp" \
    ALLOWED_SIGNERS="$T/keys/allowed_signers" AGE_IDENTITY="$ident" AGE="$AGE_CMD" RCLONE="$RCLONE_CMD" \
    RCLONE_REMOTE="$T/cases/$c" DRILL_IMAGE="$DRILL_IMAGE" COMPOSE_DIR="$T/none" \
        bash "$DRILL" "${args[@]}" >"$LOG" 2>&1
    RC=$?
}
has() { grep -Eq -- "$1" "$LOG"; }
hasnt() { ! grep -Eq -- "$1" "$LOG"; }
common_clean() { # container, temp dir and docker-scope checks that apply to every case
    check "no hara-drill-* container left" test -z "$(drills | grep -vxFf "$T/drills.before" || true)"
    check "no hara-drill.* temp directory left" test -z "$(ls -A "$T/tmp")"
    check "every docker call names a hara-drill-* container only" test "$(grep -vc 'hara-drill-' "$DLOG")" -eq 0
}
drills >"$T/drills.before"

refuse() { # refuse CASE MODE "message regex" early|late
    local c=$1 mode=$2 msg=$3 when=$4
    echo "--- $c [$mode]: must be refused ($when)"
    run "$c" "$mode"
    check "exit status non-zero (got $RC)" test "$RC" -ne 0
    check "refusal message matches /$msg/" has "$msg"
    check "never prints RESTORE DRILL PASSED" hasnt 'RESTORE DRILL PASSED'
    check "no restore started (no role/database creation, no pg_restore)" hasnt 'creating non-superuser role|restoring as drill_owner'
    if [ "$when" = early ]; then
        check "no container started (docker never called)" test ! -s "$DLOG"
        check "nothing decrypted" hasnt 'drill: decrypting'
    else
        check "throwaway container was started, then removed" has 'throwaway container hara-drill-.* removed'
    fi
    common_clean
    printf '        last lines: %s\n' "$(tail -n 2 "$LOG" | sed 's/^[0-9TZ:-]* //' | tr '\n' '|')"
}

# ---- cases -----------------------------------------------------------------
mkcase good "$T/good.dump"
for mode in remote local; do
    echo "--- good [$mode]: must PASS"
    run good "$mode"
    check "exit status 0 (got $RC)" test "$RC" -eq 0
    check "signature verified" has 'signature verified'
    check "RESTORE DRILL PASSED" has 'RESTORE DRILL PASSED'
    check "restore ran as non-superuser drill_owner" has 'restoring as drill_owner'
    check "2 tables restored, 2 migrations recorded" has 'tables in public: 2; migrations recorded: 2; latest: 2026_01_02_000000_create_zones'
    check "throwaway container removed" has 'throwaway container hara-drill-.* removed'
    common_clean
done

mkcase tampered-append "$T/good.dump"; printf 'X' >>"$T/cases/tampered-append/$NAME"
mkcase tampered-flip "$T/good.dump"
{ head -c 100 "$T/cases/tampered-flip/$NAME"; printf '\377'; tail -c +102 "$T/cases/tampered-flip/$NAME"; } >"$T/flip.tmp" && mv "$T/flip.tmp" "$T/cases/tampered-flip/$NAME"
mkcase no-sig "$T/good.dump" none
mkcase empty-sig "$T/good.dump"; : >"$T/cases/empty-sig/$NAME.sig"
mkcase other-key "$T/good.dump" other
mkcase wrong-namespace "$T/good.dump" good file
mkcase wrong-identity "$T/good.dump"
mkcase trunc-head "$T/trunc-head.dump"
mkcase corrupt-magic "$T/corrupt-magic.dump"
mkcase bad-extension "$T/ext.dump"
mkcase trunc-half "$T/trunc-half.dump"

for mode in remote local; do
    refuse tampered-append "$mode" 'signature check FAILED' early
    refuse tampered-flip "$mode" 'signature check FAILED' early
    refuse other-key "$mode" 'signature check FAILED' early
    refuse wrong-namespace "$mode" 'signature check FAILED' early
    refuse empty-sig "$mode" 'signature file .* is missing|signature check FAILED|could not be downloaded' early
done
refuse no-sig remote 'could not be downloaded; refusing an unsigned backup' early
refuse no-sig local 'signature file .* is missing; refusing an unsigned backup|not found' early
# Validly signed, so these pass the signature check and are stopped later.
refuse trunc-head remote 'pg_restore --list could not read the archive' late
refuse corrupt-magic remote 'pg_restore --list could not read the archive' late
refuse bad-extension remote 'extension other than postgis/plpgsql; refusing' late
# Extra: a wrong age key stops at decryption (the signature is fine, so no container is started yet).
echo "--- wrong-identity [remote]: must be refused at decryption"
run wrong-identity remote "$T/keys/age-wrong.key"
check "exit status non-zero (got $RC)" test "$RC" -ne 0
check "never prints RESTORE DRILL PASSED" hasnt 'RESTORE DRILL PASSED'
check "no restore started" hasnt 'creating non-superuser role|restoring as drill_owner'
common_clean
# Extra: the dump body is cut in half. The table of contents reads fine, so the
# drill starts the restore and pg_restore --exit-on-error must stop it.
echo "--- trunc-half [remote]: must fail during the restore, never pass"
run trunc-half remote
check "exit status non-zero (got $RC)" test "$RC" -ne 0
check "never prints RESTORE DRILL PASSED" hasnt 'RESTORE DRILL PASSED'
check "throwaway container removed" has 'throwaway container hara-drill-.* removed'
common_clean

echo "--- the rest of the machine"
LIVE_AFTER=$("$REAL_DOCKER" ps -a --format '{{.Names}} {{.Status}}' | sed 's/ (.*//; s/Up .*/Up/' | sort | md5sum)
check "container list (names, running or not) is unchanged by the whole run" test "$LIVE_BEFORE" = "$LIVE_AFTER"

echo
echo "RESULT: $PASS passed, $FAIL failed (age: $AGE_MODE, image: $DRILL_IMAGE)"
[ "$FAIL" -eq 0 ]
