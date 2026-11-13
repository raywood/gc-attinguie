-- Test-only credentials; this script must only initialize the isolated db-test.
CREATE ROLE gc_test_runner LOGIN PASSWORD 'test-only'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;
REVOKE CONNECT ON DATABASE gc_attinguie_test FROM PUBLIC;
GRANT CONNECT ON DATABASE gc_attinguie_test TO gc_test_runner;
GRANT USAGE, CREATE ON SCHEMA public TO gc_test_runner;

-- Outside the application schema; runner cannot replace this identity marker.
CREATE SCHEMA isolation_meta AUTHORIZATION gc_test_admin;
CREATE TABLE isolation_meta.identity (id integer PRIMARY KEY, marker text NOT NULL);
INSERT INTO isolation_meta.identity VALUES (1, 'gc-attinguie-isolated-test-v1');
REVOKE ALL ON SCHEMA isolation_meta FROM PUBLIC;
GRANT USAGE ON SCHEMA isolation_meta TO gc_test_runner;
GRANT SELECT ON isolation_meta.identity TO gc_test_runner;
