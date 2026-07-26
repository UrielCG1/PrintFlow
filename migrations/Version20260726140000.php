<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el catálogo operativo de proveedores y sus permisos administrativos.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE suppliers (
                    id INT AUTO_INCREMENT NOT NULL,
                    code VARCHAR(80) NOT NULL,
                    business_name VARCHAR(160) NOT NULL,
                    legal_name VARCHAR(160) DEFAULT NULL,
                    tax_id VARCHAR(20) DEFAULT NULL,
                    email VARCHAR(180) DEFAULT NULL,
                    phone VARCHAR(40) DEFAULT NULL,
                    notes LONGTEXT DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE INDEX uniq_suppliers_code (code),
                    UNIQUE INDEX uniq_suppliers_tax_id (tax_id),
                    INDEX idx_suppliers_active_name (is_active, business_name),
                    INDEX idx_suppliers_deleted_at (deleted_at),
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                UPDATE permissions
                SET code = CASE code
                    WHEN 'supplier.view' THEN 'suppliers.view'
                    WHEN 'supplier.create' THEN 'suppliers.create'
                    WHEN 'supplier.update' THEN 'suppliers.update'
                    WHEN 'supplier.delete' THEN 'suppliers.toggle_status'
                END
                WHERE code IN (
                    'supplier.view',
                    'supplier.create',
                    'supplier.update',
                    'supplier.delete'
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
                    (
                        'suppliers.view',
                        'Proveedores',
                        'view',
                        'Consultar proveedores',
                        'Permite consultar el catálogo operativo de proveedores.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'suppliers.create',
                        'Proveedores',
                        'create',
                        'Crear proveedores',
                        'Permite registrar proveedores.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'suppliers.update',
                        'Proveedores',
                        'update',
                        'Editar proveedores',
                        'Permite actualizar la información de proveedores.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'suppliers.toggle_status',
                        'Proveedores',
                        'toggle_status',
                        'Activar o desactivar proveedores',
                        'Permite activar o desactivar proveedores sin borrarlos.',
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
                SELECT roles.id, permissions.id
                FROM roles
                INNER JOIN permissions
                    ON permissions.code IN (
                        'suppliers.view',
                        'suppliers.create',
                        'suppliers.update',
                        'suppliers.toggle_status'
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
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions
                    ON permissions.id = role_permissions.permission_id
                WHERE permissions.code IN (
                    'suppliers.view',
                    'suppliers.create',
                    'suppliers.update',
                    'suppliers.toggle_status'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code IN (
                    'suppliers.view',
                    'suppliers.create',
                    'suppliers.update',
                    'suppliers.toggle_status'
                )
                SQL,
        );

        $this->addSql('DROP TABLE suppliers');
    }
}