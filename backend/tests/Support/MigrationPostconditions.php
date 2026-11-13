<?php
declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/** Catalogue checks run inside the outer transaction, before commit. No repair SQL. */
final class MigrationPostconditions
{
    public static function identity(Connection $connection, bool $after = false): array
    {
        $sql = MigrationContext::IDENTITY_SQL;
        if ($after) {
            // Owned application objects legitimately have owner ACLs after DDL.
            // Only that narrowly scoped exception is permitted; the original guard stays strict.
            $sql = str_replace("AND x.oid <> 'isolation_meta.identity'::regclass",
                "AND x.oid <> 'isolation_meta.identity'::regclass AND NOT (x.relnamespace='public'::regnamespace AND x.relowner=(SELECT oid FROM pg_roles WHERE rolname=current_user))", $sql);
            $needle = "WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user))\n  OR EXISTS (SELECT 1 FROM pg_catalog.pg_language";
            $sql = str_replace($needle, "WHERE a.grantee=(SELECT oid FROM pg_catalog.pg_roles WHERE rolname=current_user)\n       AND NOT (x.pronamespace='public'::regnamespace AND x.proowner=(SELECT oid FROM pg_roles WHERE rolname=current_user)))\n  OR EXISTS (SELECT 1 FROM pg_catalog.pg_language", $sql, $count);
            if ($count !== 1) {
                throw new \LogicException('Post-migration identity SQL requires review.');
            }
        }
        $row = $connection->fetchAssociative($sql);
        MigrationIdentityGuard::assertIdentity($row === false ? [] : $row);
        foreach (['databases' => MigrationContext::DATABASE_ACL_SQL, 'object_acls' => MigrationContext::OBJECT_ACL_SQL] as $key => $query) {
            $row[$key] = $connection->fetchAllAssociative($query);
            foreach ($row[$key] as &$acl) {
                foreach (['public_acl', 'direct_acl'] as $field) {
                    if (is_string($acl[$field] ?? null)) {
                        $acl[$field] = json_decode($acl[$field], true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            }
            unset($acl);
        }
        MigrationIdentityGuard::assertSafe($row);
        return $row;
    }

    public static function specification(): array
    {
        $path = dirname(__DIR__) . '/migrations.postconditions.json';
        if (is_link($path) || !hash_equals('7ab2997f6920c1fd7c69c6d9aeaead299087a38111f1ed74db1f457d82f1e555', hash_file('sha256', $path))) {
            throw new \LogicException('Postcondition inventory changed: review required.');
        }
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function verify(Connection $connection, array $before): void
    {
        $after = self::identity($connection, true);
        if (self::canonical($before) !== self::canonical($after)) {
            throw new \LogicException('Identity, protected marker or ACL snapshot changed.');
        }
        $snapshot = [];
        foreach (['relations' => self::RELATIONS_SQL, 'constraints' => self::CONSTRAINTS_SQL,
            'indexes' => self::INDEXES_SQL, 'functions' => self::FUNCTIONS_SQL, 'triggers' => self::TRIGGERS_SQL] as $key => $sql) {
            $snapshot[$key] = $connection->fetchAllAssociative($sql);
        }
        $snapshot['versions'] = $connection->fetchFirstColumn('SELECT version FROM public.doctrine_migration_versions ORDER BY version');
        self::assertSnapshot($snapshot);
        if ((int) $connection->fetchOne(self::EXTRA_OBJECTS_SQL) !== 0) {
            throw new \LogicException('Unexpected application types or rewrite rules.');
        }
        self::assertSeeds($connection);
    }

    public static function assertSnapshot(array $snapshot): void
    {
        $spec = self::specification();
        $tables = [...MigrationPlan::inspect()['tables'], 'doctrine_migration_versions'];
        $expectedRelations = array_fill_keys($tables, 'r');
        foreach (array_keys($spec['indexes']) as $name) {
            $expectedRelations[$name] = 'i';
        }
        foreach (array_diff($tables, ['app_branding', 'doctrine_migration_versions']) as $table) {
            $expectedRelations[$table . '_id_seq'] = 'S';
        }
        $actualRelations = [];
        foreach ($snapshot['relations'] ?? [] as $row) {
            if (isset($actualRelations[$row['name']]) || $row['owner'] !== 'gc_test_migrator'
                || $row['persistence'] !== 'p' || !self::false($row['unsafe'])) {
                throw new \LogicException('Unexpected relation ownership, ACL, RLS or persistence.');
            }
            $actualRelations[$row['name']] = $row['kind'];
        }
        if (self::canonical($expectedRelations) !== self::canonical($actualRelations)) {
            throw new \LogicException('Unexpected tables, sequences, indexes or views.');
        }
        MigrationPlan::assertCompletedState(array_keys(array_filter($actualRelations, static fn ($kind): bool => $kind === 'r')), $snapshot['versions'] ?? []);
        $constraints = [];
        foreach ($snapshot['constraints'] ?? [] as $row) {
            if (!self::true($row['validated']) || !self::false($row['deferrable']) || isset($constraints[$row['name']])) {
                throw new \LogicException('Unvalidated, duplicate or deferred constraint.');
            }
            $entry = ['type' => $row['type']];
            if ($row['type'] === 'f') {
                foreach (['columns', 'target', 'target_columns', 'delete_action'] as $key) {
                    $entry[$key] = $row[$key];
                }
                if ($row['target_schema'] !== 'public' || $row['update_action'] !== 'a') {
                    throw new \LogicException('Unexpected foreign key target or update action.');
                }
            } elseif ($row['type'] === 'p' && $row['columns'] !== (str_starts_with($row['name'], 'doctrine_migration_versions.') ? 'version' : 'id')) {
                throw new \LogicException('Unexpected primary key columns.');
            } elseif ($row['type'] === 'c') {
                $entry['expression'] = self::expression($row['expression']);
            }
            $constraints[$row['name']] = $entry;
        }
        $expectedConstraints = $spec['constraints'];
        foreach ($expectedConstraints as &$entry) {
            if (isset($entry['expression'])) {
                $entry['expression'] = self::expression($entry['expression']);
            }
        }
        unset($entry);
        if (self::canonical($constraints) !== self::canonical($expectedConstraints)) {
            throw new \LogicException('Constraint inventory or definition differs.');
        }
        $indexes = [];
        foreach ($snapshot['indexes'] ?? [] as $row) {
            if (isset($indexes[$row['name']]) || !self::true($row['valid']) || !self::true($row['ready']) || $row['method'] !== 'btree'
                || !self::false($row['unsafe']) || (!self::true($row['unique']) && !self::false($row['unique']))) {
                throw new \LogicException('Invalid, duplicate or unexpected index.');
            }
            $indexes[$row['name']] = ['table' => $row['table'], 'unique' => self::true($row['unique']),
                'keys' => self::expression($row['keys']), 'predicate' => self::expression($row['predicate'] ?? '')];
        }
        $expectedIndexes = $spec['indexes'];
        foreach ($expectedIndexes as &$entry) {
            $entry['keys'] = self::expression($entry['keys']);
            $entry['predicate'] = self::expression($entry['predicate']);
        }
        unset($entry);
        if (self::canonical($indexes) !== self::canonical($expectedIndexes)) {
            throw new \LogicException('Index definition differs, or a removed index is still present.');
        }
        $functions = $snapshot['functions'] ?? [];
        $source = file_get_contents(dirname(__DIR__, 2) . '/migrations/Version20261027220000.php');
        preg_match('/AS \$body\$(.*?)\$body\$ LANGUAGE plpgsql/s', $source, $body);
        if (count($functions) !== 1 || $functions[0]['name'] !== 'gc_guard_receipts'
            || $functions[0]['owner'] !== 'gc_test_migrator' || $functions[0]['language'] !== 'plpgsql'
            || $functions[0]['result'] !== 'trigger' || (string) $functions[0]['arguments'] !== '0'
            || !self::false($functions[0]['unsafe']) || trim($functions[0]['body']) !== trim($body[1] ?? '')) {
            throw new \LogicException('Receipt function differs.');
        }
        $triggers = $snapshot['triggers'] ?? [];
        if (count($triggers) !== 1 || $triggers[0] !== ['name' => 'gc_receipts_no_update', 'table' => 'receipts',
            'function' => 'gc_guard_receipts', 'function_schema' => 'public', 'enabled' => 'O', 'type' => '27', 'qualifier' => null]) {
            throw new \LogicException('Receipt trigger differs or is disabled.');
        }
    }

    /** Normalizes only PostgreSQL display syntax; never used to authorize executable SQL. */
    private static function expression(string $sql): string
    {
        $sql = strtolower($sql);
        $sql = preg_replace('/::(?:character varying|text|numeric|integer|bpchar)(?:\[\])?/', '', $sql);
        $sql = preg_replace('/\bin\s*\(/', '= ANY (ARRAY[', $sql);
        return preg_replace('/[\s()\[\]]+/', '', $sql);
    }

    private static function canonical(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                // DBAL may expose catalog booleans as native bool or PostgreSQL strings.
                return match ($item) { 't' => true, 'f' => false, default => $item };
            }
            $item = array_map($normalize, $item);
            if (array_is_list($item)) {
                usort($item, static fn ($a, $b): int => json_encode($a) <=> json_encode($b));
            } else {
                ksort($item);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }

    private static function true(mixed $value): bool { return in_array($value, [true, 't', 1, '1'], true); }
    private static function false(mixed $value): bool { return in_array($value, [false, 'f', 0, '0'], true); }

    private static function assertSeeds(Connection $connection): void
    {
        $fees = ['small_restaurant' => '5000.00', 'large_restaurant' => '10000.00', 'bar' => '5000.00',
            'maquis' => '15000.00', 'bazaar' => '20000.00', 'shopping_center' => '30000.00', 'parking' => '10000.00',
            'market_stall' => '5000.00', 'taxi' => '10000.00', 'pharmacy' => '15000.00', 'nightclub' => '15000.00'];
        $actual = [];
        foreach ($connection->fetchAllAssociative('SELECT code, monthly_fee::text AS fee, currency FROM public.activity_types') as $row) {
            if ($row['currency'] !== 'XOF' || isset($actual[$row['code']])) {
                throw new \LogicException('Activity reference data differs.');
            }
            $actual[$row['code']] = $row['fee'];
        }
        if (self::canonical($actual) !== self::canonical($fees)
            || (int) $connection->fetchOne("SELECT count(*) FROM public.activity_tariffs t JOIN public.activity_types a ON a.id=t.activity_type_id WHERE t.period='monthly' AND t.amount=a.monthly_fee AND t.currency='XOF' AND t.is_active") !== 11
            || (int) $connection->fetchOne('SELECT count(*) FROM public.activity_tariffs') !== 11) {
            throw new \LogicException('Initial tariff data differs.');
        }
        $roles = $connection->fetchFirstColumn('SELECT code FROM public.security_roles WHERE is_system');
        sort($roles);
        if ($roles !== ['ROLE_ADMIN', 'ROLE_AGENT', 'ROLE_COMPTABLE', 'ROLE_REGISSEUR']
            || (int) $connection->fetchOne('SELECT count(*) FROM public.security_roles') !== 4
            || (int) $connection->fetchOne("SELECT count(*) FROM public.app_branding WHERE id=1 AND app_name='GC-ATTINGUIÉ' AND organization_name='Commune d’Attinguié' AND dashboard_description='La plateforme de gestion des commerces de la Commune d’Attinguié.' AND logo_filename IS NULL AND version=1 AND updated_by_id IS NULL") !== 1) {
            throw new \LogicException('Initial roles or branding differs.');
        }
        foreach (array_diff(MigrationPlan::manifest()['tables'], ['activity_types', 'activity_tariffs', 'security_roles', 'app_branding']) as $table) {
            // Identifiers originate only from the pinned source inventory, never user input.
            if (!preg_match('/^[a-z_]+$/D', $table) || (int) $connection->fetchOne('SELECT count(*) FROM public.' . $connection->quoteIdentifier($table)) !== 0) {
                throw new \LogicException('Unexpected initial application data.');
            }
        }
        if ((int) $connection->fetchOne('SELECT count(*) FROM isolation_meta.identity') !== 1) {
            throw new \LogicException('Protected marker cardinality changed.');
        }
    }

    public const RELATIONS_SQL = <<<'SQL'
SELECT c.relname AS name,c.relkind AS kind,pg_get_userbyid(c.relowner) AS owner,c.relpersistence AS persistence,
 (c.relrowsecurity OR c.relforcerowsecurity OR EXISTS (SELECT 1 FROM aclexplode(c.relacl) a WHERE a.grantee<>c.relowner)) AS unsafe
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' ORDER BY c.relname
SQL;
    public const CONSTRAINTS_SQL = <<<'SQL'
SELECT c.relname||'.'||x.conname AS name,x.contype AS type,x.convalidated AS validated,x.condeferrable AS deferrable,
 (SELECT string_agg(a.attname,',' ORDER BY k.i) FROM unnest(x.conkey) WITH ORDINALITY k(num,i) JOIN pg_attribute a ON a.attrelid=x.conrelid AND a.attnum=k.num) AS columns,
 r.relname AS target,rn.nspname AS target_schema,
 (SELECT string_agg(a.attname,',' ORDER BY k.i) FROM unnest(x.confkey) WITH ORDINALITY k(num,i) JOIN pg_attribute a ON a.attrelid=x.confrelid AND a.attnum=k.num) AS target_columns,
 x.confdeltype AS delete_action,x.confupdtype AS update_action,pg_get_expr(x.conbin,x.conrelid) AS expression
FROM pg_constraint x JOIN pg_class c ON c.oid=x.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace
LEFT JOIN pg_class r ON r.oid=x.confrelid LEFT JOIN pg_namespace rn ON rn.oid=r.relnamespace WHERE n.nspname='public' ORDER BY name
SQL;
    public const INDEXES_SQL = <<<'SQL'
SELECT i.relname AS name,c.relname AS table,x.indisunique AS unique,x.indisvalid AS valid,x.indisready AS ready,am.amname AS method,
 (x.indnullsnotdistinct OR x.indnatts<>x.indnkeyatts OR x.indisexclusion) AS unsafe,
 (SELECT string_agg(pg_get_indexdef(x.indexrelid,k,true),',' ORDER BY k) FROM generate_series(1,x.indnkeyatts) k) AS keys,
 COALESCE(pg_get_expr(x.indpred,x.indrelid),'') AS predicate
FROM pg_index x JOIN pg_class i ON i.oid=x.indexrelid JOIN pg_class c ON c.oid=x.indrelid JOIN pg_namespace n ON n.oid=c.relnamespace
JOIN pg_am am ON am.oid=i.relam WHERE n.nspname='public' ORDER BY name
SQL;
    public const FUNCTIONS_SQL = <<<'SQL'
SELECT p.proname AS name,pg_get_userbyid(p.proowner) AS owner,l.lanname AS language,p.prorettype::regtype::text AS result,
 p.pronargs::text AS arguments,p.prosrc AS body,
 (p.prosecdef OR p.proconfig IS NOT NULL OR EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee<>p.proowner AND NOT (a.grantee=0 AND a.privilege_type='EXECUTE' AND NOT a.is_grantable))) AS unsafe
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_language l ON l.oid=p.prolang WHERE n.nspname='public' ORDER BY name
SQL;
    public const TRIGGERS_SQL = <<<'SQL'
SELECT t.tgname AS name,c.relname AS table,p.proname AS function,pn.nspname AS function_schema,t.tgenabled::text AS enabled,
 t.tgtype::text AS type,pg_get_expr(t.tgqual,t.tgrelid) AS qualifier
FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
JOIN pg_proc p ON p.oid=t.tgfoid JOIN pg_namespace pn ON pn.oid=p.pronamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY name
SQL;
    public const EXTRA_OBJECTS_SQL = <<<'SQL'
SELECT (SELECT count(*) FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE n.nspname='public'
 AND NOT (t.typtype='c' AND EXISTS (SELECT 1 FROM pg_class c WHERE c.oid=t.typrelid AND c.relkind='r'))
 AND NOT (EXISTS (SELECT 1 FROM pg_type e JOIN pg_class c ON c.oid=e.typrelid WHERE e.oid=t.typelem AND e.typarray=t.oid AND c.relkind='r' AND c.relnamespace=n.oid)))
 + (SELECT count(*) FROM pg_rewrite r JOIN pg_class c ON c.oid=r.ev_class JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public')
SQL;
}
