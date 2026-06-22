<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align legacy table names to current entity names (piece->part, gamme->routing, nomenclature->bill_of_materials, poste_de_travail->workstation, prevision->forecast, realisation->completion)';
    }

    public function up(Schema $schema): void
    {
        // Rename tables (if they exist)
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'piece') THEN EXECUTE 'ALTER TABLE piece RENAME TO part'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'gamme') THEN EXECUTE 'ALTER TABLE gamme RENAME TO routing'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'nomenclature') THEN EXECUTE 'ALTER TABLE nomenclature RENAME TO bill_of_materials'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'poste_de_travail') THEN EXECUTE 'ALTER TABLE poste_de_travail RENAME TO workstation'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'prevision') THEN EXECUTE 'ALTER TABLE prevision RENAME TO forecast'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'realisation') THEN EXECUTE 'ALTER TABLE realisation RENAME TO completion'; END IF; END $$;");

        // Rename columns on renamed tables (if present)
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'routing' AND column_name = 'piece_id') THEN EXECUTE 'ALTER TABLE routing RENAME COLUMN piece_id TO part_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'gamme_id') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN gamme_id TO routing_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'bill_of_materials' AND column_name = 'parent_piece_id') THEN EXECUTE 'ALTER TABLE bill_of_materials RENAME COLUMN parent_piece_id TO parent_part_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'bill_of_materials' AND column_name = 'child_piece_id') THEN EXECUTE 'ALTER TABLE bill_of_materials RENAME COLUMN child_piece_id TO child_part_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'machine' AND column_name = 'poste_de_travail_id') THEN EXECUTE 'ALTER TABLE machine RENAME COLUMN poste_de_travail_id TO workstation_id'; END IF; END $$;");

        // Drop old foreign keys if they still exist (attempt several known names), then add new FKs to match entities
        // routing.part_id -> part.id
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'routing') THEN ALTER TABLE routing DROP CONSTRAINT IF EXISTS FK_C32E1468C40FCFA8; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_routing_part') THEN EXECUTE 'ALTER TABLE routing ADD CONSTRAINT fk_routing_part FOREIGN KEY (part_id) REFERENCES part (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // machine.workstation_id -> workstation.id
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'machine') THEN ALTER TABLE machine DROP CONSTRAINT IF EXISTS FK_1505DF8417A47EE6; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_machine_workstation') THEN EXECUTE 'ALTER TABLE machine ADD CONSTRAINT fk_machine_workstation FOREIGN KEY (workstation_id) REFERENCES workstation (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // bill_of_materials parent/child -> part
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'bill_of_materials') THEN ALTER TABLE bill_of_materials DROP CONSTRAINT IF EXISTS FK_799A36525BC26379; ALTER TABLE bill_of_materials DROP CONSTRAINT IF EXISTS FK_799A36524E08B20C; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_bom_parent_part') THEN EXECUTE 'ALTER TABLE bill_of_materials ADD CONSTRAINT fk_bom_parent_part FOREIGN KEY (parent_part_id) REFERENCES part (id) NOT DEFERRABLE'; END IF; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_bom_child_part') THEN EXECUTE 'ALTER TABLE bill_of_materials ADD CONSTRAINT fk_bom_child_part FOREIGN KEY (child_part_id) REFERENCES part (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // operation.routing_id -> routing.id
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'operation') THEN ALTER TABLE operation DROP CONSTRAINT IF EXISTS FK_1981A66DD2FD85F1; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_operation_routing') THEN EXECUTE 'ALTER TABLE operation ADD CONSTRAINT fk_operation_routing FOREIGN KEY (routing_id) REFERENCES routing (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // forecast.operation_id and completion.operation_id -> operation.id
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'forecast') THEN ALTER TABLE forecast DROP CONSTRAINT IF EXISTS FK_1EEB1DDE44AC3583; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_forecast_operation') THEN EXECUTE 'ALTER TABLE forecast ADD CONSTRAINT fk_forecast_operation FOREIGN KEY (operation_id) REFERENCES operation (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'completion') THEN ALTER TABLE completion DROP CONSTRAINT IF EXISTS FK_EAA5610E44AC3583; IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_completion_operation') THEN EXECUTE 'ALTER TABLE completion ADD CONSTRAINT fk_completion_operation FOREIGN KEY (operation_id) REFERENCES operation (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // part.supplier_id -> supplier.id (ensure constraint exists)
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'part') THEN ALTER TABLE part DROP CONSTRAINT IF EXISTS FK_44CA0B23670C757F; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'part') THEN IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_part_supplier') THEN EXECUTE 'ALTER TABLE part ADD CONSTRAINT fk_part_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id) NOT DEFERRABLE'; END IF; END IF; END \$\$;");

        // Ensure unique/index names for references on renamed tables (only if tables exist)
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'part') THEN IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relkind = 'i' AND relname = 'uniq_part_reference') THEN EXECUTE 'CREATE UNIQUE INDEX uniq_part_reference ON part (reference)'; END IF; END IF; END \$\$;");
        $this->addSql("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'routing') THEN IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relkind = 'i' AND relname = 'uniq_routing_reference') THEN EXECUTE 'CREATE UNIQUE INDEX uniq_routing_reference ON routing (reference)'; END IF; END IF; END \$\$;");
    }

    public function down(Schema $schema): void
    {
        // Reverse indexes/constraints added above
        $this->addSql('ALTER TABLE routing DROP CONSTRAINT IF EXISTS fk_routing_part');
        $this->addSql('ALTER TABLE machine DROP CONSTRAINT IF EXISTS fk_machine_workstation');
        $this->addSql('ALTER TABLE bill_of_materials DROP CONSTRAINT IF EXISTS fk_bom_parent_part');
        $this->addSql('ALTER TABLE bill_of_materials DROP CONSTRAINT IF EXISTS fk_bom_child_part');
        $this->addSql('ALTER TABLE operation DROP CONSTRAINT IF EXISTS fk_operation_routing');
        $this->addSql('ALTER TABLE forecast DROP CONSTRAINT IF EXISTS fk_forecast_operation');
        $this->addSql('ALTER TABLE completion DROP CONSTRAINT IF EXISTS fk_completion_operation');
        $this->addSql('ALTER TABLE part DROP CONSTRAINT IF EXISTS fk_part_supplier');

        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relkind = 'i' AND relname = 'uniq_part_reference') THEN EXECUTE 'DROP INDEX IF EXISTS uniq_part_reference'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_class WHERE relkind = 'i' AND relname = 'uniq_routing_reference') THEN EXECUTE 'DROP INDEX IF EXISTS uniq_routing_reference'; END IF; END $$;");

        // Rename columns back if exist
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'routing' AND column_name = 'part_id') THEN EXECUTE 'ALTER TABLE routing RENAME COLUMN part_id TO piece_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'operation' AND column_name = 'routing_id') THEN EXECUTE 'ALTER TABLE operation RENAME COLUMN routing_id TO gamme_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'bill_of_materials' AND column_name = 'parent_part_id') THEN EXECUTE 'ALTER TABLE bill_of_materials RENAME COLUMN parent_part_id TO parent_piece_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'bill_of_materials' AND column_name = 'child_part_id') THEN EXECUTE 'ALTER TABLE bill_of_materials RENAME COLUMN child_part_id TO child_piece_id'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'machine' AND column_name = 'workstation_id') THEN EXECUTE 'ALTER TABLE machine RENAME COLUMN workstation_id TO poste_de_travail_id'; END IF; END $$;");

        // Rename tables back
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'part') THEN EXECUTE 'ALTER TABLE part RENAME TO piece'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'routing') THEN EXECUTE 'ALTER TABLE routing RENAME TO gamme'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'bill_of_materials') THEN EXECUTE 'ALTER TABLE bill_of_materials RENAME TO nomenclature'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'workstation') THEN EXECUTE 'ALTER TABLE workstation RENAME TO poste_de_travail'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'forecast') THEN EXECUTE 'ALTER TABLE forecast RENAME TO prevision'; END IF; END $$;");
        $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'completion') THEN EXECUTE 'ALTER TABLE completion RENAME TO realisation'; END IF; END $$;");
    }
}
