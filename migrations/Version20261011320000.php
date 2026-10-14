<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011320000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega fecha estimada, cancelación trazable y alertas de plazo para solicitudes de compra.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_purchase_requests
                ADD expected_receipt_on DATE DEFAULT NULL AFTER tracking_reference,
                ADD cancelled_by_user_id INT DEFAULT NULL AFTER closed_at,
                ADD cancelled_at DATETIME DEFAULT NULL AFTER cancelled_by_user_id,
                ADD cancellation_reason LONGTEXT DEFAULT NULL AFTER cancelled_at,
                ADD INDEX idx_purchase_request_status_expected (status, expected_receipt_on),
                ADD INDEX idx_purchase_request_cancelled_by (cancelled_by_user_id),
                ADD CONSTRAINT fk_purchase_request_cancelled_by FOREIGN KEY (cancelled_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_purchase_requests WHERE expected_receipt_on IS NOT NULL OR cancelled_at IS NOT NULL') > 0, 'No se puede revertir mientras existan fechas estimadas o cancelaciones de compra registradas.');

        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_purchase_requests
                DROP FOREIGN KEY fk_purchase_request_cancelled_by,
                DROP INDEX idx_purchase_request_status_expected,
                DROP INDEX idx_purchase_request_cancelled_by,
                DROP COLUMN cancellation_reason,
                DROP COLUMN cancelled_at,
                DROP COLUMN cancelled_by_user_id,
                DROP COLUMN expected_receipt_on
            SQL);
    }
}
