<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011280000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registra solicitudes de compra y sus recepciones físicas parciales sin crear existencias anticipadas.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_purchase_requests (
                id INT AUTO_INCREMENT NOT NULL,
                created_by_user_id INT NOT NULL,
                request_code VARCHAR(40) NOT NULL,
                idempotency_key VARCHAR(100) NOT NULL,
                command_fingerprint VARCHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL,
                tracking_reference VARCHAR(100) DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                tracking_started_at DATETIME DEFAULT NULL,
                closed_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_inventory_purchase_request_code (request_code),
                UNIQUE INDEX uniq_inventory_purchase_request_idempotency (idempotency_key),
                INDEX idx_inventory_purchase_request_status_created (status, created_at),
                INDEX idx_inventory_purchase_request_created_by (created_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_inventory_purchase_request_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_purchase_request_lines (
                id INT AUTO_INCREMENT NOT NULL,
                purchase_request_id INT NOT NULL,
                material_variant_id INT NOT NULL,
                supplier_material_variant_id INT DEFAULT NULL,
                line_number INT NOT NULL,
                requested_quantity NUMERIC(20, 6) NOT NULL,
                received_quantity NUMERIC(20, 6) NOT NULL DEFAULT 0.000000,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_purchase_request_line_number (purchase_request_id, line_number),
                UNIQUE INDEX uniq_purchase_request_line_variant (purchase_request_id, material_variant_id),
                INDEX idx_purchase_request_line_variant (material_variant_id),
                INDEX idx_purchase_request_line_offer (supplier_material_variant_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_purchase_request_line_request FOREIGN KEY (purchase_request_id) REFERENCES inventory_purchase_requests (id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchase_request_line_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchase_request_line_offer FOREIGN KEY (supplier_material_variant_id) REFERENCES supplier_material_variants (id) ON DELETE RESTRICT,
                CONSTRAINT chk_purchase_request_line_requested CHECK (requested_quantity > 0),
                CONSTRAINT chk_purchase_request_line_received CHECK (received_quantity >= 0 AND received_quantity <= requested_quantity)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_receipt_lines
                ADD purchase_request_line_id INT DEFAULT NULL AFTER material_variant_id,
                ADD INDEX idx_receipt_line_purchase_request (purchase_request_line_id),
                ADD CONSTRAINT fk_receipt_line_purchase_request FOREIGN KEY (purchase_request_line_id) REFERENCES inventory_purchase_request_lines (id) ON DELETE RESTRICT
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_purchase_requests') > 0, 'No se puede revertir mientras existan solicitudes de compra registradas.');
        $this->addSql('ALTER TABLE inventory_receipt_lines DROP FOREIGN KEY fk_receipt_line_purchase_request, DROP INDEX idx_receipt_line_purchase_request, DROP purchase_request_line_id');
        $this->addSql('DROP TABLE inventory_purchase_request_lines');
        $this->addSql('DROP TABLE inventory_purchase_requests');
    }
}
