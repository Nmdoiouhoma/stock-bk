<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename legacy operation columns to match entities (rang->rank, temps_unitaire->unit_time, poste_de_travail_id->workstation_id) and remove duplicate FK on part.supplier_id';
    }

    public function up(Schema $schema): void
    {
        // Rename columns in operation table if they exist (no-op on fresh DB)
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'rang') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN rang TO \"rank\"'; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'temps_unitaire') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN temps_unitaire TO unit_time'; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'poste_de_travail_id') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN poste_de_travail_id TO workstation_id'; END IF; END \$\$;");

        // Drop old FK / add new FK on operation — only if the table exists
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'operation') THEN ALTER TABLE operation DROP CONSTRAINT IF EXISTS fk_1981a66d17a47ee6; ALTER TABLE operation DROP CONSTRAINT IF EXISTS FK_1981A66D17A47EE6; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_operation_workstation') THEN EXECUTE 'ALTER TABLE operation ADD CONSTRAINT fk_operation_workstation FOREIGN KEY (workstation_id) REFERENCES workstation (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // Remove duplicate supplier FK / ensure canonical FK on part — only if the table exists
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'part') THEN ALTER TABLE part DROP CONSTRAINT IF EXISTS fk_44ca0b232add6d8c; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_part_supplier') THEN EXECUTE 'ALTER TABLE part ADD CONSTRAINT fk_part_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        // Remove canonical fk added
        $this->addSql('ALTER TABLE operation DROP CONSTRAINT IF EXISTS fk_operation_workstation');
        $this->addSql('ALTER TABLE part DROP CONSTRAINT IF EXISTS fk_part_supplier');

        // Try to restore old FK name (best-effort) and column names
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'workstation_id') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN workstation_id TO poste_de_travail_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'unit_time') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN unit_time TO temps_unitaire'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'rank') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN \"rank\" TO rang'; END IF; END $$;");

        // Recreate previous hashed FK name for part.supplier_id if needed (best-effort)
        $this->addSql('ALTER TABLE part ADD CONSTRAINT IF NOT EXISTS fk_44ca0b232add6d8c FOREIGN KEY (supplier_id) REFERENCES supplier (id) NOT DEFERRABLE');
    }
}
