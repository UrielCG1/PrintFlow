<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega compromisos auditados, prioridades por partida y reservas fijables para reprogramación.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql('ALTER TABLE service_orders ADD commitment_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql("ALTER TABLE service_order_items ADD priority VARCHAR(12) NOT NULL DEFAULT 'normal', ADD INDEX idx_service_order_items_priority (priority)");
        $this->addSql('ALTER TABLE service_order_operation_plans ADD is_schedule_pinned TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_service_order_plan_pinned_active (is_schedule_pinned, is_active)');

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (
                code, module, action, name, description, is_system, is_active, created_at, updated_at
            ) VALUES
                (
                    'service_orders.commitment.manage',
                    'service_orders',
                    'commitment',
                    'Gestionar fechas compromiso',
                    'Permite pactar o ajustar fechas de entrega con motivo y revisar el riesgo operativo.',
                    1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                ),
                (
                    'production.priority.manage',
                    'production',
                    'priority',
                    'Cambiar prioridad de producción',
                    'Permite cambiar Normal, Alta o Urgente por partida con motivo.',
                    1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                ),
                (
                    'production.schedule.reprogram',
                    'production',
                    'reprogram',
                    'Reprogramar agenda de producción',
                    'Permite fijar reservas y confirmar reprogramaciones después de revisar su impacto.',
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
            INNER JOIN permissions ON (
                permissions.code = 'service_orders.commitment.manage'
                AND roles.code IN ('ROLE_ADMIN', 'ROLE_SALES', 'ROLE_PRODUCTION')
            ) OR (
                permissions.code IN ('production.priority.manage', 'production.schedule.reprogram')
                AND roles.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_orders WHERE commitment_reason IS NOT NULL') > 0
                || (int) $this->connection->fetchOne("SELECT COUNT(*) FROM service_order_items WHERE priority <> 'normal'") > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_order_operation_plans WHERE is_schedule_pinned = 1') > 0
                || (int) $this->connection->fetchOne(<<<'SQL'
                    SELECT COUNT(*) FROM audit_logs
                    WHERE action IN (
                        'service_order.commitment_date_updated',
                        'production.item_priority_changed',
                        'production.priority_rescheduled',
                        'production.reservation_pinned',
                        'production.reservation_unpinned'
                    )
                    SQL) > 0,
            'No se revierte la Fase 3 mientras existan cambios de compromiso, prioridades o reservas fijadas.',
        );

        $this->addSql(<<<'SQL'
            DELETE role_permissions FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code IN (
                'service_orders.commitment.manage',
                'production.priority.manage',
                'production.schedule.reprogram'
            )
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('service_orders.commitment.manage', 'production.priority.manage', 'production.schedule.reprogram')");
        $this->addSql('ALTER TABLE service_order_operation_plans DROP INDEX idx_service_order_plan_pinned_active, DROP is_schedule_pinned');
        $this->addSql('ALTER TABLE service_order_items DROP INDEX idx_service_order_items_priority, DROP priority');
        $this->addSql('ALTER TABLE service_orders DROP commitment_reason');
    }
}
