<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order: make quote_id nullable, add total_amount';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ALTER COLUMN quote_id DROP NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD total_amount NUMERIC(10, 2) NOT NULL DEFAULT \'0\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN total_amount');
        $this->addSql('ALTER TABLE "order" ALTER COLUMN quote_id SET NOT NULL');
    }
}
