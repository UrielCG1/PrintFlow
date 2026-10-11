<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega trazabilidad de Impresión: requisitos, escaneos, consumo real y salidas identificadas por etiqueta QR.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_operation_plans
                ADD material_variant_id INT DEFAULT NULL,
                ADD material_snapshot JSON DEFAULT NULL,
                ADD INDEX idx_service_order_plan_material_active (material_variant_id, is_active),
                ADD CONSTRAINT fk_service_order_plan_material_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                MODIFY planned_quantity NUMERIC(20,6) DEFAULT NULL,
                MODIFY posted_quantity NUMERIC(20,6) DEFAULT NULL,
                ADD operation_plan_id INT DEFAULT NULL,
                ADD operation_execution_id INT DEFAULT NULL,
                ADD material_snapshot JSON DEFAULT NULL,
                ADD INDEX idx_usage_plan_execution (operation_plan_id, operation_execution_id),
                ADD CONSTRAINT fk_usage_operation_plan FOREIGN KEY (operation_plan_id) REFERENCES service_order_operation_plans (id) ON DELETE RESTRICT,
                ADD CONSTRAINT fk_usage_operation_execution FOREIGN KEY (operation_execution_id) REFERENCES production_operation_executions (id) ON DELETE RESTRICT
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE production_printing_scans (
                id INT AUTO_INCREMENT NOT NULL,
                operation_plan_id INT NOT NULL,
                design_release_id INT NOT NULL,
                equipment_id INT NOT NULL,
                material_variant_id INT NOT NULL,
                operator_user_id INT NOT NULL,
                equipment_code VARCHAR(40) NOT NULL,
                material_barcode VARCHAR(80) NOT NULL,
                requirements_snapshot JSON NOT NULL,
                scan_token VARCHAR(64) NOT NULL,
                validated_at DATETIME NOT NULL,
                consumed_at DATETIME DEFAULT NULL,
                invalidated_at DATETIME DEFAULT NULL,
                current_marker INT UNSIGNED DEFAULT 1,
                UNIQUE INDEX uniq_printing_scan_plan_current (operation_plan_id, current_marker),
                UNIQUE INDEX uniq_printing_scan_token (scan_token),
                INDEX idx_printing_scan_operator_current (operator_user_id, current_marker),
                PRIMARY KEY(id),
                CONSTRAINT fk_printing_scan_plan FOREIGN KEY (operation_plan_id) REFERENCES service_order_operation_plans (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printing_scan_release FOREIGN KEY (design_release_id) REFERENCES service_order_item_design_releases (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printing_scan_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printing_scan_material FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printing_scan_operator FOREIGN KEY (operator_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE production_printed_outputs (
                id INT AUTO_INCREMENT NOT NULL,
                service_order_item_id INT NOT NULL,
                operation_execution_id INT NOT NULL,
                design_release_id INT NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                label_code VARCHAR(64) NOT NULL,
                label_ready_at DATETIME DEFAULT NULL,
                label_placed_at DATETIME DEFAULT NULL,
                label_placed_by_user_id INT DEFAULT NULL,
                current_marker INT UNSIGNED DEFAULT 1,
                superseded_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_printed_output_item_version (service_order_item_id, version_number),
                UNIQUE INDEX uniq_printed_output_item_current (service_order_item_id, current_marker),
                UNIQUE INDEX uniq_printed_output_label_code (label_code),
                INDEX idx_printed_output_execution (operation_execution_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_printed_output_item FOREIGN KEY (service_order_item_id) REFERENCES service_order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printed_output_execution FOREIGN KEY (operation_execution_id) REFERENCES production_operation_executions (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printed_output_release FOREIGN KEY (design_release_id) REFERENCES service_order_item_design_releases (id) ON DELETE RESTRICT,
                CONSTRAINT fk_printed_output_label_placed_by FOREIGN KEY (label_placed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_printed_output_version CHECK (version_number > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración solo puede revertirse sobre MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_printing_scans') > 0 || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_printed_outputs') > 0, 'No se puede revertir mientras existan escaneos o salidas impresas; su historia debe conservarse.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_material_usages WHERE operation_plan_id IS NOT NULL OR operation_execution_id IS NOT NULL') > 0, 'No se puede revertir mientras existan consumos ligados a Impresión.');

        $this->addSql('DROP TABLE production_printed_outputs');
        $this->addSql('DROP TABLE production_printing_scans');
        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                DROP FOREIGN KEY fk_usage_operation_plan,
                DROP FOREIGN KEY fk_usage_operation_execution,
                DROP INDEX idx_usage_plan_execution,
                DROP operation_plan_id,
                DROP operation_execution_id,
                DROP material_snapshot,
                MODIFY planned_quantity NUMERIC(20,6) NOT NULL,
                MODIFY posted_quantity NUMERIC(20,6) NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_operation_plans
                DROP FOREIGN KEY fk_service_order_plan_material_variant,
                DROP INDEX idx_service_order_plan_material_active,
                DROP material_variant_id,
                DROP material_snapshot
            SQL);
    }
}
