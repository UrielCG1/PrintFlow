<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725083212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Amplía clientes con datos fiscales, comerciales, contactos, zonas y costos de entrega.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            'CREATE TABLE client_categories (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_client_categories_active_order (is_active, display_order),
                UNIQUE INDEX uniq_client_categories_name (name),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE delivery_zones (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                base_delivery_cost NUMERIC(12, 2) NOT NULL DEFAULT \'0.00\',
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_delivery_zones_active_order (is_active, display_order),
                UNIQUE INDEX uniq_delivery_zones_name (name),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            "INSERT INTO client_categories (
                name, description, display_order, is_active, created_at, updated_at
            ) VALUES
                ('General', 'Categoría inicial para clientes sin segmentación específica.', 10, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('Frecuente', 'Cliente con compras recurrentes.', 20, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('Mayorista', 'Cliente con condiciones comerciales de mayoreo.', 30, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                display_order = VALUES(display_order),
                is_active = VALUES(is_active),
                updated_at = UTC_TIMESTAMP()"
        );

        $this->addSql(
            "INSERT INTO delivery_zones (
                name, description, base_delivery_cost, display_order, is_active, created_at, updated_at
            ) VALUES
                ('Zona local', 'Cobertura local de prueba.', 0.00, 10, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('Zona cercana', 'Cobertura cercana de prueba.', 100.00, 20, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('Zona extendida', 'Cobertura extendida de prueba.', 250.00, 30, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                base_delivery_cost = VALUES(base_delivery_cost),
                display_order = VALUES(display_order),
                is_active = VALUES(is_active),
                updated_at = UTC_TIMESTAMP()"
        );

        $this->addSql(
            'ALTER TABLE clients
                ADD legal_name VARCHAR(160) DEFAULT NULL,
                ADD tax_regime_code VARCHAR(3) DEFAULT NULL,
                ADD fiscal_postal_code VARCHAR(5) DEFAULT NULL,
                ADD billing_email VARCHAR(180) DEFAULT NULL,
                ADD default_cfdi_use_code VARCHAR(10) DEFAULT NULL,
                ADD default_discount_percent DOUBLE PRECISION NOT NULL DEFAULT 0,
                ADD client_category_id INT DEFAULT NULL'
        );

        $this->addSql(
            'ALTER TABLE clients
                ADD CONSTRAINT fk_clients_category
                    FOREIGN KEY (client_category_id) REFERENCES client_categories (id)
                    ON DELETE RESTRICT'
        );

        $this->addSql(
            'CREATE INDEX idx_clients_category ON clients (client_category_id)'
        );

        $this->addSql(
            'ALTER TABLE client_contacts
                ADD phone_extension VARCHAR(15) DEFAULT NULL,
                ADD mobile_phone VARCHAR(40) DEFAULT NULL,
                ADD personal_mobile_phone VARCHAR(40) DEFAULT NULL,
                ADD work_schedule VARCHAR(160) DEFAULT NULL'
        );

        $this->addSql(
            'ALTER TABLE client_addresses
                ADD delivery_zone_id INT DEFAULT NULL,
                ADD delivery_cost NUMERIC(12, 2) NOT NULL DEFAULT \'0.00\''
        );

        $this->addSql(
            'ALTER TABLE client_addresses
                ADD CONSTRAINT fk_client_addresses_delivery_zone
                    FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones (id)
                    ON DELETE RESTRICT'
        );

        $this->addSql(
            'CREATE INDEX idx_client_addresses_delivery_zone
            ON client_addresses (delivery_zone_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            'ALTER TABLE client_addresses
                DROP FOREIGN KEY fk_client_addresses_delivery_zone'
        );

        $this->addSql(
            'DROP INDEX idx_client_addresses_delivery_zone ON client_addresses'
        );

        $this->addSql(
            'ALTER TABLE client_addresses
                DROP delivery_zone_id,
                DROP delivery_cost'
        );

        $this->addSql(
            'ALTER TABLE client_contacts
                DROP phone_extension,
                DROP mobile_phone,
                DROP personal_mobile_phone,
                DROP work_schedule'
        );

        $this->addSql(
            'ALTER TABLE clients DROP FOREIGN KEY fk_clients_category'
        );

        $this->addSql(
            'DROP INDEX idx_clients_category ON clients'
        );

        $this->addSql(
            'ALTER TABLE clients
                DROP legal_name,
                DROP tax_regime_code,
                DROP fiscal_postal_code,
                DROP billing_email,
                DROP default_cfdi_use_code,
                DROP default_discount_percent,
                DROP client_category_id'
        );

        $this->addSql('DROP TABLE delivery_zones');
        $this->addSql('DROP TABLE client_categories');
    }
}