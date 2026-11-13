-- MANUAL, TEST ONLY. Never mounted in docker-entrypoint-initdb.d.
-- Future execution: psql -X -v ON_ERROR_STOP=1, against verified db-test only.
BEGIN;
SET LOCAL statement_timeout = '10s';
SET LOCAL lock_timeout = '3s';
SET LOCAL search_path = pg_catalog;
DO $guard$
BEGIN
    IF current_database() <> 'gc_attinguie_test'
       OR current_user <> 'gc_test_admin' OR session_user <> 'gc_test_admin'
       OR current_setting('server_version_num')::integer NOT BETWEEN 160000 AND 169999 THEN
        RAISE EXCEPTION 'Unexpected provisioning destination';
    END IF;
    IF (SELECT marker FROM isolation_meta.identity WHERE id = 1)
       IS DISTINCT FROM 'gc-attinguie-isolated-test-v1' THEN
        RAISE EXCEPTION 'Missing or unexpected identity marker';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE c.oid = 'isolation_meta.identity'::regclass AND c.relkind = 'r'
          AND pg_get_userbyid(c.relowner) = 'gc_test_admin'
          AND pg_get_userbyid(n.nspowner) = 'gc_test_admin'
    ) THEN RAISE EXCEPTION 'Unexpected marker ownership'; END IF;
    IF EXISTS (
        SELECT 1 FROM pg_catalog.pg_class c,
             LATERAL aclexplode(COALESCE(c.relacl, acldefault('r', c.relowner))) a
        WHERE c.oid = 'isolation_meta.identity'::regclass
          AND a.grantee = 0
    ) OR EXISTS (
        SELECT 1 FROM pg_catalog.pg_namespace n,
             LATERAL aclexplode(COALESCE(n.nspacl, acldefault('n', n.nspowner))) a
        WHERE n.nspname = 'isolation_meta' AND a.grantee = 0 AND a.privilege_type = 'CREATE'
    ) OR has_schema_privilege('gc_test_runner', 'isolation_meta', 'CREATE')
      OR has_table_privilege('gc_test_runner', 'isolation_meta.identity', 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
      OR has_any_column_privilege('gc_test_runner', 'isolation_meta.identity', 'INSERT,UPDATE,REFERENCES')
      OR pg_has_role('gc_test_runner', 'gc_test_admin', 'MEMBER') THEN
        RAISE EXCEPTION 'Identity marker protection changed';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'gc_test_migrator') THEN
        RAISE EXCEPTION 'Migrator already exists: inspect explicitly, do not overwrite';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_database
               WHERE datname NOT IN ('gc_attinguie_test', 'postgres', 'template0', 'template1')) THEN
        RAISE EXCEPTION 'Unexpected databases in test cluster';
    END IF;
    -- Audit 10B.2B: PUBLIC allowances are upper bounds, never new grants.
    -- Preserve existing ACLs. Reject any broader right/delegation before CREATE ROLE.
    IF (SELECT COUNT(*) FROM pg_catalog.pg_database) <> 4 OR EXISTS (
        SELECT 1 FROM pg_catalog.pg_database d
        WHERE pg_get_userbyid(d.datdba) <> 'gc_test_admin'
          OR d.datallowconn IS DISTINCT FROM (d.datname <> 'template0')
    ) THEN
        RAISE EXCEPTION 'Unexpected database ownership or datallowconn';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_catalog.pg_database d,
             LATERAL aclexplode(COALESCE(d.datacl, acldefault('d', d.datdba))) a
        WHERE a.grantee = 0 AND (
            a.is_grantable OR a.grantor <> d.datdba OR NOT (
                (d.datname = 'gc_attinguie_test' AND a.privilege_type = 'TEMPORARY')
                OR (d.datname = 'postgres' AND a.privilege_type IN ('CONNECT', 'TEMPORARY'))
                OR (d.datname IN ('template0', 'template1') AND a.privilege_type = 'CONNECT')
            )
        )
    ) THEN
        RAISE EXCEPTION 'PUBLIC database ACL exceeds audited policy';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_namespace n
                   WHERE n.nspname='public' AND pg_get_userbyid(n.nspowner)='pg_database_owner')
       OR EXISTS (SELECT 1 FROM pg_catalog.pg_namespace n
                  WHERE n.nspname !~ '^pg_' AND n.nspname NOT IN ('information_schema','public','isolation_meta'))
       OR NOT EXISTS (SELECT 1 FROM pg_catalog.pg_language l
                      WHERE l.lanname='plpgsql' AND l.lanpltrusted
                        AND pg_get_userbyid(l.lanowner)='gc_test_admin')
       OR EXISTS (
        SELECT 1 FROM pg_catalog.pg_namespace n,
             LATERAL aclexplode(COALESCE(n.nspacl,acldefault('n',n.nspowner))) a
        WHERE n.nspname IN ('public','isolation_meta') AND a.grantee=0
          AND (n.nspname <> 'public' OR a.privilege_type <> 'USAGE'
               OR a.is_grantable OR a.grantor <> n.nspowner)
    ) OR EXISTS (
        SELECT 1 FROM pg_catalog.pg_language l,
             LATERAL aclexplode(COALESCE(l.lanacl,acldefault('l',l.lanowner))) a
        WHERE l.lanname='plpgsql' AND a.grantee=0
          AND (a.privilege_type <> 'USAGE' OR a.is_grantable OR a.grantor <> l.lanowner)
    ) OR EXISTS (
        SELECT 1 FROM pg_catalog.pg_attribute c,LATERAL aclexplode(c.attacl) a
        WHERE c.attrelid='isolation_meta.identity'::regclass AND a.grantee=0
    ) THEN
        RAISE EXCEPTION 'PUBLIC object ACL exceeds audited policy';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
               WHERE n.nspname='public')
       OR EXISTS (SELECT 1 FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
                  WHERE n.nspname='public')
       OR EXISTS (SELECT 1 FROM pg_catalog.pg_type t JOIN pg_catalog.pg_namespace n ON n.oid=t.typnamespace
                  WHERE n.nspname='public') THEN
        RAISE EXCEPTION 'Application schema is not empty: ownership requires review';
    END IF;
END
$guard$;

CREATE ROLE gc_test_migrator LOGIN PASSWORD 'migration-test-only'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
-- Only the new role receives grants. Existing roles and PUBLIC are untouched.
GRANT CONNECT ON DATABASE gc_attinguie_test TO gc_test_migrator;
GRANT USAGE, CREATE ON SCHEMA public TO gc_test_migrator;
GRANT USAGE ON LANGUAGE plpgsql TO gc_test_migrator;
GRANT USAGE ON SCHEMA isolation_meta TO gc_test_migrator;
GRANT SELECT ON isolation_meta.identity TO gc_test_migrator;
ALTER ROLE gc_test_migrator IN DATABASE gc_attinguie_test SET search_path = public;
ALTER ROLE gc_test_migrator IN DATABASE gc_attinguie_test SET statement_timeout = '10s';
ALTER ROLE gc_test_migrator IN DATABASE gc_attinguie_test SET lock_timeout = '3s';
ALTER ROLE gc_test_migrator IN DATABASE gc_attinguie_test SET idle_in_transaction_session_timeout = '10s';
-- No default-privilege changes, role membership, ownership transfer, or marker write.
COMMIT;
