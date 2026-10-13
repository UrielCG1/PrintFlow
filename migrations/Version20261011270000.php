<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registra conteos cíclicos por unidad QR y habilita su captura para Producción.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql('ALTER TABLE inventory_stock_units ADD inventory_version INT NOT NULL DEFAULT 1 AFTER remaining_quantity');
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_cycle_counts (
                id INT AUTO_INCREMENT NOT NULL,
                stock_unit_id INT NOT NULL,
                material_variant_id INT NOT NULL,
                requested_by_user_id INT NOT NULL,
                reviewed_by_user_id INT DEFAULT NULL,
                inventory_movement_id INT DEFAULT NULL,
                idempotency_key VARCHAR(100) NOT NULL,
                command_fingerprint VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                expected_quantity NUMERIC(20, 6) NOT NULL,
                stock_unit_version INT NOT NULL,
                counted_quantity NUMERIC(20, 6) NOT NULL,
                difference_quantity NUMERIC(20, 6) NOT NULL,
                note VARCHAR(500) DEFAULT NULL,
                review_note VARCHAR(500) DEFAULT NULL,
                counted_at DATETIME NOT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_inventory_cycle_count_idempotency (idempotency_key),
                UNIQUE INDEX uniq_inventory_cycle_count_movement (inventory_movement_id),
                INDEX idx_inventory_cycle_count_status_counted (status, counted_at),
                INDEX idx_inventory_cycle_count_stock_status (stock_unit_id, status),
                INDEX idx_inventory_cycle_count_variant (material_variant_id),
                INDEX idx_inventory_cycle_count_requested_by (requested_by_user_id),
                INDEX idx_inventory_cycle_count_reviewed_by (reviewed_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_inventory_cycle_count_stock_unit FOREIGN KEY (stock_unit_id) REFERENCES inventory_stock_units (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_cycle_count_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_cycle_count_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_cycle_count_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_inventory_cycle_count_movement FOREIGN KEY (inventory_movement_id) REFERENCES inventory_movements (id) ON DELETE RESTRICT,
                CONSTRAINT chk_inventory_cycle_count_expected CHECK (expected_quantity >= 0),
                CONSTRAINT chk_inventory_cycle_count_counted CHECK (counted_quantity >= 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('inventory.count', 'inventory', 'count', 'Registrar conteos cíclicos', 'Permite escanear unidades QR y enviar sus conteos físicos a revisión.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code = 'inventory.count'
            WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_cycle_counts') > 0, 'No se puede revertir mientras existan conteos cíclicos registrados.');
        $this->addSql('DROP TABLE inventory_cycle_counts');
        $this->addSql('ALTER TABLE inventory_stock_units DROP inventory_version');
        $this->addSql("DELETE role_permissions FROM role_permissions INNER JOIN permissions ON permissions.id = role_permissions.permission_id WHERE permissions.code = 'inventory.count'");
        $this->addSql("DELETE FROM permissions WHERE code = 'inventory.count'");
    }
}
