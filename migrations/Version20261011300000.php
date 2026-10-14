<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserva las operaciones controladas de bloqueo, liberación y retiro de unidades QR en bodega.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_stock_unit_operations (
                id INT AUTO_INCREMENT NOT NULL,
                stock_unit_id INT NOT NULL,
                material_variant_id INT NOT NULL,
                responsible_user_id INT NOT NULL,
                inventory_movement_id INT DEFAULT NULL,
                operation_type VARCHAR(20) NOT NULL,
                reason VARCHAR(30) NOT NULL,
                quantity NUMERIC(20, 6) NOT NULL,
                previous_status VARCHAR(20) NOT NULL,
                resulting_status VARCHAR(20) NOT NULL,
                note VARCHAR(500) NOT NULL,
                reference VARCHAR(100) DEFAULT NULL,
                occurred_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_inventory_stock_operation_movement (inventory_movement_id),
                INDEX idx_inventory_stock_operation_unit_occurred (stock_unit_id, occurred_at),
                INDEX idx_inventory_stock_operation_variant_occurred (material_variant_id, occurred_at),
                INDEX idx_inventory_stock_operation_type_occurred (operation_type, occurred_at),
                INDEX idx_inventory_stock_operation_responsible (responsible_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_inventory_stock_operation_unit FOREIGN KEY (stock_unit_id) REFERENCES inventory_stock_units (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_stock_operation_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_stock_operation_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_stock_operation_movement FOREIGN KEY (inventory_movement_id) REFERENCES inventory_movements (id) ON DELETE RESTRICT,
                CONSTRAINT chk_inventory_stock_operation_quantity CHECK (quantity > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_stock_unit_operations') > 0, 'No se puede revertir mientras existan operaciones de bodega registradas.');

        $this->addSql('DROP TABLE inventory_stock_unit_operations');
    }
}
