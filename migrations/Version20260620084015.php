<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260620084015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Machine-Workstation ManyToMany : remplace la FK workstation_id par la table de jointure machine_workstation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE IF NOT EXISTS machine_workstation (machine_id INT NOT NULL, workstation_id INT NOT NULL, PRIMARY KEY (machine_id, workstation_id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_6C56EF76F6B75B26 ON machine_workstation (machine_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_6C56EF76E29BB7D ON machine_workstation (workstation_id)');
        $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'FK_6C56EF76F6B75B26') THEN ALTER TABLE machine_workstation ADD CONSTRAINT FK_6C56EF76F6B75B26 FOREIGN KEY (machine_id) REFERENCES machine (id) ON DELETE CASCADE; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'FK_6C56EF76E29BB7D') THEN ALTER TABLE machine_workstation ADD CONSTRAINT FK_6C56EF76E29BB7D FOREIGN KEY (workstation_id) REFERENCES workstation (id) ON DELETE CASCADE; END IF; END \$\$;");
        $this->addSql('ALTER TABLE machine DROP CONSTRAINT IF EXISTS fk_machine_workstation');
        $this->addSql('DROP INDEX IF EXISTS idx_1505df84e29bb7d');
        $this->addSql('ALTER TABLE machine DROP COLUMN IF EXISTS workstation_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE machine_workstation DROP CONSTRAINT FK_6C56EF76F6B75B26');
        $this->addSql('ALTER TABLE machine_workstation DROP CONSTRAINT FK_6C56EF76E29BB7D');
        $this->addSql('DROP TABLE machine_workstation');
        $this->addSql('ALTER TABLE machine ADD workstation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE machine ADD CONSTRAINT fk_machine_workstation FOREIGN KEY (workstation_id) REFERENCES workstation (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_1505df84e29bb7d ON machine (workstation_id)');
    }
}
