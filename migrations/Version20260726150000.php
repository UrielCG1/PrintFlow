<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea categorías y materiales operativos con permisos granulares y relaciones protegidas.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE material_categories (
                    id INT AUTO_INCREMENT NOT NULL,
                    code VARCHAR(40) NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    description TEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX uniq_material_categories_code (code),
                    UNIQUE INDEX uniq_material_categories_name (name),
                    INDEX idx_material_categories_active_name (is_active, name),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE materials (
                    id INT AUTO_INCREMENT NOT NULL,
                    category_id INT NOT NULL,
                    measurement_unit_id INT NOT NULL,
                    primary_supplier_id INT DEFAULT NULL,
                    code VARCHAR(80) NOT NULL,
                    name VARCHAR(160) NOT NULL,
                    description TEXT DEFAULT NULL,
                    reference_cost NUMERIC(12, 2) NOT NULL,
                    minimum_stock NUMERIC(12, 3) NOT NULL,
                    notes TEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX uniq_materials_code (code),
                    INDEX idx_materials_active_name (is_active, name),
                    INDEX idx_materials_category_active (category_id, is_active),
                    INDEX idx_materials_unit_active (measurement_unit_id, is_active),
                    INDEX idx_materials_supplier_active (primary_supplier_id, is_active),
                    PRIMARY KEY (id),
                    CONSTRAINT chk_materials_reference_cost CHECK (reference_cost >= 0),
                    CONSTRAINT chk_materials_minimum_stock CHECK (minimum_stock >= 0),
                    CONSTRAINT fk_materials_category
                        FOREIGN KEY (category_id)
                        REFERENCES material_categories (id)
                        ON DELETE RESTRICT,
                    CONSTRAINT fk_materials_measurement_unit
                        FOREIGN KEY (measurement_unit_id)
                        REFERENCES measurement_units (id)
                        ON DELETE RESTRICT,
                    CONSTRAINT fk_materials_primary_supplier
                        FOREIGN KEY (primary_supplier_id)
                        REFERENCES suppliers (id)
                        ON DELETE RESTRICT
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO permissions (
                    code,
                    module,
                    action,
                    name,
                    description,
                    is_system,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES
                    (
                        'material_categories.view',
                        'Materiales',
                        'categories_view',
                        'Consultar categorías de materiales',
                        'Permite consultar las categorías de materiales.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'material_categories.create',
                        'Materiales',
                        'categories_create',
                        'Crear categorías de materiales',
                        'Permite registrar categorías de materiales.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'material_categories.update',
                        'Materiales',
                        'categories_update',
                        'Editar categorías de materiales',
                        'Permite actualizar categorías de materiales.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'material_categories.toggle_status',
                        'Materiales',
                        'categories_toggle_status',
                        'Activar o desactivar categorías de materiales',
                        'Permite cambiar el estado de categorías de materiales.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'materials.view',
                        'Materiales',
                        'view',
                        'Consultar materiales',
                        'Permite consultar el catálogo operativo de materiales.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'materials.create',
                        'Materiales',
                        'create',
                        'Crear materiales',
                        'Permite registrar materiales operativos.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'materials.update',
                        'Materiales',
                        'update',
                        'Editar materiales',
                        'Permite actualizar materiales operativos.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'materials.toggle_status',
                        'Materiales',
                        'toggle_status',
                        'Activar o desactivar materiales',
                        'Permite cambiar el estado de materiales sin eliminarlos.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    )
                ON DUPLICATE KEY UPDATE
                    module = VALUES(module),
                    action = VALUES(action),
                    name = VALUES(name),
                    description = VALUES(description),
                    is_system = VALUES(is_system),
                    is_active = VALUES(is_active),
                    updated_at = UTC_TIMESTAMP()
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT legacy_assignment.role_id, current_permission.id
                FROM role_permissions AS legacy_assignment
                INNER JOIN permissions AS legacy_permission
                    ON legacy_permission.id = legacy_assignment.permission_id
                INNER JOIN permissions AS current_permission
                    ON current_permission.code = 'materials.view'
                WHERE legacy_permission.code = 'material.view'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permission
                FROM role_permissions AS role_permission
                INNER JOIN permissions AS permission
                    ON permission.id = role_permission.permission_id
                WHERE permission.code = 'material.view'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code = 'material.view'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT legacy_assignment.role_id, current_permission.id
                FROM role_permissions AS legacy_assignment
                INNER JOIN permissions AS legacy_permission
                    ON legacy_permission.id = legacy_assignment.permission_id
                INNER JOIN permissions AS current_permission
                    ON current_permission.code IN (
                        'material_categories.view',
                        'material_categories.create',
                        'material_categories.update',
                        'material_categories.toggle_status',
                        'materials.view',
                        'materials.create',
                        'materials.update',
                        'materials.toggle_status'
                    )
                WHERE legacy_permission.code = 'material.manage'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permission
                FROM role_permissions AS role_permission
                INNER JOIN permissions AS permission
                    ON permission.id = role_permission.permission_id
                WHERE permission.code = 'material.manage'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code = 'material.manage'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT roles.id, permissions.id
                FROM roles
                INNER JOIN permissions
                    ON permissions.code IN (
                        'material_categories.view',
                        'material_categories.create',
                        'material_categories.update',
                        'material_categories.toggle_status',
                        'materials.view',
                        'materials.create',
                        'materials.update',
                        'materials.toggle_status'
                    )
                WHERE roles.code = 'ROLE_ADMIN'
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                UPDATE permissions
                SET
                    code = 'material.view',
                    module = 'materials',
                    action = 'view',
                    name = 'Consultar materiales',
                    description = 'Permite consultar materiales.'
                WHERE code = 'materials.view'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO permissions (
                    code,
                    module,
                    action,
                    name,
                    description,
                    is_system,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    'material.manage',
                    'materials',
                    'manage',
                    'Administrar materiales',
                    'Permite administrar materiales.',
                    1,
                    1,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                )
                ON DUPLICATE KEY UPDATE
                    module = VALUES(module),
                    action = VALUES(action),
                    name = VALUES(name),
                    description = VALUES(description),
                    is_system = VALUES(is_system),
                    is_active = VALUES(is_active),
                    updated_at = UTC_TIMESTAMP()
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT DISTINCT granular_assignment.role_id, legacy_permission.id
                FROM role_permissions AS granular_assignment
                INNER JOIN permissions AS granular_permission
                    ON granular_permission.id = granular_assignment.permission_id
                INNER JOIN permissions AS legacy_permission
                    ON legacy_permission.code = 'material.manage'
                WHERE granular_permission.code IN (
                    'material_categories.view',
                    'material_categories.create',
                    'material_categories.update',
                    'material_categories.toggle_status',
                    'materials.create',
                    'materials.update',
                    'materials.toggle_status'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permission
                FROM role_permissions AS role_permission
                INNER JOIN permissions AS permission
                    ON permission.id = role_permission.permission_id
                WHERE permission.code IN (
                    'material_categories.view',
                    'material_categories.create',
                    'material_categories.update',
                    'material_categories.toggle_status',
                    'materials.create',
                    'materials.update',
                    'materials.toggle_status'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code IN (
                    'material_categories.view',
                    'material_categories.create',
                    'material_categories.update',
                    'material_categories.toggle_status',
                    'materials.create',
                    'materials.update',
                    'materials.toggle_status'
                )
                SQL,
        );

        $this->addSql('DROP TABLE materials');
        $this->addSql('DROP TABLE material_categories');
    }
}