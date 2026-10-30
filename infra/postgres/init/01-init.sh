#!/bin/sh
set -eu

: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"

psql_admin() {
  PGOPTIONS='-c client_min_messages=warning' psql -q -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --no-password "$@"
}

databases="$POSTGRES_DB"
for suffix in ${EXTRA_DB_SUFFIXES-test e2e}; do
  databases="$databases ${POSTGRES_DB}_${suffix}"
done

for db in $databases; do
  exists=$(psql_admin --dbname postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'")
  if [ "$exists" != "1" ]; then
    psql_admin --dbname postgres -c "CREATE DATABASE \"$db\" OWNER \"$POSTGRES_USER\""
  fi

  psql_admin --dbname "$db" <<SQL
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE SCHEMA IF NOT EXISTS rag AUTHORIZATION "$POSTGRES_USER";
CREATE SCHEMA IF NOT EXISTS worker AUTHORIZATION "$POSTGRES_USER";
SQL
  echo "init: database $db ready (vector, pg_trgm, unaccent; schemas rag, worker)"
done
