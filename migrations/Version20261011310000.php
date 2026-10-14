<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011310000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega el permiso explicito para cancelar ordenes de produccion y liberar sus apartados pendientes.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migracion requiere MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (
                code, module, action, name, description, is_system, is_active, created_at, updated_at
            ) VALUES (
                'service_orders.cancel',
                'service_orders',
                'cancel',
                'Cancelar produccion de una orden',
                'Permite detener una orden, liberar solo sus apartados no consumidos y conservar su trazabilidad.',
                1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE
                module = VALUES(module), action = VALUES(action), name = VALUES(name),
                description = VALUES(description), is_system = VALUES(is_system),
                is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code = 'service_orders.cancel'
            WHERE roles.code = 'ROLE_ADMIN'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migracion requiere MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'service_order.production_cancelled'") > 0
                || (int) $this->connection->fetchOne("SELECT COUNT(*) FROM service_order_traceability_events WHERE event_type = 'production.order_cancelled'") > 0,
            'No se revierte mientras existan cancelaciones de produccion registradas.',
        );

        $this->addSql(<<<'SQL'
            DELETE role_permissions FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code = 'service_orders.cancel'
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code = 'service_orders.cancel'");
    }
}
