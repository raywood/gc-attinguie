<?php
declare(strict_types=1);

namespace App\Tests\Support;

/** Narrow, fail-closed static policy for this one script; not a PostgreSQL parser. */
final class ProvisioningSqlPolicy
{
    public static function assertSafe(string $sql): void
    {
        $tokens = self::tokens($sql);
        $statements = [];
        $statement = [];
        $guards = 0;
        foreach ($tokens as [$kind, $value]) {
            if ($kind === 'dollar') {
                if ($statement !== ['DO'] || !str_starts_with($value, '$guard$')) {
                    throw new \LogicException('Unexpected SQL block.');
                }
                ++$guards;
                self::assertReadOnlyGuard(substr($value, 7, -7));
                $statement[] = 'GUARD';
            } elseif ($value === ';') {
                $statements[] = implode(' ', $statement);
                $statement = [];
            } else {
                $statement[] = $kind === 'word' ? strtoupper($value) : $value;
            }
        }
        if ($statement !== [] || $guards !== 1 || $statements !== self::allowedStatements()) {
            throw new \LogicException('Provisioning may only configure the newly created migrator.');
        }
    }

    private static function allowedStatements(): array
    {
        return [
            'BEGIN',
            "SET LOCAL STATEMENT_TIMEOUT = '10s'",
            "SET LOCAL LOCK_TIMEOUT = '3s'",
            'SET LOCAL SEARCH_PATH = PG_CATALOG',
            'DO GUARD',
            "CREATE ROLE GC_TEST_MIGRATOR LOGIN PASSWORD 'migration-test-only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT",
            'GRANT CONNECT ON DATABASE GC_ATTINGUIE_TEST TO GC_TEST_MIGRATOR',
            'GRANT USAGE , CREATE ON SCHEMA PUBLIC TO GC_TEST_MIGRATOR',
            'GRANT USAGE ON LANGUAGE PLPGSQL TO GC_TEST_MIGRATOR',
            'GRANT USAGE ON SCHEMA ISOLATION_META TO GC_TEST_MIGRATOR',
            'GRANT SELECT ON ISOLATION_META . IDENTITY TO GC_TEST_MIGRATOR',
            'ALTER ROLE GC_TEST_MIGRATOR IN DATABASE GC_ATTINGUIE_TEST SET SEARCH_PATH = PUBLIC',
            "ALTER ROLE GC_TEST_MIGRATOR IN DATABASE GC_ATTINGUIE_TEST SET STATEMENT_TIMEOUT = '10s'",
            "ALTER ROLE GC_TEST_MIGRATOR IN DATABASE GC_ATTINGUIE_TEST SET LOCK_TIMEOUT = '3s'",
            "ALTER ROLE GC_TEST_MIGRATOR IN DATABASE GC_ATTINGUIE_TEST SET IDLE_IN_TRANSACTION_SESSION_TIMEOUT = '10s'",
            'COMMIT',
        ];
    }

    private static function assertReadOnlyGuard(string $body): void
    {
        $tokens = self::tokens($body);
        $forbidden = ['EXECUTE', 'PERFORM', 'CALL', 'COPY', 'CREATE', 'ALTER', 'GRANT', 'REVOKE',
            'DROP', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'SET', 'RESET', 'INTO', 'DO',
            'COMMIT', 'ROLLBACK', 'LOOP', 'WHILE', 'FOR'];
        $functions = ['CURRENT_DATABASE', 'CURRENT_SETTING', 'PG_GET_USERBYID', 'ACLEXPLODE',
            'COALESCE', 'ACLDEFAULT', 'HAS_SCHEMA_PRIVILEGE', 'HAS_TABLE_PRIVILEGE',
            'HAS_ANY_COLUMN_PRIVILEGE', 'PG_HAS_ROLE', 'COUNT', 'IF', 'IN', 'EXISTS', 'AND', 'OR', 'NOT', 'FROM'];
        foreach ($tokens as $i => [$kind, $value]) {
            if ($kind === 'dollar' || $value === '"' || $value === '\\') {
                throw new \LogicException('Opaque guard code is forbidden.');
            }
            if ($kind !== 'word') {
                continue;
            }
            $word = strtoupper($value);
            if (in_array($word, $forbidden, true)) {
                throw new \LogicException('Guard must not change privileges or execute SQL indirectly.');
            }
            if (($tokens[$i + 1][1] ?? null) === '(') {
                if (!in_array($word, $functions, true)
                    || (($tokens[$i - 1][1] ?? null) === '.' && strtoupper($tokens[$i - 2][1] ?? '') !== 'PG_CATALOG')) {
                    throw new \LogicException('Unapproved function in provisioning guard.');
                }
            }
        }
    }

    /** Tokenizes only the lexical subset used here, retaining quoted/block content. */
    private static function tokens(string $sql): array
    {
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($sql)) {
            if (!preg_match('~\G(?:(?<space>\s+)|(?<comment>--[^\r\n]*|/\*(?:(?!/\*|\*/)[\s\S])*\*/)|(?<string>\x27(?:\x27\x27|[^\x27])*\x27)|(?<dollar>\$(?<tag>[A-Za-z_][A-Za-z_0-9]*)?\$[\s\S]*?\$\k<tag>\$)|(?<word>[A-Za-z_][A-Za-z_0-9]*)|(?<symbol>[^\s]))~', $sql, $match, PREG_UNMATCHED_AS_NULL, $offset)) {
                throw new \LogicException('Unsupported SQL syntax.');
            }
            $offset += strlen($match[0]);
            foreach (['space', 'comment', 'string', 'dollar', 'word', 'symbol'] as $kind) {
                if ($match[$kind] !== null) {
                    if (!in_array($kind, ['space', 'comment'], true)) {
                        $tokens[] = [$kind, $match[$kind]];
                    }
                    break;
                }
            }
        }
        return $tokens;
    }
}
