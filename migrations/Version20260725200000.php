<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la base documental de cotizaciones, sus partidas y permisos iniciales.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql("CREATE TABLE quotations (
            id INT AUTO_INCREMENT NOT NULL,
            client_id INT NOT NULL,
            created_by_user_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            folio VARCHAR(40) DEFAULT NULL,
            expires_at DATE NOT NULL,
            issued_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            currency CHAR(3) NOT NULL DEFAULT 'MXN',
            client_snapshot JSON NOT NULL,
            fiscal_address_snapshot JSON DEFAULT NULL,
            delivery_address_snapshot JSON DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            discount_percent NUMERIC(5, 2) NOT NULL DEFAULT 0.00,
            tax_rate NUMERIC(5, 4) NOT NULL DEFAULT 0.1600,
            subtotal NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            discount_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            taxable_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            tax_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            total NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_quotations_folio (folio),
            INDEX idx_quotations_status_expires_at (status, expires_at),
            INDEX idx_quotations_client_created_at (client_id, created_at),
            PRIMARY KEY(id),
            CONSTRAINT chk_quotations_status CHECK (
                status IN ('DRAFT', 'ISSUED', 'SENT', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'CANCELLED')
            ),
            CONSTRAINT chk_quotations_discount_percent CHECK (
                discount_percent >= 0 AND discount_percent <= 100
            ),
            CONSTRAINT chk_quotations_tax_rate CHECK (
                tax_rate >= 0 AND tax_rate <= 1
            ),
            CONSTRAINT chk_quotations_amounts CHECK (
                subtotal >= 0
                AND discount_amount >= 0
                AND taxable_amount >= 0
                AND tax_amount >= 0
                AND total >= 0
            ),
            CONSTRAINT chk_quotations_issue_data CHECK (
                (status = 'DRAFT' AND folio IS NULL AND issued_at IS NULL)
                OR
                (status <> 'DRAFT' AND folio IS NOT NULL AND issued_at IS NOT NULL)
            ),
            CONSTRAINT fk_quotations_client FOREIGN KEY (client_id)
                REFERENCES clients (id) ON DELETE RESTRICT,
            CONSTRAINT fk_quotations_created_by_user FOREIGN KEY (created_by_user_id)
                REFERENCES users (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE quotation_items (
            id INT AUTO_INCREMENT NOT NULL,
            quotation_id INT NOT NULL,
            commercial_item_id INT NOT NULL,
            line_number INT UNSIGNED NOT NULL,
            quantity NUMERIC(14, 4) NOT NULL,
            unit_price NUMERIC(12, 2) NOT NULL,
            line_subtotal NUMERIC(14, 2) NOT NULL,
            commercial_item_snapshot JSON NOT NULL,
            price_rule_snapshot JSON DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX uniq_quotation_items_line_number (quotation_id, line_number),
            INDEX idx_quotation_items_commercial_item (commercial_item_id),
            PRIMARY KEY(id),
            CONSTRAINT chk_quotation_items_line_number CHECK (line_number > 0),
            CONSTRAINT chk_quotation_items_quantity CHECK (quantity > 0),
            CONSTRAINT chk_quotation_items_unit_price CHECK (unit_price >= 0),
            CONSTRAINT chk_quotation_items_line_subtotal CHECK (line_subtotal >= 0),
            CONSTRAINT fk_quotation_items_quotation FOREIGN KEY (quotation_id)
                REFERENCES quotations (id) ON DELETE RESTRICT,
            CONSTRAINT fk_quotation_items_commercial_item FOREIGN KEY (commercial_item_id)
                REFERENCES commercial_items (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("INSERT INTO permissions (
            code, module, action, name, description, is_system, is_active, created_at, updated_at
        ) VALUES
            ('quotations.view', 'Cotizaciones', 'view', 'Consultar cotizaciones', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('quotations.create', 'Cotizaciones', 'create', 'Crear cotizaciones', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('quotations.update', 'Cotizaciones', 'update', 'Editar cotizaciones en borrador', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('quotations.issue', 'Cotizaciones', 'issue', 'Emitir cotizaciones', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('quotations.manage_status', 'Cotizaciones', 'manage_status', 'Actualizar estado de cotizaciones emitidas', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('quotations.send', 'Cotizaciones', 'send', 'Enviar cotizaciones por correo', NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                module = VALUES(module),
                action = VALUES(action),
                name = VALUES(name),
                description = VALUES(description),
                is_system = VALUES(is_system),
                is_active = VALUES(is_active),
                updated_at = UTC_TIMESTAMP()");

        $this->addSql("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT role.id, permission.id
            FROM roles AS role
            CROSS JOIN permissions AS permission
            WHERE role.code = 'ROLE_ADMIN'
              AND permission.code IN (
                  'quotations.view',
                  'quotations.create',
                  'quotations.update',
                  'quotations.issue',
                  'quotations.manage_status',
                  'quotations.send'
              )");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql("DELETE role_permission
            FROM role_permissions AS role_permission
            INNER JOIN permissions AS permission ON permission.id = role_permission.permission_id
            WHERE permission.code LIKE 'quotations.%'");

        $this->addSql("DELETE FROM permissions WHERE code LIKE 'quotations.%'");

        $this->addSql('DROP TABLE quotation_items');
        $this->addSql('DROP TABLE quotations');
    }
}