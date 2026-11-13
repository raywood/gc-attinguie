<?php
declare(strict_types=1);

namespace App\Tests\Support;

/** Explicitly online operation; constructed without I/O, invoked only on request. */
final class PostgresIdentityProbe
{
    /** @param array<string, mixed> $environment
     *  @return array<string, mixed>
     */
    public function __invoke(array $environment): array
    {
        TestEnvironmentGuard::assertSafe($environment);

        // Never forward an arbitrary URL or libpq environment options. Explicit
        // host/user/database/password/port and a short connection timeout.
        $connection = new \PDO(
            'pgsql:host=db-test;port=5432;dbname=gc_attinguie_test;connect_timeout=3;sslmode=disable;application_name=gc-integration-identity',
            'gc_test_runner',
            'test-only',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $connection->beginTransaction();
        try {
            $connection->exec('SET TRANSACTION READ ONLY');
            $connection->exec("SET LOCAL statement_timeout = '3s'");
            $row = $connection->query(<<<'SQL'
SELECT current_database() AS database_name, current_user AS user_name,
       session_user AS session_user_name,
       current_setting('server_version_num') AS server_version_num,
       EXISTS (
           SELECT 1 FROM pg_catalog.pg_roles r
           WHERE pg_has_role(current_user, r.oid, 'MEMBER')
             AND (r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolbypassrls)
       ) AS elevated_role,
       i.marker,
       pg_get_userbyid(c.relowner) AS marker_owner,
       pg_get_userbyid(n.nspowner) AS schema_owner,
       (has_table_privilege(current_user, c.oid, 'INSERT')
        OR has_table_privilege(current_user, c.oid, 'UPDATE')
        OR has_table_privilege(current_user, c.oid, 'DELETE')
        OR has_table_privilege(current_user, c.oid, 'TRUNCATE')
        OR has_any_column_privilege(current_user, c.oid, 'INSERT')
        OR has_any_column_privilege(current_user, c.oid, 'UPDATE')) AS marker_writable,
       has_schema_privilege(current_user, n.oid, 'CREATE') AS schema_writable,
       pg_has_role(current_user, c.relowner, 'MEMBER') AS marker_owner_member
FROM isolation_meta.identity i
JOIN pg_catalog.pg_class c ON c.oid = 'isolation_meta.identity'::regclass
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE i.id = 1
SQL)->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \LogicException('PostgreSQL identity marker missing.');
            }
            return $row;
        } finally {
            $connection->rollBack();
        }
    }
}
