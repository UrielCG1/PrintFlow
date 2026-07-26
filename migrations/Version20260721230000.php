<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea direcciones fiscales y de entrega de clientes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            'CREATE TABLE client_addresses (
                id INT AUTO_INCREMENT NOT NULL,
                client_id INT NOT NULL,
                label VARCHAR(100) NOT NULL,
                recipient_name VARCHAR(160) DEFAULT NULL,
                street VARCHAR(160) NOT NULL,
                exterior_number VARCHAR(30) NOT NULL,
                interior_number VARCHAR(30) DEFAULT NULL,
                neighborhood VARCHAR(120) DEFAULT NULL,
                postal_code CHAR(5) NOT NULL,
                municipality VARCHAR(120) NOT NULL,
                state VARCHAR(120) NOT NULL,
                country_code CHAR(2) NOT NULL DEFAULT \'MX\',
                references_text TEXT DEFAULT NULL,
                is_fiscal_address TINYINT(1) NOT NULL DEFAULT 0,
                is_delivery_address TINYINT(1) NOT NULL DEFAULT 0,
                is_default_fiscal TINYINT(1) NOT NULL DEFAULT 0,
                is_default_delivery TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                default_fiscal_client_id INT GENERATED ALWAYS AS (
                    CASE
                        WHEN is_active = 1
                         AND is_fiscal_address = 1
                         AND is_default_fiscal = 1
                        THEN client_id
                        ELSE NULL
                    END
                ) STORED,
                default_delivery_client_id INT GENERATED ALWAYS AS (
                    CASE
                        WHEN is_active = 1
                         AND is_delivery_address = 1
                         AND is_default_delivery = 1
                        THEN client_id
                        ELSE NULL
                    END
                ) STORED,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_client_addresses_client_active (client_id, is_active),
                UNIQUE INDEX uniq_client_addresses_default_fiscal (default_fiscal_client_id),
                UNIQUE INDEX uniq_client_addresses_default_delivery (default_delivery_client_id),
                PRIMARY KEY(id),
                CONSTRAINT chk_client_addresses_usage
                    CHECK (is_fiscal_address = 1 OR is_delivery_address = 1),
                CONSTRAINT chk_client_addresses_default_fiscal
                    CHECK (is_default_fiscal = 0 OR is_fiscal_address = 1),
                CONSTRAINT chk_client_addresses_default_delivery
                    CHECK (is_default_delivery = 0 OR is_delivery_address = 1),
                CONSTRAINT fk_client_addresses_client
                    FOREIGN KEY (client_id) REFERENCES clients (id)
                    ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            "INSERT INTO permissions (
                code, module, action, name, description,
                is_system, is_active, created_at, updated_at
            ) VALUES
                ('clients.addresses.view', 'Clientes', 'addresses_view', 'Consultar direcciones de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.addresses.create', 'Clientes', 'addresses_create', 'Crear direcciones de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.addresses.update', 'Clientes', 'addresses_update', 'Editar direcciones de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('clients.addresses.toggle_status', 'Clientes', 'addresses_toggle_status', 'Activar o desactivar direcciones de clientes', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
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
                  'clients.addresses.view',
                  'clients.addresses.create',
                  'clients.addresses.update',
                  'clients.addresses.toggle_status'
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
            WHERE permission.code LIKE 'clients.addresses.%'"
        );

        $this->addSql("DELETE FROM permissions WHERE code LIKE 'clients.addresses.%'");
        $this->addSql('DROP TABLE client_addresses');
    }
}