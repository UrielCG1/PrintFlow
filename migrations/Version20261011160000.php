<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega unidades físicas con QR y saldos bloqueado/comprometido para el nuevo inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración requiere MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_stock_units (
                id INT AUTO_INCREMENT NOT NULL,
                material_variant_id INT NOT NULL,
                lot_id INT DEFAULT NULL,
                parent_stock_unit_id INT DEFAULT NULL,
                unit_code VARCHAR(40) NOT NULL,
                qr_token VARCHAR(64) NOT NULL,
                unit_kind VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                initial_quantity NUMERIC(20, 6) NOT NULL,
                remaining_quantity NUMERIC(20, 6) NOT NULL,
                physical_attributes JSON DEFAULT NULL,
                storage_location VARCHAR(100) DEFAULT NULL,
                received_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_inventory_stock_unit_code (unit_code),
                UNIQUE INDEX uniq_inventory_stock_unit_qr_token (qr_token),
                INDEX idx_inventory_stock_unit_variant_status (material_variant_id, status),
                INDEX idx_inventory_stock_unit_lot_status (lot_id, status),
                INDEX idx_inventory_stock_unit_parent (parent_stock_unit_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_inventory_stock_unit_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_stock_unit_lot FOREIGN KEY (lot_id) REFERENCES inventory_lots (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_stock_unit_parent FOREIGN KEY (parent_stock_unit_id) REFERENCES inventory_stock_units (id) ON DELETE RESTRICT,
                CONSTRAINT chk_inventory_stock_unit_kind CHECK (unit_kind IN ('RECEIVED', 'SURPLUS')),
                CONSTRAINT chk_inventory_stock_unit_status CHECK (status IN ('AVAILABLE', 'BLOCKED', 'EXHAUSTED', 'DISPOSED')),
                CONSTRAINT chk_inventory_stock_unit_quantities CHECK (initial_quantity > 0 AND remaining_quantity >= 0 AND remaining_quantity <= initial_quantity)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movements
                ADD stock_unit_id INT DEFAULT NULL,
                ADD INDEX idx_inventory_stock_unit (stock_unit_id, occurred_at),
                ADD CONSTRAINT fk_inventory_movement_stock_unit FOREIGN KEY (stock_unit_id) REFERENCES inventory_stock_units (id) ON DELETE RESTRICT
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE material_variant_costs
                ADD blocked_quantity NUMERIC(20, 6) NOT NULL DEFAULT 0,
                ADD committed_quantity NUMERIC(20, 6) NOT NULL DEFAULT 0,
                ADD CONSTRAINT chk_variant_costs_inventory_partition CHECK (blocked_quantity >= 0 AND committed_quantity >= 0 AND blocked_quantity + committed_quantity <= on_hand_quantity)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración requiere MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_stock_units') > 0,
            'No se puede revertir mientras existan unidades físicas identificadas.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE stock_unit_id IS NOT NULL') > 0,
            'No se puede revertir mientras existan movimientos ligados a unidades físicas.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM material_variant_costs WHERE blocked_quantity <> 0 OR committed_quantity <> 0') > 0,
            'No se puede revertir mientras existan saldos bloqueados o comprometidos.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_movements
                DROP FOREIGN KEY fk_inventory_movement_stock_unit,
                DROP INDEX idx_inventory_stock_unit,
                DROP stock_unit_id
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE material_variant_costs
                DROP CONSTRAINT chk_variant_costs_inventory_partition,
                DROP blocked_quantity,
                DROP committed_quantity
            SQL);
        $this->addSql('DROP TABLE inventory_stock_units');
    }
}
