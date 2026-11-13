#!/bin/sh
# Runs INSIDE the postgres container. The task runner copies it in and runs it:
#   .\infra\scripts\dev.ps1 restore-drill   (or the same make target)
#
# Restore drill (spec 11.4, backlog E-03). Restores /tmp/backup.dump into a
# brand-new scratch database, proves the copy is complete and still behaves,
# then removes the scratch database. It never writes to the source database.
#
# Checks, each must pass:
#   1. every table has the same number of rows as the source
#   2. the migration ledger is identical
#   3. every stored shape is byte-identical and valid
#   4. the spatial indexes exist
#   5. the audit log is still append-only (its triggers survived)
#   6. a serviceability lookup gives the same answer in both databases
#
# Exit status is 0 only if every check passes.
set -eu

DUMP=/tmp/backup.dump
SRC="$POSTGRES_DB"
DRILL="restore_drill_$(date +%Y%m%d%H%M%S)"
FAILED=0

q() { psql --username "$POSTGRES_USER" --dbname "$1" --no-psqlrc --tuples-only --no-align --quiet -v ON_ERROR_STOP=1 -c "$2"; }

check() { # name, expected, actual
    if [ "$2" = "$3" ]; then
        echo "PASS  $1"
    else
        echo "FAIL  $1"
        FAILED=1
    fi
}

cleanup() {
    # Only ever drops a database this script created.
    case "$DRILL" in
        restore_drill_*) dropdb --username "$POSTGRES_USER" --if-exists "$DRILL" ;;
    esac
}
trap cleanup EXIT

[ -s "$DUMP" ] || { echo "No backup at $DUMP. Run the backup task first."; exit 2; }

echo "source database:  $SRC"
echo "scratch database: $DRILL"
echo "backup sha256:    $(sha256sum "$DUMP" | cut -d' ' -f1)"
START=$(date +%s)

createdb --username "$POSTGRES_USER" "$DRILL"
pg_restore --username "$POSTGRES_USER" --dbname "$DRILL" --no-owner --no-privileges --exit-on-error "$DUMP"
echo "restore time: $(( $(date +%s) - START ))s"
echo

# 1. Row counts, table by table. count(*) per table avoids trusting statistics.
TABLES=$(q "$SRC" "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name <> 'spatial_ref_sys' ORDER BY 1")
counts() { for t in $TABLES; do echo "$t $(q "$1" "SELECT count(*) FROM \"$t\"")"; done; }
SRC_COUNTS=$(counts "$SRC")
check "1. row counts match for $(echo "$TABLES" | wc -l | tr -d ' ') tables" "$SRC_COUNTS" "$(counts "$DRILL")"
echo "$SRC_COUNTS" | awk '$2 > 0 { printf "        %s: %s rows\n", $1, $2 }'

# 2. Migration ledger.
MIG="SELECT string_agg(migration || ':' || batch, ',' ORDER BY id) FROM migrations"
check "2. migration ledger identical" "$(q "$SRC" "$MIG")" "$(q "$DRILL" "$MIG")"

# 3. Shapes: byte-identical and valid.
GEOM="SELECT coalesce(md5(string_agg(id || encode(ST_AsEWKB(coverage), 'hex'), '' ORDER BY id)), 'none') FROM delivery_zones"
check "3a. zone shapes byte-identical" "$(q "$SRC" "$GEOM")" "$(q "$DRILL" "$GEOM")"
EXCL="SELECT coalesce(md5(string_agg(id || encode(ST_AsEWKB(geometry), 'hex'), '' ORDER BY id)), 'none') FROM coverage_exclusions"
check "3b. exclusion shapes byte-identical" "$(q "$SRC" "$EXCL")" "$(q "$DRILL" "$EXCL")"
check "3c. no invalid shape after restore" "0" "$(q "$DRILL" "SELECT count(*) FROM delivery_zones WHERE NOT ST_IsValid(coverage) OR ST_SRID(coverage) <> 4326")"

# 4. Spatial indexes.
check "4. spatial indexes present" "4" "$(q "$DRILL" "SELECT count(*) FROM pg_indexes WHERE schemaname = 'public' AND indexname IN ('delivery_zones_coverage_gix','coverage_exclusions_geometry_gix','branches_location_gix','saved_addresses_point_gix')")"

# 5. Audit log still append-only. The probe row is written to the scratch copy only.
check "5a. audit triggers present" "2" "$(q "$DRILL" "SELECT count(*) FROM pg_trigger WHERE tgname IN ('audit_logs_no_update_delete','audit_logs_no_truncate')")"
q "$DRILL" "INSERT INTO audit_logs (id, action, subject_type) VALUES ('drillprobe0000000000000000', 'drill.probe', 'drill')"
if q "$DRILL" "UPDATE audit_logs SET action = 'tampered' WHERE id = 'drillprobe0000000000000000'" >/dev/null 2>&1; then
    check "5b. audit log refuses UPDATE" "refused" "allowed"
else
    check "5b. audit log refuses UPDATE" "refused" "refused"
fi
if q "$DRILL" "DELETE FROM audit_logs WHERE id = 'drillprobe0000000000000000'" >/dev/null 2>&1; then
    check "5c. audit log refuses DELETE" "refused" "allowed"
else
    check "5c. audit log refuses DELETE" "refused" "refused"
fi

# 6. Behaviour: ask both databases the same serviceability question, at a
#    point taken from inside a stored active zone (so no coordinate is
#    written in this script). With no active zone the answer is "none" twice.
POINT=$(q "$SRC" "SELECT ST_X(p) || ' ' || ST_Y(p) FROM (SELECT ST_PointOnSurface(z.coverage) AS p FROM delivery_zones z JOIN cities c ON c.id = z.city_id WHERE z.status = 'active' AND c.status = 'active' ORDER BY z.id LIMIT 1) s")
if [ -n "$POINT" ]; then
    LOOKUP="SELECT coalesce((SELECT z.id FROM delivery_zones z JOIN cities c ON c.id = z.city_id WHERE z.status = 'active' AND c.status = 'active' AND ST_Covers(z.coverage, ST_SetSRID(ST_MakePoint(${POINT% *}, ${POINT#* }), 4326)) ORDER BY z.priority DESC, z.id LIMIT 1), 'none')"
    ANSWER=$(q "$SRC" "$LOOKUP")
    check "6. serviceability lookup gives the same zone" "$ANSWER" "$(q "$DRILL" "$LOOKUP")"
    [ "$ANSWER" != "none" ] || { echo "FAIL  6. lookup found no zone at a point inside one"; FAILED=1; }
else
    echo "SKIP  6. serviceability lookup (no active zone in an active city to test with)"
fi

echo
if [ "$FAILED" -eq 0 ]; then
    echo "RESTORE DRILL PASSED. Scratch database $DRILL is removed on exit."
else
    echo "RESTORE DRILL FAILED. Scratch database $DRILL is removed on exit."
fi
exit "$FAILED"
