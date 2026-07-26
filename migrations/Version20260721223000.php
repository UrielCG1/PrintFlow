<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea contactos de cliente y sus permisos administrativos.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            'CREATE TABLE client_contacts (
                id INT AUTO_INCREMENT NOT NULL,
                client_id INT NOT NULL,
                full_name VARCHAR(160) NOT NULL,
                job_title VARCHAR(120) DEFAULT NULL,
                email VARCHAR(180) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_client_contacts_client_active (client_id, is_active),
                INDEX idx_client_contacts_client_primary (client_id, is_primary),
                PRIMARY KEY(id),
                CONSTRAINT fk_client_contacts_client
                    FOREIGN KEY (client_id) REFERENCES clients (id)
                    ON DELETE RESTRICT
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
                ('clients.contacts.view', 'Clientes', 'contacts_view', 'Consultar contactos de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.contacts.create', 'Clientes', 'contacts_create', 'Crear contactos de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.contacts.update', 'Clientes', 'contacts_update', 'Editar contactos de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.contacts.toggle_status', 'Clientes', 'contacts_toggle_status', 'Activar o desactivar contactos de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
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
                  'clients.contacts.view',
                  'clients.contacts.create',
                  'clients.contacts.update',
                  'clients.contacts.toggle_status'
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
            WHERE permission.code LIKE 'clients.contacts.%'"
        );

        $this->addSql(
            "DELETE FROM permissions
            WHERE code LIKE 'clients.contacts.%'"
        );

        $this->addSql('DROP TABLE client_contacts');
    }
}