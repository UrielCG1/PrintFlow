<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el catálogo comercial, sus unidades de medida y reglas de precio por cantidad.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL.');

        $this->addSql("CREATE TABLE commercial_categories (
            id INT AUTO_INCREMENT NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL, display_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_commercial_categories_code (code), UNIQUE INDEX uniq_commercial_categories_name (name),
            INDEX idx_commercial_categories_active_order (is_active, display_order, name),
            CONSTRAINT chk_commercial_categories_display_order CHECK (display_order >= 0),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE measurement_units (
            id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) NOT NULL, name VARCHAR(80) NOT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_measurement_units_code (code), UNIQUE INDEX uniq_measurement_units_name (name),
            INDEX idx_measurement_units_active_order (is_active, display_order, name),
            CONSTRAINT chk_measurement_units_display_order CHECK (display_order >= 0),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE commercial_items (
            id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, measurement_unit_id INT NOT NULL,
            code VARCHAR(80) NOT NULL, type VARCHAR(20) NOT NULL, name VARCHAR(160) NOT NULL,
            description TEXT DEFAULT NULL, base_price NUMERIC(12, 2) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_commercial_items_code (code),
            INDEX idx_commercial_items_active_name (is_active, name),
            INDEX idx_commercial_items_category_active (category_id, is_active),
            INDEX idx_commercial_items_unit_active (measurement_unit_id, is_active),
            PRIMARY KEY(id),
            CONSTRAINT chk_commercial_items_type CHECK (type IN ('PRODUCT', 'SERVICE')),
            CONSTRAINT chk_commercial_items_base_price CHECK (base_price >= 0),
            CONSTRAINT fk_commercial_items_category FOREIGN KEY (category_id) REFERENCES commercial_categories (id) ON DELETE RESTRICT,
            CONSTRAINT fk_commercial_items_measurement_unit FOREIGN KEY (measurement_unit_id) REFERENCES measurement_units (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE item_price_rules (
            id INT AUTO_INCREMENT NOT NULL, commercial_item_id INT NOT NULL, rule_type VARCHAR(30) NOT NULL,
            min_quantity NUMERIC(14, 4) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_item_price_rules_threshold (commercial_item_id, rule_type, min_quantity),
            INDEX idx_item_price_rules_lookup (commercial_item_id, is_active, min_quantity),
            PRIMARY KEY(id),
            CONSTRAINT chk_item_price_rules_type CHECK (rule_type IN ('QUANTITY_TIER')),
            CONSTRAINT chk_item_price_rules_min_quantity CHECK (min_quantity > 0),
            CONSTRAINT chk_item_price_rules_unit_price CHECK (unit_price >= 0),
            CONSTRAINT fk_item_price_rules_item FOREIGN KEY (commercial_item_id) REFERENCES commercial_items (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at) VALUES
            ('catalog.view', 'Catálogo', 'view', 'Consultar catálogo comercial', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.categories.manage', 'Catálogo', 'categories_manage', 'Administrar categorías comerciales', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.units.manage', 'Catálogo', 'units_manage', 'Administrar unidades de medida', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.items.create', 'Catálogo', 'items_create', 'Crear conceptos comerciales', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.items.update', 'Catálogo', 'items_update', 'Editar conceptos comerciales', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.items.update_price', 'Catálogo', 'items_update_price', 'Modificar precios y rangos comerciales', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.items.toggle_status', 'Catálogo', 'items_toggle_status', 'Activar o desactivar conceptos comerciales', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system), is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()");

        $this->addSql("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT role.id, permission.id FROM roles AS role CROSS JOIN permissions AS permission
            WHERE role.code = 'ROLE_ADMIN' AND permission.code IN ('catalog.view', 'catalog.categories.manage', 'catalog.units.manage', 'catalog.items.create', 'catalog.items.update', 'catalog.items.update_price', 'catalog.items.toggle_status')");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL.');

        $this->addSql("DELETE role_permission FROM role_permissions AS role_permission INNER JOIN permissions AS permission ON permission.id = role_permission.permission_id WHERE permission.code LIKE 'catalog.%'");
        $this->addSql("DELETE FROM permissions WHERE code LIKE 'catalog.%'");
        $this->addSql('DROP TABLE item_price_rules');
        $this->addSql('DROP TABLE commercial_items');
        $this->addSql('DROP TABLE measurement_units');
        $this->addSql('DROP TABLE commercial_categories');
    }
}