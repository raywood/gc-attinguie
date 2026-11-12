<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supprime uniquement un index B-tree redondant.
 * L'index UNIQUE sur les mêmes colonnes reste en place et garantit l'unicité.
 */
final class Version20261112221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retirer un index redondant de business_billing_profiles, sans toucher aux données métier.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform), 'PostgreSQL requis.');

        // Précondition explicite pour éviter une suppression non couverte.
        $uniqueExists = (bool) $this->connection->fetchOne(<<<'SQL'
SELECT EXISTS (
    SELECT 1 FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename = 'business_billing_profiles'
      AND indexname = 'uniq_gc_billing_business_effective'
      AND indexdef ILIKE 'CREATE UNIQUE INDEX%'
      AND indexdef LIKE '%(business_id, effective_from)%'
)
SQL);
        $this->abortIf(!$uniqueExists, 'Index UNIQUE de remplacement introuvable ou différent : abandon.');

        $this->addSql('DROP INDEX IF EXISTS idx_gc_billing_business_date');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform), 'PostgreSQL requis.');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gc_billing_business_date ON business_billing_profiles (business_id, effective_from)');
    }
}
