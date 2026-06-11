<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename fournisseur table/column to supplier to match entity rename';
    }

    public function up(Schema $schema): void
    {
    // Rename table if it exists (keeps data)
    $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'fournisseur') THEN EXECUTE 'ALTER TABLE fournisseur RENAME TO supplier'; END IF; END $$;");

    // Rename column on piece table if it exists
    $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'piece' AND column_name = 'fournisseur_id') THEN EXECUTE 'ALTER TABLE piece RENAME COLUMN fournisseur_id TO supplier_id'; END IF; END $$;");

        // Drop old FK if it exists and add a new FK to supplier
        $this->addSql('ALTER TABLE piece DROP CONSTRAINT IF EXISTS fk_44ca0b23670c757f');
        $this->addSql('ALTER TABLE piece ADD CONSTRAINT FK_44CA0B232ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) NOT DEFERRABLE');

        // Recreate index on the renamed column
        $this->addSql('DROP INDEX IF EXISTS idx_44ca0b23670c757f');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_44CA0B232ADD6D8C ON piece (supplier_id)');
    }

    public function down(Schema $schema): void
    {
        // Reverse the renames
        $this->addSql('ALTER TABLE piece DROP CONSTRAINT IF EXISTS FK_44CA0B232ADD6D8C');
        $this->addSql('DROP INDEX IF EXISTS IDX_44CA0B232ADD6D8C');

    $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'piece' AND column_name = 'supplier_id') THEN EXECUTE 'ALTER TABLE piece RENAME COLUMN supplier_id TO fournisseur_id'; END IF; END $$;");
    $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'supplier') THEN EXECUTE 'ALTER TABLE supplier RENAME TO fournisseur'; END IF; END $$;");

        // Recreate previous FK and index names if needed
        $this->addSql('ALTER TABLE piece ADD CONSTRAINT FK_44CA0B23670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_44CA0B23670C757F ON piece (fournisseur_id)');
    }
}
