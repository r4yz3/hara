#!/bin/sh
# Runs once, when the data volume is first created.
# Enables PostGIS in the development database and creates a separate test
# database so tests never touch development data (backlog A-06).
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE DATABASE ${POSTGRES_DB}_test OWNER "$POSTGRES_USER";
SQL

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "${POSTGRES_DB}_test" <<SQL
CREATE EXTENSION IF NOT EXISTS postgis;
SQL
