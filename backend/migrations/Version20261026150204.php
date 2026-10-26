<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261026150204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_business_primary_manager');
        $this->addSql('CREATE UNIQUE INDEX uniq_business_primary_manager ON business_managers (business_id) WHERE (is_primary = true AND ended_at IS NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_business_primary_manager');
        $this->addSql('CREATE UNIQUE INDEX uniq_business_primary_manager ON business_managers (business_id) WHERE ((is_primary = true) AND (ended_at IS NULL))');
    }
}
