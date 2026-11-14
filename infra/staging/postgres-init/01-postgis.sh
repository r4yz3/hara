#!/bin/sh
# Runs once, when the data volume is first created. Enables PostGIS in the
# staging database. Unlike the development init script it creates no test
# database: backend tests must never run against staging.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE EXTENSION IF NOT EXISTS postgis;
SQL
