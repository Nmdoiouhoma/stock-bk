<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625065025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add estimated_duration to production_order and normalize index names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_order ADD estimated_duration DOUBLE PRECISION DEFAULT NULL');

        $this->addSql('ALTER INDEX IF EXISTS idx_op_routing_op RENAME TO IDX_639393744AC3583');
        $this->addSql('ALTER INDEX IF EXISTS idx_op_routing_rt RENAME TO IDX_639393758735C4C');
        $this->addSql('ALTER INDEX IF EXISTS idx_4b1e375344ac3583 RENAME TO IDX_EF2857AE44AC3583');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_order DROP COLUMN estimated_duration');
    }
}
