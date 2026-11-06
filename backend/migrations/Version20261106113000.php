<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lier les identités de gérants aux comptes utilisateurs et garantir l’unicité des e-mails sans modifier les données métier.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform), 'PostgreSQL requis.');
        $duplicates = $this->connection->fetchFirstColumn(
            'SELECT LOWER(email) FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1 LIMIT 5'
        );
        $this->abortIf($duplicates !== [], 'Doublons e-mail insensibles à la casse : régulariser les comptes avant migration.');
        $this->addSql('ALTER TABLE managers ADD user_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_GC_MANAGERS_USER ON managers (user_id)');
        $this->addSql('ALTER TABLE managers ADD CONSTRAINT FK_GC_MANAGERS_USER FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_GC_USERS_EMAIL_LOWER ON users (LOWER(email))');
    }

    public function down(Schema $schema): void
    {
        throw new \RuntimeException('Rollback automatique interdit : ne pas supprimer les associations de comptes et gérants.');
    }
}
