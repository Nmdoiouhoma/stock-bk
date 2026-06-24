<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace les tables forecast et completion par une seule table production_order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE production_order (id SERIAL NOT NULL, operation_id INT NOT NULL, planned_date DATE NOT NULL, planned_quantity INT NOT NULL, actual_quantity INT DEFAULT NULL, actual_duration DOUBLE PRECISION DEFAULT NULL, status VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4B1E375344AC3583 ON production_order (operation_id)');
        $this->addSql('ALTER TABLE production_order ADD CONSTRAINT FK_4B1E375344AC3583 FOREIGN KEY (operation_id) REFERENCES operation (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE forecast DROP CONSTRAINT fk_forecast_operation');
        $this->addSql('DROP TABLE forecast');

        $this->addSql('ALTER TABLE completion DROP CONSTRAINT fk_completion_operation');
        $this->addSql('DROP TABLE completion');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE production_order DROP CONSTRAINT FK_4B1E375344AC3583');
        $this->addSql('DROP TABLE production_order');

        $this->addSql('CREATE TABLE forecast (id SERIAL NOT NULL, operation_id INT NOT NULL, planned_date DATE NOT NULL, planned_quantity INT NOT NULL, status VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_forecast_operation ON forecast (operation_id)');
        $this->addSql('ALTER TABLE forecast ADD CONSTRAINT fk_forecast_operation FOREIGN KEY (operation_id) REFERENCES operation (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE completion (id SERIAL NOT NULL, operation_id INT NOT NULL, date DATE NOT NULL, actual_quantity INT NOT NULL, actual_duration DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_completion_operation ON completion (operation_id)');
        $this->addSql('ALTER TABLE completion ADD CONSTRAINT fk_completion_operation FOREIGN KEY (operation_id) REFERENCES operation (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
