<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026145526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uniq_activity_types_code RENAME TO UNIQ_4732BF8377153098');
        $this->addSql('DROP INDEX uniq_business_primary_manager');
        $this->addSql('CREATE UNIQUE INDEX uniq_business_primary_manager ON business_managers (business_id) WHERE (is_primary = true AND ended_at IS NULL)');
        $this->addSql('ALTER INDEX idx_bm_business RENAME TO IDX_A179EE90A89DB457');
        $this->addSql('ALTER INDEX idx_bm_manager RENAME TO IDX_A179EE90783E3463');
        $this->addSql('ALTER INDEX idx_invoice_lines_invoice RENAME TO IDX_72DBDC232989F1FD');
        $this->addSql('COMMENT ON COLUMN invoices.issued_at IS \'\'');
        $this->addSql('ALTER INDEX uniq_invoices_number RENAME TO UNIQ_6A2F2F9596901F54');
        $this->addSql('ALTER INDEX idx_invoices_created_by RENAME TO IDX_6A2F2F95B03A8386');
        $this->addSql('COMMENT ON COLUMN payments.received_at IS \'\'');
        $this->addSql('ALTER INDEX idx_payments_invoice RENAME TO IDX_65D29B322989F1FD');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER INDEX uniq_4732bf8377153098 RENAME TO uniq_activity_types_code');
        $this->addSql('DROP INDEX uniq_business_primary_manager');
        $this->addSql('CREATE UNIQUE INDEX uniq_business_primary_manager ON business_managers (business_id) WHERE ((is_primary = true) AND (ended_at IS NULL))');
        $this->addSql('ALTER INDEX idx_a179ee90783e3463 RENAME TO idx_bm_manager');
        $this->addSql('ALTER INDEX idx_a179ee90a89db457 RENAME TO idx_bm_business');
        $this->addSql('ALTER INDEX idx_72dbdc232989f1fd RENAME TO idx_invoice_lines_invoice');
        $this->addSql('COMMENT ON COLUMN invoices.issued_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER INDEX uniq_6a2f2f9596901f54 RENAME TO uniq_invoices_number');
        $this->addSql('ALTER INDEX idx_6a2f2f95b03a8386 RENAME TO idx_invoices_created_by');
        $this->addSql('COMMENT ON COLUMN payments.received_at IS \'(DC2Type:datetimetz_immutable)\'');
        $this->addSql('ALTER INDEX idx_65d29b322989f1fd RENAME TO idx_payments_invoice');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetimetz_immutable)\'');
    }
}
