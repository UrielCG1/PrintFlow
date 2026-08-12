<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega planificación por partida, operaciones manuales, equipos compatibles, estados y permisos de órdenes de servicio.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE service_orders
                    ADD planned_by_user_id INT DEFAULT NULL,
                    ADD planned_at DATETIME DEFAULT NULL,
                    ADD INDEX idx_service_orders_planned_by_user (planned_by_user_id),
                    ADD CONSTRAINT fk_service_orders_planned_by_user
                        FOREIGN KEY (planned_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
                SQL,
        );

        $this->addSql('ALTER TABLE service_orders DROP CONSTRAINT chk_service_orders_status');
        $this->addSql(
            <<<'SQL'
                ALTER TABLE service_orders
                    ADD CONSTRAINT chk_service_orders_status CHECK (
                        status IN ('PENDING_PLANNING', 'PLANNED')
                    )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE service_order_operation_plans (
                    id INT AUTO_INCREMENT NOT NULL,
                    service_order_item_id INT NOT NULL,
                    operation_id INT NOT NULL,
                    equipment_id INT DEFAULT NULL,
                    created_by_user_id INT NOT NULL,
                    deactivated_by_user_id INT DEFAULT NULL,
                    sequence_number INT UNSIGNED NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    operation_snapshot JSON NOT NULL,
                    equipment_snapshot JSON DEFAULT NULL,
                    deactivated_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE INDEX uniq_service_order_operation_plan_item_operation (service_order_item_id, operation_id),
                    INDEX idx_service_order_operation_plan_item_active_sequence (service_order_item_id, is_active, sequence_number),
                    INDEX idx_service_order_operation_plan_equipment_active (equipment_id, is_active),
                    INDEX idx_service_order_operation_plan_operation (operation_id),
                    INDEX idx_service_order_operation_plan_created_by_user (created_by_user_id),
                    INDEX idx_service_order_operation_plan_deactivated_by_user (deactivated_by_user_id),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_service_order_operation_plan_item
                        FOREIGN KEY (service_order_item_id) REFERENCES service_order_items (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_order_operation_plan_operation
                        FOREIGN KEY (operation_id) REFERENCES operations (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_order_operation_plan_equipment
                        FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_order_operation_plan_created_by_user
                        FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_service_order_operation_plan_deactivated_by_user
                        FOREIGN KEY (deactivated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                    CONSTRAINT chk_service_order_operation_plan_sequence
                        CHECK (sequence_number > 0)
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO permissions (
                    code, module, action, name, description, is_system, is_active, created_at, updated_at
                ) VALUES (
                    'service_orders.plan',
                    'service_orders',
                    'plan',
                    'Planificar órdenes de servicio',
                    'Permite definir fecha compromiso, ruta manual por partida y equipos compatibles, y marcar la orden como planificada.',
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
                    ON permissions.code IN ('service_orders.view', 'service_orders.plan')
                WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
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
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_order_operation_plans') > 0,
            'No se puede revertir mientras existan rutas planificadas; conservar su historia es obligatorio.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM service_orders WHERE status = 'PLANNED'") > 0,
            'No se puede revertir mientras existan órdenes planificadas.',
        );

        $this->addSql('DROP TABLE service_order_operation_plans');

        $this->addSql('ALTER TABLE service_orders DROP CONSTRAINT fk_service_orders_planned_by_user');
        $this->addSql('ALTER TABLE service_orders DROP INDEX idx_service_orders_planned_by_user');
        $this->addSql('ALTER TABLE service_orders DROP planned_by_user_id, DROP planned_at');

        $this->addSql('ALTER TABLE service_orders DROP CONSTRAINT chk_service_orders_status');
        $this->addSql(
            <<<'SQL'
                ALTER TABLE service_orders
                    ADD CONSTRAINT chk_service_orders_status CHECK (status IN ('PENDING_PLANNING'))
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions ON permissions.id = role_permissions.permission_id
                WHERE permissions.code = 'service_orders.plan'
                SQL,
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'service_orders.plan'");
    }
}
