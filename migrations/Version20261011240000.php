<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relaciona cada validación de impresión con la unidad física de inventario identificada por QR.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            ALTER TABLE production_printing_scans
                ADD stock_unit_id INT DEFAULT NULL AFTER material_variant_id,
                ADD INDEX idx_printing_scan_stock_unit (stock_unit_id),
                ADD CONSTRAINT fk_printing_scan_stock_unit FOREIGN KEY (stock_unit_id) REFERENCES inventory_stock_units (id) ON DELETE RESTRICT
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_printing_scans WHERE stock_unit_id IS NOT NULL') > 0, 'No se puede revertir mientras existan escaneos ligados a unidades físicas.');

        $this->addSql(<<<'SQL'
            ALTER TABLE production_printing_scans
                DROP FOREIGN KEY fk_printing_scan_stock_unit,
                DROP INDEX idx_printing_scan_stock_unit,
                DROP stock_unit_id
            SQL);
    }
}
