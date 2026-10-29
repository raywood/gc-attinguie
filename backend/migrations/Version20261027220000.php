<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annulation irréversible des reçus : motif, date, administrateur, garde-fous SQL';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform), 'Migration PostgreSQL seulement.');
        $this->addSql('ALTER TABLE receipts ADD cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE receipts ADD cancelled_by_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE receipts ADD cancellation_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE receipts ADD cancelled_by_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_receipts_cancelled_by ON receipts (cancelled_by_user_id)');
        $this->addSql('ALTER TABLE receipts ADD CONSTRAINT FK_RECEIPTS_CANCELLED_BY FOREIGN KEY (cancelled_by_user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql(<<<'SQL'
ALTER TABLE receipts ADD CONSTRAINT chk_receipts_cancellation_data CHECK (
  (status = 'issued' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL AND cancelled_by_email IS NULL)
  OR
  (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL
   AND cancellation_reason IS NOT NULL AND cancelled_by_email IS NOT NULL
   AND char_length(btrim(cancellation_reason)) BETWEEN 10 AND 500)
)
SQL);
        // Le contrôle s'exécute aussi si du code extérieur essaie de modifier directement la base.
        $this->addSql(<<<'SQL'
CREATE FUNCTION gc_guard_receipts() RETURNS trigger AS $body$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'Suppression des reçus interdite';
  END IF;
  IF OLD.status <> 'issued' OR NEW.status <> 'cancelled' THEN
    RAISE EXCEPTION 'Seule la transition issued -> cancelled est autorisée';
  END IF;
  IF (to_jsonb(NEW) - ARRAY['status','cancelled_at','cancelled_by_user_id','cancellation_reason','cancelled_by_email'])
       IS DISTINCT FROM
     (to_jsonb(OLD) - ARRAY['status','cancelled_at','cancelled_by_user_id','cancellation_reason','cancelled_by_email']) THEN
    RAISE EXCEPTION 'Les données signées d''un reçu sont immuables';
  END IF;
  RETURN NEW;
END;
$body$ LANGUAGE plpgsql
SQL);
        $this->addSql('CREATE TRIGGER gc_receipts_no_update BEFORE UPDATE OR DELETE ON receipts FOR EACH ROW EXECUTE FUNCTION gc_guard_receipts()');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Annulation d’une migration d’audit interdite. Restaurer uniquement via une procédure contrôlée.');
    }
}
