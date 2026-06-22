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
        $this->addSql('ALTER TABLE routing ADD COLUMN IF NOT EXISTS supervisor_id INT');
        $this->addSql('UPDATE routing SET supervisor_id = (SELECT id FROM "user" ORDER BY id LIMIT 1) WHERE supervisor_id IS NULL');
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'routing' AND column_name = 'supervisor_id' AND is_nullable = 'YES') THEN ALTER TABLE routing ALTER COLUMN supervisor_id SET NOT NULL; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'FK_A5F8B9FA19E9AC5F') THEN ALTER TABLE routing ADD CONSTRAINT FK_A5F8B9FA19E9AC5F FOREIGN KEY (supervisor_id) REFERENCES \"user\" (id) NOT DEFERRABLE; END IF; END \$\$;");
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_A5F8B9FA19E9AC5F ON routing (supervisor_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE routing DROP CONSTRAINT FK_A5F8B9FA19E9AC5F');
        $this->addSql('DROP INDEX IDX_A5F8B9FA19E9AC5F');
        $this->addSql('ALTER TABLE routing DROP supervisor_id');
    }
}
