<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Empêche les références de paiement en double sur une même facture';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform),
            'Cette migration nécessite PostgreSQL.'
        );
        // Un échec ici n'altère pas les paiements : traiter les doublons manuellement avant migration.
        $this->addSql(<<<'SQL'
DO $block$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM payments
        WHERE reference IS NOT NULL
        GROUP BY invoice_id, reference
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION 'Références de paiements en double : migration refusée';
    END IF;
END
$block$;
SQL);
        // PostgreSQL permet plusieurs NULL sous une contrainte UNIQUE : utile aux données historiques.
        $this->addSql('CREATE UNIQUE INDEX uniq_payments_invoice_reference ON payments (invoice_id, reference)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'La suppression de la protection anti-doublon requiert une décision explicite.');
    }
}
