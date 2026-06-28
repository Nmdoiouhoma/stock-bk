<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Operation ↔ Routing : ManyToOne → ManyToMany via table operation_routing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE operation_routing (operation_id INT NOT NULL, routing_id INT NOT NULL, PRIMARY KEY (operation_id, routing_id))');
        $this->addSql('CREATE INDEX IDX_OP_ROUTING_OP ON operation_routing (operation_id)');
        $this->addSql('CREATE INDEX IDX_OP_ROUTING_RT ON operation_routing (routing_id)');
        $this->addSql('ALTER TABLE operation_routing ADD CONSTRAINT FK_OP_ROUTING_OP FOREIGN KEY (operation_id) REFERENCES operation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE operation_routing ADD CONSTRAINT FK_OP_ROUTING_RT FOREIGN KEY (routing_id) REFERENCES routing (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Migrate existing data: copy routing_id into the join table
        $this->addSql('INSERT INTO operation_routing (operation_id, routing_id) SELECT id, routing_id FROM operation WHERE routing_id IS NOT NULL');

        $this->addSql('ALTER TABLE operation DROP CONSTRAINT fk_operation_routing');
        $this->addSql('DROP INDEX IF EXISTS idx_operation_routing_id');
        $this->addSql('ALTER TABLE operation DROP routing_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation ADD routing_id INT DEFAULT NULL');
        $this->addSql('UPDATE operation o SET routing_id = (SELECT routing_id FROM operation_routing WHERE operation_id = o.id LIMIT 1)');
        $this->addSql('ALTER TABLE operation ALTER routing_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_operation_routing_id ON operation (routing_id)');
        $this->addSql('ALTER TABLE operation ADD CONSTRAINT fk_operation_routing FOREIGN KEY (routing_id) REFERENCES routing (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE operation_routing DROP CONSTRAINT FK_OP_ROUTING_OP');
        $this->addSql('ALTER TABLE operation_routing DROP CONSTRAINT FK_OP_ROUTING_RT');
        $this->addSql('DROP TABLE operation_routing');
    }
}
