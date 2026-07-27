<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea los catálogos de áreas operativas y operaciones con orden sugerido, estado y permisos granulares.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE operation_areas (
                    id INT AUTO_INCREMENT NOT NULL,
                    code VARCHAR(40) NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    description LONGTEXT DEFAULT NULL,
                    display_order INT UNSIGNED NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX uniq_operation_areas_code (code),
                    UNIQUE INDEX uniq_operation_areas_name (name),
                    INDEX idx_operation_areas_active_order (is_active, display_order, name),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE operations (
                    id INT AUTO_INCREMENT NOT NULL,
                    operation_area_id INT NOT NULL,
                    code VARCHAR(40) NOT NULL,
                    name VARCHAR(120) NOT NULL,
                    description LONGTEXT DEFAULT NULL,
                    display_order INT UNSIGNED NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX uniq_operations_code (code),
                    UNIQUE INDEX uniq_operations_area_name (operation_area_id, name),
                    INDEX idx_operations_area_active_order (operation_area_id, is_active, display_order, name),
                    INDEX idx_operations_active_name (is_active, name),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_operations_area FOREIGN KEY (operation_area_id) REFERENCES operation_areas (id) ON DELETE RESTRICT
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO operation_areas (
                    code,
                    name,
                    description,
                    display_order,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES
                    ('PREPRESS', 'Preprensa', NULL, 10, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('PRINT', 'Impresión', NULL, 20, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('FINISH', 'Acabados', NULL, 30, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('POSTPROD', 'Posproducción', NULL, 40, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO operations (
                    operation_area_id,
                    code,
                    name,
                    description,
                    display_order,
                    is_active,
                    created_at,
                    updated_at
                )
                SELECT operation_area.id, seed.code, seed.name, NULL, seed.display_order, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                FROM (
                    SELECT 'PREPRESS' AS area_code, 'PRE-DISENO' AS code, 'Diseño' AS name, 10 AS display_order
                    UNION ALL SELECT 'PRINT', 'IMP-IMPRESION', 'Impresión', 10
                    UNION ALL SELECT 'FINISH', 'ACA-CORTE', 'Corte', 10
                    UNION ALL SELECT 'FINISH', 'ACA-ENROLLADO', 'Enrollado', 20
                    UNION ALL SELECT 'POSTPROD', 'POS-ENTREGA', 'Entrega', 10
                ) AS seed
                INNER JOIN operation_areas AS operation_area
                    ON operation_area.code = seed.area_code
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
                    ('operation_areas.view', 'Operaciones', 'areas_view', 'Consultar áreas operativas', 'Permite consultar el catálogo de áreas operativas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operation_areas.create', 'Operaciones', 'areas_create', 'Crear áreas operativas', 'Permite registrar áreas operativas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operation_areas.update', 'Operaciones', 'areas_update', 'Editar áreas operativas', 'Permite actualizar áreas operativas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operation_areas.toggle_status', 'Operaciones', 'areas_toggle_status', 'Activar o desactivar áreas operativas', 'Permite cambiar el estado de áreas operativas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operation_areas.reorder', 'Operaciones', 'areas_reorder', 'Reordenar áreas operativas', 'Permite modificar el orden sugerido global de las áreas operativas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operations.view', 'Operaciones', 'view', 'Consultar operaciones', 'Permite consultar el catálogo de operaciones.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operations.create', 'Operaciones', 'create', 'Crear operaciones', 'Permite registrar operaciones.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operations.update', 'Operaciones', 'update', 'Editar operaciones', 'Permite actualizar operaciones y su área operativa.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operations.toggle_status', 'Operaciones', 'toggle_status', 'Activar o desactivar operaciones', 'Permite cambiar el estado de operaciones sin eliminar su historial.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('operations.reorder', 'Operaciones', 'reorder', 'Reordenar operaciones', 'Permite modificar el orden sugerido de las operaciones dentro de un área.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
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
                SELECT roles.id, permissions.id
                FROM roles
                INNER JOIN permissions
                    ON permissions.code IN (
                        'operation_areas.view',
                        'operation_areas.create',
                        'operation_areas.update',
                        'operation_areas.toggle_status',
                        'operation_areas.reorder',
                        'operations.view',
                        'operations.create',
                        'operations.update',
                        'operations.toggle_status',
                        'operations.reorder'
                    )
                WHERE roles.code = 'ROLE_ADMIN'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT roles.id, permissions.id
                FROM roles
                INNER JOIN permissions
                    ON permissions.code IN ('operation_areas.view', 'operations.view')
                WHERE roles.code = 'ROLE_PRODUCTION'
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
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions
                    ON permissions.id = role_permissions.permission_id
                WHERE permissions.code IN (
                    'operation_areas.view',
                    'operation_areas.create',
                    'operation_areas.update',
                    'operation_areas.toggle_status',
                    'operation_areas.reorder',
                    'operations.view',
                    'operations.create',
                    'operations.update',
                    'operations.toggle_status',
                    'operations.reorder'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code IN (
                    'operation_areas.view',
                    'operation_areas.create',
                    'operation_areas.update',
                    'operation_areas.toggle_status',
                    'operation_areas.reorder',
                    'operations.view',
                    'operations.create',
                    'operations.update',
                    'operations.toggle_status',
                    'operations.reorder'
                )
                SQL,
        );

        $this->addSql('DROP TABLE operations');
        $this->addSql('DROP TABLE operation_areas');
    }
}