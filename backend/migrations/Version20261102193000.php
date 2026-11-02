<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alignement du schéma QR Code avec Doctrine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE businesses ALTER COLUMN qr_version DROP DEFAULT'
        );

        $this->addSql(
            'ALTER INDEX uniq_businesses_qr_public_token RENAME TO UNIQ_2DCA55EC98C24F5B'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER INDEX uniq_2dca55ec98c24f5b RENAME TO uniq_businesses_qr_public_token'
        );

        $this->addSql(
            'ALTER TABLE businesses ALTER COLUMN qr_version SET DEFAULT 1'
        );
    }
}
