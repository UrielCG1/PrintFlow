<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega estados independientes de producción/entrega, intentos idempotentes, eventos de trazabilidad y el rol Calidad/Entregas.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE service_orders
                ADD production_status VARCHAR(30) NOT NULL DEFAULT 'WAITING_DESIGN',
                ADD delivery_status VARCHAR(30) NOT NULL DEFAULT 'NOT_READY',
                ADD traceability_version INT UNSIGNED NOT NULL DEFAULT 1,
                ADD INDEX idx_service_orders_production_status_created_at (production_status, created_at),
                ADD INDEX idx_service_orders_delivery_status_created_at (delivery_status, created_at),
                ADD CONSTRAINT chk_service_orders_production_status
                    CHECK (production_status IN ('WAITING_DESIGN', 'IN_PRODUCTION', 'WAITING_QUALITY', 'QUALITY_REWORK', 'PRODUCTION_COMPLETED', 'CANCELLED')),
                ADD CONSTRAINT chk_service_orders_delivery_status
                    CHECK (delivery_status IN ('NOT_READY', 'READY_FOR_PICKUP', 'READY_FOR_SHIPMENT', 'SHIPPED', 'DELIVERED')),
                ADD CONSTRAINT chk_service_orders_traceability_version CHECK (traceability_version > 0)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE production_operation_executions (
                id INT AUTO_INCREMENT NOT NULL,
                plan_id INT NOT NULL,
                origin_execution_id INT DEFAULT NULL,
                assigned_operator_user_id INT NOT NULL,
                created_by_user_id INT NOT NULL,
                attempt_number INT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL,
                open_marker INT UNSIGNED DEFAULT 1,
                pause_reason VARCHAR(500) DEFAULT NULL,
                started_at DATETIME NOT NULL,
                paused_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                completion_note VARCHAR(500) DEFAULT NULL,
                state_version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_production_execution_plan_attempt (plan_id, attempt_number),
                UNIQUE INDEX uniq_production_execution_open_plan (plan_id, open_marker),
                INDEX idx_production_execution_plan_status (plan_id, status),
                INDEX idx_production_execution_assigned_status (assigned_operator_user_id, status),
                INDEX idx_production_execution_origin (origin_execution_id),
                INDEX idx_production_execution_created_by (created_by_user_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_production_execution_plan FOREIGN KEY (plan_id) REFERENCES service_order_operation_plans (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_execution_origin FOREIGN KEY (origin_execution_id) REFERENCES production_operation_executions (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_execution_operator FOREIGN KEY (assigned_operator_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_execution_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_production_execution_attempt CHECK (attempt_number > 0),
                CONSTRAINT chk_production_execution_status CHECK (status IN ('IN_PROGRESS', 'PAUSED', 'COMPLETED', 'CANCELLED')),
                CONSTRAINT chk_production_execution_version CHECK (state_version > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE service_order_traceability_events (
                id INT AUTO_INCREMENT NOT NULL,
                service_order_id INT NOT NULL,
                service_order_item_id INT DEFAULT NULL,
                operation_plan_id INT DEFAULT NULL,
                operation_execution_id INT DEFAULT NULL,
                actor_user_id INT DEFAULT NULL,
                event_type VARCHAR(120) NOT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                command_id VARCHAR(64) DEFAULT NULL,
                previous_state JSON DEFAULT NULL,
                new_state JSON DEFAULT NULL,
                reason VARCHAR(500) DEFAULT NULL,
                context JSON DEFAULT NULL,
                occurred_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_service_order_traceability_command (command_id),
                INDEX idx_service_order_traceability_order_time (service_order_id, occurred_at, id),
                INDEX idx_service_order_traceability_item_time (service_order_item_id, occurred_at, id),
                INDEX idx_service_order_traceability_plan_time (operation_plan_id, occurred_at, id),
                INDEX idx_service_order_traceability_execution_time (operation_execution_id, occurred_at, id),
                INDEX idx_service_order_traceability_actor (actor_user_id),
                INDEX idx_service_order_traceability_correlation (correlation_id),
                PRIMARY KEY (id),
                CONSTRAINT fk_service_order_traceability_order FOREIGN KEY (service_order_id) REFERENCES service_orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_service_order_traceability_item FOREIGN KEY (service_order_item_id) REFERENCES service_order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_service_order_traceability_plan FOREIGN KEY (operation_plan_id) REFERENCES service_order_operation_plans (id) ON DELETE RESTRICT,
                CONSTRAINT fk_service_order_traceability_execution FOREIGN KEY (operation_execution_id) REFERENCES production_operation_executions (id) ON DELETE RESTRICT,
                CONSTRAINT fk_service_order_traceability_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO roles (code, name, description, is_system, is_active, created_at, updated_at)
            VALUES (
                'ROLE_QUALITY_DELIVERY',
                'Calidad/Entregas',
                'Revisión final de producción, liberación y confirmación de entrega.',
                1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            ) ON DUPLICATE KEY UPDATE
                name = VALUES(name), description = VALUES(description),
                is_system = VALUES(is_system), is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES
                ('production.view', 'production', 'view', 'Consultar producción', 'Permite consultar órdenes y partidas operativas sin exponer importes comerciales.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.plan', 'production', 'plan', 'Planificar producción', 'Permite preparar la planificación operativa de una orden.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.assign', 'production', 'assign', 'Asignar operaciones de producción', 'Permite asignar responsables a operaciones planificadas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.execute', 'production', 'execute', 'Ejecutar operaciones asignadas', 'Permite iniciar, pausar, reanudar y completar operaciones asignadas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.substitution.approve', 'production', 'substitution_approve', 'Autorizar sustituciones de producción', 'Permite aprobar una sustitución compatible de máquina o material.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.rework.authorize', 'production', 'rework_authorize', 'Autorizar retrabajos', 'Permite autorizar el retrabajo derivado de una no conformidad.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.files.upload', 'production', 'files_upload', 'Cargar archivos de producción', 'Permite cargar arte final, captura de referencia y evidencia técnica.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.files.download', 'production', 'files_download', 'Descargar archivos de producción', 'Permite descargar únicamente archivos operativos autorizados.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('production.labels.print', 'production', 'labels_print', 'Imprimir etiquetas de producción', 'Permite imprimir o reimprimir etiquetas QR operativas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('quality.review', 'quality', 'review', 'Revisar Calidad', 'Permite revisar la totalidad de las partidas de una orden.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('quality.return', 'quality', 'return', 'Devolver partidas por no conformidad', 'Permite registrar no conformidades y devolver una partida al área responsable.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('quality.release', 'quality', 'release', 'Liberar producción terminada', 'Permite marcar Producción terminada después de la revisión integral.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('delivery.ship', 'delivery', 'ship', 'Registrar envío a domicilio', 'Permite registrar el hito Enviado para órdenes a domicilio.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('delivery.confirm', 'delivery', 'confirm', 'Confirmar entrega al cliente', 'Permite confirmar la entrega de una orden completa.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('delivery.notification.retry', 'delivery', 'notification_retry', 'Reintentar aviso de recolección', 'Permite reintentar un aviso de orden lista para recoger.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                module = VALUES(module), action = VALUES(action), name = VALUES(name),
                description = VALUES(description), is_system = VALUES(is_system),
                is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN (
                'production.view', 'production.plan', 'production.assign', 'production.execute',
                'production.substitution.approve', 'production.rework.authorize',
                'production.files.upload', 'production.files.download', 'production.labels.print',
                'quality.review', 'quality.return', 'quality.release', 'delivery.ship',
                'delivery.confirm', 'delivery.notification.retry'
            )
            WHERE roles.code = 'ROLE_ADMIN'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN (
                'production.view', 'production.plan', 'production.assign', 'production.execute',
                'production.substitution.approve', 'production.rework.authorize',
                'production.files.upload', 'production.files.download', 'production.labels.print'
            )
            WHERE roles.code = 'ROLE_PRODUCTION'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN (
                'production.view', 'production.execute', 'production.files.upload',
                'production.files.download', 'production.labels.print'
            )
            WHERE roles.code = 'ROLE_OPERATOR'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN (
                'production.view', 'production.files.download', 'quality.review',
                'quality.return', 'quality.release', 'delivery.ship',
                'delivery.confirm', 'delivery.notification.retry'
            )
            WHERE roles.code = 'ROLE_QUALITY_DELIVERY'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_operation_executions') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_order_traceability_events') > 0,
            'No se puede revertir mientras existan intentos o eventos de trazabilidad; su historia debe conservarse.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*)
                FROM user_roles
                INNER JOIN roles ON roles.id = user_roles.role_id
                WHERE roles.code = 'ROLE_QUALITY_DELIVERY'
                SQL) > 0,
            'No se puede revertir mientras Calidad/Entregas esté asignado a usuarios.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM service_orders
                WHERE production_status <> 'WAITING_DESIGN'
                    OR delivery_status <> 'NOT_READY'
                    OR traceability_version <> 1
                SQL) > 0,
            'No se puede revertir mientras existan órdenes con estado o versión de trazabilidad modificados.',
        );

        $this->addSql(<<<'SQL'
            DELETE role_permissions FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code IN (
                'production.view', 'production.plan', 'production.assign', 'production.execute',
                'production.substitution.approve', 'production.rework.authorize',
                'production.files.upload', 'production.files.download', 'production.labels.print',
                'quality.review', 'quality.return', 'quality.release', 'delivery.ship',
                'delivery.confirm', 'delivery.notification.retry'
            )
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM permissions WHERE code IN (
                'production.view', 'production.plan', 'production.assign', 'production.execute',
                'production.substitution.approve', 'production.rework.authorize',
                'production.files.upload', 'production.files.download', 'production.labels.print',
                'quality.review', 'quality.return', 'quality.release', 'delivery.ship',
                'delivery.confirm', 'delivery.notification.retry'
            )
            SQL);
        $this->addSql("DELETE FROM roles WHERE code = 'ROLE_QUALITY_DELIVERY'");
        $this->addSql('DROP TABLE service_order_traceability_events');
        $this->addSql('DROP TABLE production_operation_executions');
        $this->addSql(<<<'SQL'
            ALTER TABLE service_orders
                DROP INDEX idx_service_orders_production_status_created_at,
                DROP INDEX idx_service_orders_delivery_status_created_at,
                DROP CHECK chk_service_orders_production_status,
                DROP CHECK chk_service_orders_delivery_status,
                DROP CHECK chk_service_orders_traceability_version,
                DROP production_status,
                DROP delivery_status,
                DROP traceability_version
            SQL);
    }
}
