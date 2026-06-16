<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616065859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routing ADD supervisor_id INT');
        $this->addSql('UPDATE routing SET supervisor_id = (SELECT id FROM "user" ORDER BY id LIMIT 1) WHERE supervisor_id IS NULL');
        $this->addSql('ALTER TABLE routing ALTER COLUMN supervisor_id SET NOT NULL');
        $this->addSql('ALTER TABLE routing ADD CONSTRAINT FK_A5F8B9FA19E9AC5F FOREIGN KEY (supervisor_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A5F8B9FA19E9AC5F ON routing (supervisor_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE routing DROP CONSTRAINT FK_A5F8B9FA19E9AC5F');
        $this->addSql('DROP INDEX IDX_A5F8B9FA19E9AC5F');
        $this->addSql('ALTER TABLE routing DROP supervisor_id');
    }
}
