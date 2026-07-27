<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el catálogo de equipos, agrega Laminado a Acabados y registra permisos de administración de equipos.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                UPDATE operations AS operation_item
                INNER JOIN operation_areas AS operation_area
                    ON operation_area.id = operation_item.operation_area_id
                SET operation_item.display_order = 30,
                    operation_item.updated_at = UTC_TIMESTAMP()
                WHERE operation_area.code = 'FINISH'
                  AND operation_item.code = 'ACA-ENROLLADO'
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
                SELECT
                    operation_area.id,
                    'ACA-LAMINADO',
                    'Laminado',
                    'Aplicación de laminado como acabado del material.',
                    20,
                    1,
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                FROM operation_areas AS operation_area
                WHERE operation_area.code = 'FINISH'
                  AND NOT EXISTS (
                    SELECT 1
                    FROM operations AS existing_operation
                    WHERE existing_operation.code = 'ACA-LAMINADO'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE equipment (
                    id INT AUTO_INCREMENT NOT NULL,
                    primary_operation_id INT NOT NULL,
                    code VARCHAR(40) NOT NULL,
                    name VARCHAR(160) NOT NULL,
                    technology VARCHAR(100) DEFAULT NULL,
                    brand VARCHAR(100) DEFAULT NULL,
                    model VARCHAR(100) DEFAULT NULL,
                    serial_number VARCHAR(100) DEFAULT NULL,
                    usable_width_cm NUMERIC(8, 2) DEFAULT NULL,
                    technical_capacity VARCHAR(120) DEFAULT NULL,
                    color_configuration VARCHAR(100) DEFAULT NULL,
                    observations LONGTEXT DEFAULT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'available',
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX uniq_equipment_code (code),
                    UNIQUE INDEX uniq_equipment_serial_number (serial_number),
                    INDEX idx_equipment_operation_status (primary_operation_id, status),
                    INDEX idx_equipment_status_name (status, name),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_equipment_primary_operation
                        FOREIGN KEY (primary_operation_id) REFERENCES operations (id) ON DELETE RESTRICT,
                    CONSTRAINT chk_equipment_status
                        CHECK (status IN ('available', 'maintenance', 'inactive'))
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO equipment (
                    primary_operation_id,
                    code,
                    name,
                    technology,
                    brand,
                    model,
                    serial_number,
                    usable_width_cm,
                    technical_capacity,
                    color_configuration,
                    observations,
                    status,
                    created_at,
                    updated_at
                )
                SELECT
                    operation_item.id,
                    seed.code,
                    seed.name,
                    seed.technology,
                    seed.brand,
                    seed.model,
                    NULL,
                    seed.usable_width_cm,
                    seed.technical_capacity,
                    seed.color_configuration,
                    NULL,
                    'available',
                    UTC_TIMESTAMP(),
                    UTC_TIMESTAMP()
                FROM (
                    SELECT
                        'IMP-IMPRESION' AS operation_code,
                        'EQ-IMP-SKY-COLOR' AS code,
                        'Plotter Sky Color' AS name,
                        'Ecosolvente' AS technology,
                        NULL AS brand,
                        NULL AS model,
                        160.00 AS usable_width_cm,
                        '15 m²/h' AS technical_capacity,
                        '4 colores' AS color_configuration
                    UNION ALL SELECT
                        'IMP-IMPRESION',
                        'EQ-IMP-HP-365',
                        'Plotter HP 365',
                        'Látex',
                        'HP',
                        '365',
                        160.00,
                        '23 m²/h',
                        '6 colores'
                    UNION ALL SELECT
                        'ACA-CORTE',
                        'EQ-ACA-MIMAKI-GC-SRIII',
                        'Plotter Mimaki GC SRIII',
                        'Corte vinil',
                        'Mimaki',
                        'GC SRIII',
                        61.00,
                        '50 cm/s',
                        '1 color'
                    UNION ALL SELECT
                        'ACA-CORTE',
                        'EQ-ACA-PRIME-XL',
                        'Plotter Prime XL',
                        'Corte vinil',
                        'Prime',
                        'XL',
                        160.00,
                        '70 cm/s',
                        '1 color'
                    UNION ALL SELECT
                        'ACA-LAMINADO',
                        'EQ-ACA-LAMINADORA-DXL',
                        'Laminadora DXL',
                        'Laminado',
                        NULL,
                        NULL,
                        160.00,
                        '100 lm/h',
                        '1 color'
                ) AS seed
                INNER JOIN operations AS operation_item
                    ON operation_item.code = seed.operation_code
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM equipment AS existing_equipment
                    WHERE existing_equipment.code = seed.code
                )
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
                    ('equipment.view', 'Equipos', 'view', 'Consultar equipos', 'Permite consultar el catálogo técnico de equipos.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('equipment.create', 'Equipos', 'create', 'Crear equipos', 'Permite registrar equipos operativos.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('equipment.update', 'Equipos', 'update', 'Editar equipos', 'Permite actualizar la ficha técnica y operación primaria de los equipos.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                    ('equipment.change_status', 'Equipos', 'change_status', 'Cambiar estado de equipos', 'Permite marcar equipos como disponibles, en mantenimiento o inactivos sin eliminar su historial.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
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
                        'equipment.view',
                        'equipment.create',
                        'equipment.update',
                        'equipment.change_status'
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
                    ON permissions.code = 'equipment.view'
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

        $this->addSql('DROP TABLE equipment');

        $this->addSql(
            <<<'SQL'
                DELETE FROM operations
                WHERE code = 'ACA-LAMINADO'
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions
                    ON permissions.id = role_permissions.permission_id
                WHERE permissions.code IN (
                    'equipment.view',
                    'equipment.create',
                    'equipment.update',
                    'equipment.change_status'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code IN (
                    'equipment.view',
                    'equipment.create',
                    'equipment.update',
                    'equipment.change_status'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                UPDATE operations AS operation_item
                INNER JOIN operation_areas AS operation_area
                    ON operation_area.id = operation_item.operation_area_id
                SET operation_item.display_order = 20,
                    operation_item.updated_at = UTC_TIMESTAMP()
                WHERE operation_area.code = 'FINISH'
                  AND operation_item.code = 'ACA-ENROLLADO'
                SQL,
        );
    }
}