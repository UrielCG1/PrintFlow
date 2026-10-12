<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registra recepciones físicas idempotentes y sus partidas vinculadas a QR y movimientos de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración requiere MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_receipts (
                id INT AUTO_INCREMENT NOT NULL,
                received_by_user_id INT NOT NULL,
                receipt_code VARCHAR(40) NOT NULL,
                idempotency_key VARCHAR(100) NOT NULL,
                command_fingerprint VARCHAR(64) NOT NULL,
                reference VARCHAR(100) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                is_provisional TINYINT(1) NOT NULL DEFAULT 0,
                received_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_inventory_receipt_code (receipt_code),
                UNIQUE INDEX uniq_inventory_receipt_idempotency (idempotency_key),
                INDEX idx_inventory_receipt_received_at (received_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_inventory_receipt_received_by FOREIGN KEY (received_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_receipt_lines (
                id INT AUTO_INCREMENT NOT NULL,
                receipt_id INT NOT NULL,
                material_variant_id INT NOT NULL,
                lot_id INT DEFAULT NULL,
                stock_unit_id INT NOT NULL,
                unit_id INT NOT NULL,
                inventory_movement_id INT DEFAULT NULL,
                line_number INT NOT NULL,
                received_quantity NUMERIC(20, 6) NOT NULL,
                unit_cost_mxn NUMERIC(19, 6) DEFAULT NULL,
                UNIQUE INDEX uniq_inventory_receipt_line_number (receipt_id, line_number),
                UNIQUE INDEX uniq_inventory_receipt_line_stock_unit (stock_unit_id),
                UNIQUE INDEX uniq_inventory_receipt_line_movement (inventory_movement_id),
                INDEX idx_inventory_receipt_line_variant (material_variant_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_inventory_receipt_line_receipt FOREIGN KEY (receipt_id) REFERENCES inventory_receipts (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_receipt_line_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_receipt_line_lot FOREIGN KEY (lot_id) REFERENCES inventory_lots (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_receipt_line_stock_unit FOREIGN KEY (stock_unit_id) REFERENCES inventory_stock_units (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_receipt_line_unit FOREIGN KEY (unit_id) REFERENCES measurement_units (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_receipt_line_movement FOREIGN KEY (inventory_movement_id) REFERENCES inventory_movements (id) ON DELETE RESTRICT,
                CONSTRAINT chk_inventory_receipt_line_number CHECK (line_number > 0),
                CONSTRAINT chk_inventory_receipt_line_quantity CHECK (received_quantity > 0),
                CONSTRAINT chk_inventory_receipt_line_cost CHECK (unit_cost_mxn IS NULL OR unit_cost_mxn >= 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración requiere MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_receipt_lines') > 0,
            'No se puede revertir mientras existan partidas de recepción; su trazabilidad debe conservarse.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_receipts') > 0,
            'No se puede revertir mientras existan recepciones de inventario.',
        );

        $this->addSql('DROP TABLE inventory_receipt_lines');
        $this->addSql('DROP TABLE inventory_receipts');
    }
}
