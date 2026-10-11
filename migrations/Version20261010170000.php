<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Congela modalidad/contacto de entrega y petición, archivo y código estable de cada partida de la orden.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE service_orders
                ADD fulfillment_method VARCHAR(20) NOT NULL DEFAULT 'undefined',
                ADD delivery_contact_snapshot JSON DEFAULT NULL,
                ADD delivery_preparation_issue VARCHAR(255) DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE service_orders
            SET delivery_contact_snapshot = JSON_OBJECT(
                'source', 'LEGACY_ORDER',
                'status', 'HISTORICAL_DATA_NOT_AVAILABLE'
            ),
            delivery_preparation_issue = 'La modalidad de entrega sigue pendiente de definición por Ventas.'
            WHERE delivery_contact_snapshot IS NULL
            SQL);
        $this->addSql('ALTER TABLE service_orders MODIFY delivery_contact_snapshot JSON NOT NULL');

        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_items
                ADD tracking_code VARCHAR(64) DEFAULT NULL,
                ADD request_details_snapshot JSON DEFAULT NULL,
                ADD source_file_snapshot JSON DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE service_order_items
            INNER JOIN service_orders ON service_orders.id = service_order_items.service_order_id
            SET tracking_code = CONCAT(
                service_orders.folio,
                '-P',
                LPAD(service_order_items.line_number, 3, '0')
            )
            WHERE tracking_code IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_items
                MODIFY tracking_code VARCHAR(64) NOT NULL,
                ADD UNIQUE INDEX uniq_service_order_items_tracking_code (tracking_code)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*)
                FROM service_order_items
                WHERE request_details_snapshot IS NOT NULL OR source_file_snapshot IS NOT NULL
                SQL) > 0,
            'No se puede revertir mientras existan snapshots de petición o archivos de trazabilidad.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*)
                FROM service_orders
                WHERE JSON_UNQUOTE(JSON_EXTRACT(delivery_contact_snapshot, '$.source')) <> 'LEGACY_ORDER'
                SQL) > 0,
            'No se puede revertir mientras existan contactos de entrega congelados para órdenes nuevas.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_items
                DROP INDEX uniq_service_order_items_tracking_code,
                DROP tracking_code,
                DROP request_details_snapshot,
                DROP source_file_snapshot
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE service_orders
                DROP fulfillment_method,
                DROP delivery_contact_snapshot,
                DROP delivery_preparation_issue
            SQL);
    }
}
