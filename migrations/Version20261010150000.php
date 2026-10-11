<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega capacidades validadas, estimaciones físicas, reservas laborables y excepciones auditadas para producción.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE equipment
                ADD capacity_unit VARCHAR(32) DEFAULT NULL,
                ADD capacity_rate NUMERIC(12, 4) DEFAULT NULL,
                ADD setup_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                ADD cleanup_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                ADD efficiency_percent NUMERIC(5, 2) NOT NULL DEFAULT 100.00,
                ADD capacity_valid_from DATE DEFAULT NULL,
                ADD capacity_valid_until DATE DEFAULT NULL,
                ADD capacity_validated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                ADD capacity_validated_by_user_id INT DEFAULT NULL,
                ADD INDEX idx_equipment_capacity_validated (capacity_unit, capacity_validated_at),
                ADD INDEX idx_equipment_capacity_validator (capacity_validated_by_user_id),
                ADD CONSTRAINT fk_equipment_capacity_validator FOREIGN KEY (capacity_validated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                ADD CONSTRAINT chk_equipment_efficiency CHECK (efficiency_percent > 0 AND efficiency_percent <= 100),
                ADD CONSTRAINT chk_equipment_capacity_dates CHECK (capacity_valid_until IS NULL OR capacity_valid_from IS NULL OR capacity_valid_until >= capacity_valid_from)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_operation_plans
                ADD operator_user_id INT DEFAULT NULL,
                ADD estimated_load_value NUMERIC(14, 4) DEFAULT NULL,
                ADD estimated_load_unit VARCHAR(20) DEFAULT NULL,
                ADD estimate_kind VARCHAR(16) DEFAULT NULL,
                ADD estimated_minutes INT UNSIGNED DEFAULT NULL,
                ADD manual_estimate_reason VARCHAR(500) DEFAULT NULL,
                ADD estimate_snapshot JSON DEFAULT NULL,
                ADD estimated_by_user_id INT DEFAULT NULL,
                ADD estimated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                ADD actual_minutes INT UNSIGNED DEFAULT NULL,
                ADD actual_note VARCHAR(500) DEFAULT NULL,
                ADD actual_by_user_id INT DEFAULT NULL,
                ADD actual_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                ADD INDEX idx_service_order_plan_operator (operator_user_id),
                ADD INDEX idx_service_order_plan_estimated_by (estimated_by_user_id),
                ADD INDEX idx_service_order_plan_actual_by (actual_by_user_id),
                ADD CONSTRAINT fk_service_order_plan_operator FOREIGN KEY (operator_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                ADD CONSTRAINT fk_service_order_plan_estimated_by FOREIGN KEY (estimated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                ADD CONSTRAINT fk_service_order_plan_actual_by FOREIGN KEY (actual_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                ADD CONSTRAINT chk_service_order_plan_estimate CHECK (estimated_minutes IS NULL OR estimated_minutes > 0)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE production_reservations (
                id INT AUTO_INCREMENT NOT NULL,
                plan_id INT NOT NULL,
                equipment_id INT NOT NULL,
                created_by_user_id INT NOT NULL,
                segment_number INT UNSIGNED NOT NULL,
                starts_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                ends_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                UNIQUE INDEX uniq_production_reservation_plan_segment (plan_id, segment_number),
                INDEX idx_production_reservation_equipment_window (equipment_id, is_active, starts_at, ends_at),
                INDEX idx_production_reservation_plan_active (plan_id, is_active),
                INDEX idx_production_reservation_created_by (created_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_production_reservation_plan FOREIGN KEY (plan_id) REFERENCES service_order_operation_plans (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_reservation_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_reservation_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_production_reservation_segment CHECK (segment_number > 0),
                CONSTRAINT chk_production_reservation_window CHECK (ends_at > starts_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE production_calendar_exceptions (
                id INT AUTO_INCREMENT NOT NULL,
                equipment_id INT DEFAULT NULL,
                created_by_user_id INT NOT NULL,
                deactivated_by_user_id INT DEFAULT NULL,
                type VARCHAR(20) NOT NULL,
                starts_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                ends_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                reason VARCHAR(500) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                deactivated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                INDEX idx_production_calendar_exception_window (is_active, starts_at, ends_at),
                INDEX idx_production_calendar_exception_equipment (equipment_id, is_active),
                INDEX idx_production_calendar_exception_created_by (created_by_user_id),
                INDEX idx_production_calendar_exception_deactivated_by (deactivated_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_production_calendar_exception_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_calendar_exception_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_calendar_exception_deactivated_by FOREIGN KEY (deactivated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_production_calendar_exception_window CHECK (ends_at > starts_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (
                code, module, action, name, description, is_system, is_active, created_at, updated_at
            ) VALUES (
                'production_calendar.manage',
                'production_calendar',
                'manage',
                'Gestionar jornada y excepciones de producción',
                'Permite registrar feriados, mantenimiento, averías y horas extraordinarias autorizadas.',
                1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            ) ON DUPLICATE KEY UPDATE
                module = VALUES(module), action = VALUES(action), name = VALUES(name),
                description = VALUES(description), is_system = VALUES(is_system),
                is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code = 'production_calendar.manage'
            WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_reservations') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_calendar_exceptions') > 0,
            'No se revierte la Fase 2 mientras existan reservas o excepciones registradas.',
        );

        $this->addSql(<<<'SQL'
            DELETE role_permissions FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code = 'production_calendar.manage'
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code = 'production_calendar.manage'");
        $this->addSql('DROP TABLE production_calendar_exceptions');
        $this->addSql('DROP TABLE production_reservations');
        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_operation_plans
                DROP FOREIGN KEY fk_service_order_plan_operator,
                DROP FOREIGN KEY fk_service_order_plan_estimated_by,
                DROP FOREIGN KEY fk_service_order_plan_actual_by,
                DROP INDEX idx_service_order_plan_operator,
                DROP INDEX idx_service_order_plan_estimated_by,
                DROP INDEX idx_service_order_plan_actual_by,
                DROP CHECK chk_service_order_plan_estimate,
                DROP operator_user_id, DROP estimated_load_value, DROP estimated_load_unit,
                DROP estimate_kind, DROP estimated_minutes, DROP manual_estimate_reason,
                DROP estimate_snapshot, DROP estimated_by_user_id, DROP estimated_at,
                DROP actual_minutes, DROP actual_note, DROP actual_by_user_id, DROP actual_at
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE equipment
                DROP FOREIGN KEY fk_equipment_capacity_validator,
                DROP INDEX idx_equipment_capacity_validated,
                DROP INDEX idx_equipment_capacity_validator,
                DROP CHECK chk_equipment_efficiency,
                DROP CHECK chk_equipment_capacity_dates,
                DROP capacity_unit, DROP capacity_rate, DROP setup_minutes, DROP cleanup_minutes,
                DROP efficiency_percent, DROP capacity_valid_from, DROP capacity_valid_until,
                DROP capacity_validated_at, DROP capacity_validated_by_user_id
            SQL);
    }
}
