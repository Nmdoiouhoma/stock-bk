<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618100124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_workstation (user_id INT NOT NULL, workstation_id INT NOT NULL, PRIMARY KEY (user_id, workstation_id))');
        $this->addSql('CREATE INDEX IDX_64E81085A76ED395 ON user_workstation (user_id)');
        $this->addSql('CREATE INDEX IDX_64E81085E29BB7D ON user_workstation (workstation_id)');
        $this->addSql('ALTER TABLE user_workstation ADD CONSTRAINT FK_64E81085A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_workstation ADD CONSTRAINT FK_64E81085E29BB7D FOREIGN KEY (workstation_id) REFERENCES workstation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_workstation DROP CONSTRAINT FK_64E81085A76ED395');
        $this->addSql('ALTER TABLE user_workstation DROP CONSTRAINT FK_64E81085E29BB7D');
        $this->addSql('DROP TABLE user_workstation');
    }
}
