<?php
declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;

/** Construction is offline. One explicit DBAL instance is shared with Doctrine. */
final class MigrationContext
{
    private readonly DependencyFactory $doctrine;

    public function __construct(private readonly Connection $connection)
    {
        $this->assertConnection($connection);
        $this->doctrine = DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations_paths' => ['DoctrineMigrations' => dirname(__DIR__, 2) . '/migrations'],
            'table_storage' => ['table_name' => 'public.doctrine_migration_versions'],
            'all_or_nothing' => true, 'transactional' => true, 'check_database_platform' => true,
        ]), new ExistingConnection($connection));
    }

    public static function create(array $environment): self
    {
        MigrationProfile::assertSafe($environment);
        return new self(DriverManager::getConnection(MigrationProfile::connectionParameters()));
    }

    public function doctrine(): DependencyFactory
    {
        $this->assertConnection($this->doctrine->getConnection());
        return $this->doctrine;
    }

    public function assertConnection(Connection $candidate): void
    {
        if ($candidate !== $this->connection || $candidate->getParams() !== MigrationProfile::connectionParameters()) {
            throw new \LogicException('Migration connection substituted or parameters changed.');
        }
    }

    /** Online, read-only. Never call during offline validation. */
    public function checkIdentity(array $environment): string
    {
        MigrationProfile::assertSafe($environment);
        $connection = $this->doctrine()->getConnection();
        $this->assertConnection($connection);
        if ($connection->isTransactionActive()) {
            throw new \LogicException('Unexpected active migration transaction.');
        }
        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET TRANSACTION READ ONLY');
            $connection->executeStatement("SET LOCAL statement_timeout = '10s'");
            $connection->executeStatement("SET LOCAL lock_timeout = '3s'");
            $connection->executeStatement("SET LOCAL idle_in_transaction_session_timeout = '10s'");
            // Transaction advisory lock serializes only the guarded migration channel.
            $locked = $connection->fetchOne('SELECT pg_try_advisory_xact_lock(826043, 1)');
            if (!in_array($locked, [true, 't', '1', 1], true)) {
                throw new \LogicException('Another migration check is active.');
            }
            $row = $connection->fetchAssociative(self::IDENTITY_SQL);
            $row = $row === false ? [] : $row;
            MigrationIdentityGuard::assertIdentity($row);
            $row['databases'] = self::decodeAcls($connection->fetchAllAssociative(self::DATABASE_ACL_SQL));
            $row['object_acls'] = self::decodeAcls($connection->fetchAllAssociative(self::OBJECT_ACL_SQL));
            MigrationIdentityGuard::assertSafe($row);
            MigrationPlan::inspect();
            $versions = array_map(static fn ($item): string => (string) $item->getVersion(),
                $this->doctrine->getMigrationRepository()->getMigrations()->getItems());
            $expected = array_map(static fn (string $v): string => 'DoctrineMigrations\\Version' . $v, MigrationPlan::VERSIONS);
            sort($versions);
            if ($versions !== $expected) {
                throw new \LogicException('Doctrine migration repository differs from approved plan.');
            }
            // No metadata initialization: any existing public object blocks first preparation.
            $objects = $connection->fetchFirstColumn(<<<'SQL'
SELECT c.relname FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'
UNION ALL SELECT p.proname FROM pg_catalog.pg_proc p
JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public'
UNION ALL SELECT t.typname FROM pg_catalog.pg_type t
JOIN pg_catalog.pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname='public'
SQL);
            MigrationPlan::assertInitialState($objects, []);
            $this->assertConnection($connection);
            return 'OK: migration identity verified on Doctrine DBAL connection; writes disabled.';
        } finally {
            // Releases the transaction advisory lock too. No authorization retained.
            $connection->rollBack();
        }
    }

    public const IDENTITY_SQL = <<<'SQL'
