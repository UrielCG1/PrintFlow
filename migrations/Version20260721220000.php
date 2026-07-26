<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

final class Version20260721220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el módulo de clientes y asigna sus permisos a ROLE_ADMIN.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            'CREATE TABLE clients (
                id INT AUTO_INCREMENT NOT NULL,
                business_name VARCHAR(160) NOT NULL,
                tax_id VARCHAR(20) DEFAULT NULL,
                email VARCHAR(180) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX uniq_clients_tax_id (tax_id),
                INDEX idx_clients_active_name (is_active, business_name),
                INDEX idx_clients_deleted_at (deleted_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            "INSERT INTO permissions (
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
                ('clients.view', 'Clientes', 'view', 'Consultar clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.create', 'Clientes', 'create', 'Crear clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.update', 'Clientes', 'update', 'Editar clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.toggle_status', 'Clientes', 'toggle_status', 'Activar o desactivar clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                module = VALUES(module),
                action = VALUES(action),
                name = VALUES(name),
                description = VALUES(description),
                is_system = VALUES(is_system),
                is_active = VALUES(is_active),
                updated_at = UTC_TIMESTAMP()"
        );

        $this->addSql(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT role.id, permission.id
            FROM roles AS role
            CROSS JOIN permissions AS permission
            WHERE role.code = 'ROLE_ADMIN'
              AND permission.code IN (
                  'clients.view',
                  'clients.create',
                  'clients.update',
                  'clients.toggle_status'
              )"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            "DELETE role_permission
            FROM role_permissions AS role_permission
            INNER JOIN permissions AS permission
                ON permission.id = role_permission.permission_id
            WHERE permission.code IN (
                'clients.view',
                'clients.create',
                'clients.update',
                'clients.toggle_status'
            )"
        );

        $this->addSql(
            "DELETE FROM permissions
            WHERE code IN (
                'clients.view',
                'clients.create',
                'clients.update',
                'clients.toggle_status'
            )"
        );

        $this->addSql('DROP TABLE clients');
    }
}