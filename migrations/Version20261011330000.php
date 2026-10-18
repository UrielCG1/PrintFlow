<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011330000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserva cantidades físicas lineales previstas y reales de rollos sin reinterpretar consumos históricos por área.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                ADD planned_inventory_quantity NUMERIC(20, 6) DEFAULT NULL AFTER planned_quantity,
                ADD actual_inventory_quantity NUMERIC(20, 6) DEFAULT NULL AFTER actual_quantity,
                ADD CONSTRAINT chk_usage_planned_inventory_quantity CHECK (planned_inventory_quantity IS NULL OR planned_inventory_quantity > 0),
                ADD CONSTRAINT chk_usage_actual_inventory_quantity CHECK (actual_inventory_quantity IS NULL OR actual_inventory_quantity > 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_material_usages WHERE planned_inventory_quantity IS NOT NULL OR actual_inventory_quantity IS NOT NULL') > 0, 'No se puede revertir mientras existan longitudes físicas registradas para rollos.');

        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                DROP CHECK chk_usage_planned_inventory_quantity,
                DROP CHECK chk_usage_actual_inventory_quantity,
                DROP COLUMN actual_inventory_quantity,
                DROP COLUMN planned_inventory_quantity
            SQL);
    }
}