SELECT current_database() AS database_name, current_user AS user_name,
 session_user AS session_user_name, current_schema() AS current_schema,
 current_setting('search_path') AS search_path,
 current_setting('server_version_num') AS server_version_num,
 EXISTS (SELECT 1 FROM pg_catalog.pg_roles r WHERE pg_has_role(current_user,r.oid,'MEMBER')
   AND (r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolbypassrls)) AS elevated_role,
 EXISTS (SELECT 1 FROM pg_catalog.pg_roles r WHERE r.rolname <> current_user
   AND pg_has_role(current_user,r.oid,'MEMBER')) AS other_role_member,
 has_database_privilege(current_user,current_database(),'CREATE') AS database_create,
 EXISTS (SELECT 1 FROM pg_catalog.pg_namespace x WHERE x.nspname <> 'public'
   AND has_schema_privilege(current_user,x.oid,'CREATE')) AS other_schema_write,
 (EXISTS (SELECT 1 FROM pg_catalog.pg_class x,
     LATERAL aclexplode(COALESCE(x.relacl,acldefault('r',x.relowner))) a
     WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user)
       AND x.oid <> 'isolation_meta.identity'::regclass)
  OR EXISTS (SELECT 1 FROM pg_catalog.pg_namespace x,
     LATERAL aclexplode(COALESCE(x.nspacl,acldefault('n',x.nspowner))) a
     WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user)
       AND x.nspname NOT IN ('public','isolation_meta'))
  OR EXISTS (SELECT 1 FROM pg_catalog.pg_proc x,
     LATERAL aclexplode(COALESCE(x.proacl,acldefault('f',x.proowner))) a
     WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user))
  OR EXISTS (SELECT 1 FROM pg_catalog.pg_language x,
     LATERAL aclexplode(COALESCE(x.lanacl,acldefault('l',x.lanowner))) a
     WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user) AND x.lanname <> 'plpgsql')
  OR EXISTS (SELECT 1 FROM pg_catalog.pg_attribute x,
     LATERAL aclexplode(x.attacl) a
     WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user))
 ) AS unexpected_direct_grants,
 pg_has_role(current_user,(SELECT datdba FROM pg_catalog.pg_database WHERE datname=current_database()),'MEMBER') AS database_owner_member,
 pg_has_role(current_user,(SELECT nspowner FROM pg_catalog.pg_namespace WHERE nspname='public'),'MEMBER') AS public_owner_member,
 has_schema_privilege(current_user,'public','USAGE') AS public_usage,
 has_schema_privilege(current_user,'public','CREATE') AS public_create,
 has_language_privilege(current_user,'plpgsql','USAGE') AS plpgsql_usage,
 EXISTS (SELECT 1 FROM pg_catalog.pg_attribute x,LATERAL aclexplode(x.attacl) a
   WHERE x.attrelid='isolation_meta.identity'::regclass AND
   (a.grantee=0 OR a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user))) AS marker_column_acl,
 i.marker, c.relkind AS marker_kind,
 pg_get_userbyid(c.relowner) AS marker_owner, pg_get_userbyid(n.nspowner) AS schema_owner,
 (has_table_privilege(current_user,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
  OR has_any_column_privilege(current_user,c.oid,'INSERT,UPDATE,REFERENCES')) AS marker_writable,
 has_schema_privilege(current_user,n.oid,'CREATE') AS schema_writable,
 pg_has_role(current_user,c.relowner,'MEMBER') AS marker_owner_member
FROM isolation_meta.identity i
JOIN pg_catalog.pg_class c ON c.oid='isolation_meta.identity'::regclass
JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
WHERE i.id=1
SQL;

    private static function decodeAcls(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['public_acl', 'direct_acl'] as $key) {
                if (is_string($row[$key] ?? null)) {
                    $row[$key] = json_decode($row[$key], true, 512, JSON_THROW_ON_ERROR);
                }
            }
        }
        return $rows;
    }

    public const DATABASE_ACL_SQL = <<<'SQL'
SELECT d.datname AS name, pg_get_userbyid(d.datdba) AS owner, d.datallowconn AS allow_connections,
 COALESCE((SELECT json_agg(json_build_object('privilege',a.privilege_type,'grantable',a.is_grantable,
   'grantor',pg_get_userbyid(a.grantor))) FROM aclexplode(COALESCE(d.datacl,acldefault('d',d.datdba))) a
   WHERE a.grantee=0),'[]'::json) AS public_acl,
 COALESCE((SELECT json_agg(json_build_object('privilege',a.privilege_type,'grantable',a.is_grantable,
   'grantor',pg_get_userbyid(a.grantor))) FROM aclexplode(COALESCE(d.datacl,acldefault('d',d.datdba))) a
   WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user)),'[]'::json) AS direct_acl,
 has_database_privilege(current_user,d.oid,'CONNECT') AS effective_connect,
 has_database_privilege(current_user,d.oid,'TEMPORARY') AS effective_temporary,
 has_database_privilege(current_user,d.oid,'CREATE') AS effective_create
FROM pg_catalog.pg_database d ORDER BY d.datname
SQL;

    public const OBJECT_ACL_SQL = <<<'SQL'
WITH targets AS (
 SELECT 'schema:' || n.nspname AS resource,n.nspowner AS owner,
   COALESCE(n.nspacl,acldefault('n',n.nspowner)) AS acl
 FROM pg_catalog.pg_namespace n WHERE n.nspname IN ('public','isolation_meta')
 UNION ALL SELECT 'table:isolation_meta.identity',c.relowner,COALESCE(c.relacl,acldefault('r',c.relowner))
 FROM pg_catalog.pg_class c WHERE c.oid='isolation_meta.identity'::regclass
 UNION ALL SELECT 'language:plpgsql',l.lanowner,COALESCE(l.lanacl,acldefault('l',l.lanowner))
 FROM pg_catalog.pg_language l WHERE l.lanname='plpgsql'
)
SELECT t.resource,pg_get_userbyid(t.owner) AS owner,
 COALESCE((SELECT json_agg(json_build_object('privilege',a.privilege_type,'grantable',a.is_grantable,
   'grantor',pg_get_userbyid(a.grantor))) FROM aclexplode(t.acl) a WHERE a.grantee=0),'[]'::json) AS public_acl,
 COALESCE((SELECT json_agg(json_build_object('privilege',a.privilege_type,'grantable',a.is_grantable,
   'grantor',pg_get_userbyid(a.grantor))) FROM aclexplode(t.acl) a
   WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user)),'[]'::json) AS direct_acl
FROM targets t ORDER BY t.resource
SQL;
}
