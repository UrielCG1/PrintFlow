<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea órdenes de servicio inmutables desde cotizaciones aceptadas, con folio, snapshots y permisos comerciales.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE service_order_folio_sequences (
                    folio_year SMALLINT UNSIGNED NOT NULL,
                    last_number INT UNSIGNED NOT NULL,
                    PRIMARY KEY (folio_year)
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE service_orders (
                    id INT AUTO_INCREMENT NOT NULL,
                    source_quotation_id INT NOT NULL,
                    created_by_user_id INT NOT NULL,
                    folio VARCHAR(40) NOT NULL,
                    status VARCHAR(30) NOT NULL,
                    source_quotation_folio VARCHAR(40) NOT NULL,
                    quotation_snapshot JSON NOT NULL,
                    client_snapshot JSON NOT NULL,
                    fiscal_address_snapshot JSON DEFAULT NULL,
                    delivery_address_snapshot JSON DEFAULT NULL,
                    notes LONGTEXT DEFAULT NULL,
                    currency VARCHAR(3) NOT NULL,
                    discount_percent NUMERIC(5, 2) NOT NULL,
                    tax_rate NUMERIC(5, 4) NOT NULL,
                    subtotal NUMERIC(14, 2) NOT NULL,
                    discount_amount NUMERIC(14, 2) NOT NULL,
                    taxable_amount NUMERIC(14, 2) NOT NULL,
                    tax_amount NUMERIC(14, 2) NOT NULL,
                    total NUMERIC(14, 2) NOT NULL,
                    commitment_date DATE DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE INDEX uniq_service_orders_folio (folio),
                    UNIQUE INDEX uniq_service_orders_source_quotation (source_quotation_id),
                    INDEX idx_service_orders_status_created_at (status, created_at),
                    INDEX idx_service_orders_created_by_user (created_by_user_id),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_service_orders_source_quotation
                        FOREIGN KEY (source_quotation_id) REFERENCES quotations (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_orders_created_by_user
                        FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                    CONSTRAINT chk_service_orders_status
                        CHECK (status IN ('PENDING_PLANNING'))
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE service_order_items (
                    id INT AUTO_INCREMENT NOT NULL,
                    service_order_id INT NOT NULL,
                    source_quotation_item_id INT NOT NULL,
                    commercial_item_id INT NOT NULL,
                    line_number INT UNSIGNED NOT NULL,
                    quantity NUMERIC(14, 4) NOT NULL,
                    unit_price NUMERIC(12, 2) NOT NULL,
                    line_subtotal NUMERIC(14, 2) NOT NULL,
                    commercial_item_snapshot JSON NOT NULL,
                    price_rule_snapshot JSON DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE INDEX uniq_service_order_items_line_number (service_order_id, line_number),
                    UNIQUE INDEX uniq_service_order_items_source_quotation_item (source_quotation_item_id),
                    INDEX idx_service_order_items_commercial_item (commercial_item_id),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_service_order_items_service_order
                        FOREIGN KEY (service_order_id) REFERENCES service_orders (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_order_items_source_quotation_item
                        FOREIGN KEY (source_quotation_item_id) REFERENCES quotation_items (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_order_items_commercial_item
                        FOREIGN KEY (commercial_item_id) REFERENCES commercial_items (id) ON DELETE RESTRICT
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO permissions (
                    code, module, action, name, description, is_system, is_active, created_at, updated_at
                ) VALUES
                    (
                        'service_orders.view',
                        'service_orders',
                        'view',
                        'Consultar órdenes de servicio',
                        'Permite consultar órdenes creadas desde cotizaciones aceptadas.',
                        1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                    ),
                    (
                        'service_orders.create_from_quotation',
                        'service_orders',
                        'create_from_quotation',
                        'Crear órdenes desde cotizaciones',
                        'Permite convertir una cotización aceptada en una orden de servicio inmutable.',
                        1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
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
                        'service_orders.view',
                        'service_orders.create_from_quotation'
                    )
                WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_SALES')
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_orders') > 0,
            'No se puede revertir mientras existan órdenes de servicio; conservar su historia es obligatorio.',
        );

        $this->addSql('DROP TABLE service_order_items');
        $this->addSql('DROP TABLE service_orders');
        $this->addSql('DROP TABLE service_order_folio_sequences');

        $this->addSql(
            <<<'SQL'
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions ON permissions.id = role_permissions.permission_id
                WHERE permissions.code IN ('service_orders.view', 'service_orders.create_from_quotation')
                SQL,
        );
        $this->addSql("DELETE FROM permissions WHERE code IN ('service_orders.view', 'service_orders.create_from_quotation')");
    }
}
