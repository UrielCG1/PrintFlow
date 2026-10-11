<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega rutas base reutilizables, versiones por producto y snapshots de ruta por partida.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE production_route_templates (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description TEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_production_route_templates_code (code),
                INDEX idx_production_route_templates_active_name (is_active, name),
                INDEX idx_production_route_templates_created_by (created_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_production_route_templates_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE production_route_template_steps (
                id INT AUTO_INCREMENT NOT NULL,
                template_id INT NOT NULL,
                operation_id INT NOT NULL,
                sequence_number INT UNSIGNED NOT NULL,
                condition_code VARCHAR(32) NOT NULL DEFAULT 'ALWAYS',
                condition_config JSON DEFAULT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE INDEX uniq_production_route_template_step_sequence (template_id, sequence_number),
                UNIQUE INDEX uniq_production_route_template_step_operation (template_id, operation_id),
                INDEX idx_production_route_template_step_operation (operation_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_production_route_template_step_template FOREIGN KEY (template_id) REFERENCES production_route_templates (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_route_template_step_operation FOREIGN KEY (operation_id) REFERENCES operations (id) ON DELETE RESTRICT,
                CONSTRAINT chk_production_route_template_step_sequence CHECK (sequence_number > 0),
                CONSTRAINT chk_production_route_template_step_condition CHECK (condition_code IN ('ALWAYS', 'CLIENT_FILE_PRESENT', 'CLIENT_FILE_MISSING', 'SPECIFICATION_EQUALS'))
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE commercial_item_route_versions (
                id INT AUTO_INCREMENT NOT NULL,
                commercial_item_id INT NOT NULL,
                source_template_id INT DEFAULT NULL,
                version_number INT UNSIGNED NOT NULL,
                name_snapshot VARCHAR(120) NOT NULL,
                definition_snapshot JSON NOT NULL,
                published_by_user_id INT NOT NULL,
                published_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_commercial_item_route_version_number (commercial_item_id, version_number),
                INDEX idx_commercial_item_route_version_source_template (source_template_id),
                INDEX idx_commercial_item_route_version_published_by (published_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_commercial_item_route_version_item FOREIGN KEY (commercial_item_id) REFERENCES commercial_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_commercial_item_route_version_template FOREIGN KEY (source_template_id) REFERENCES production_route_templates (id) ON DELETE RESTRICT,
                CONSTRAINT fk_commercial_item_route_version_published_by FOREIGN KEY (published_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_commercial_item_route_version_number CHECK (version_number > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE commercial_item_route_steps (
                id INT AUTO_INCREMENT NOT NULL,
                route_version_id INT NOT NULL,
                operation_id INT NOT NULL,
                sequence_number INT UNSIGNED NOT NULL,
                condition_code VARCHAR(32) NOT NULL DEFAULT 'ALWAYS',
                condition_config JSON DEFAULT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                operation_snapshot JSON NOT NULL,
                UNIQUE INDEX uniq_commercial_item_route_step_sequence (route_version_id, sequence_number),
                UNIQUE INDEX uniq_commercial_item_route_step_operation (route_version_id, operation_id),
                INDEX idx_commercial_item_route_step_operation (operation_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_commercial_item_route_step_version FOREIGN KEY (route_version_id) REFERENCES commercial_item_route_versions (id) ON DELETE RESTRICT,
                CONSTRAINT fk_commercial_item_route_step_operation FOREIGN KEY (operation_id) REFERENCES operations (id) ON DELETE RESTRICT,
                CONSTRAINT chk_commercial_item_route_step_sequence CHECK (sequence_number > 0),
                CONSTRAINT chk_commercial_item_route_step_condition CHECK (condition_code IN ('ALWAYS', 'CLIENT_FILE_PRESENT', 'CLIENT_FILE_MISSING', 'SPECIFICATION_EQUALS'))
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE commercial_items ADD current_route_version_id INT DEFAULT NULL, ADD INDEX idx_commercial_items_current_route_version (current_route_version_id)');
        $this->addSql('ALTER TABLE commercial_items ADD CONSTRAINT fk_commercial_items_current_route_version FOREIGN KEY (current_route_version_id) REFERENCES commercial_item_route_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_order_items ADD route_version_id INT DEFAULT NULL, ADD route_snapshot JSON DEFAULT NULL, ADD planning_issue VARCHAR(255) DEFAULT NULL, ADD INDEX idx_service_order_items_route_version (route_version_id)');
        $this->addSql('ALTER TABLE service_order_items ADD CONSTRAINT fk_service_order_items_route_version FOREIGN KEY (route_version_id) REFERENCES commercial_item_route_versions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_order_operation_plans ADD route_step_snapshot JSON DEFAULT NULL, ADD condition_applied TINYINT(1) DEFAULT NULL, ADD blocked_reason VARCHAR(255) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('production_routes.manage', 'production_routes', 'manage', 'Configurar rutas de producción', 'Crear rutas reutilizables, publicar versiones por Producto y ajustar su configuración.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system), is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id FROM roles
            INNER JOIN permissions ON permissions.code = 'production_routes.manage'
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
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_order_items WHERE route_version_id IS NOT NULL') > 0,
            'No se puede revertir mientras existan trabajos que conserven snapshots de rutas.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM commercial_item_route_versions') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_route_templates') > 0,
            'No se puede revertir mientras existan rutas configuradas o publicadas.',
        );

        $this->addSql('ALTER TABLE service_order_operation_plans DROP blocked_reason, DROP condition_applied, DROP route_step_snapshot');
        $this->addSql('ALTER TABLE service_order_items DROP CONSTRAINT fk_service_order_items_route_version');
        $this->addSql('ALTER TABLE service_order_items DROP INDEX idx_service_order_items_route_version');
        $this->addSql('ALTER TABLE service_order_items DROP route_version_id, DROP route_snapshot, DROP planning_issue');
        $this->addSql('ALTER TABLE commercial_items DROP CONSTRAINT fk_commercial_items_current_route_version');
        $this->addSql('ALTER TABLE commercial_items DROP INDEX idx_commercial_items_current_route_version');
        $this->addSql('ALTER TABLE commercial_items DROP current_route_version_id');
        $this->addSql('DROP TABLE commercial_item_route_steps');
        $this->addSql('DROP TABLE commercial_item_route_versions');
        $this->addSql('DROP TABLE production_route_template_steps');
        $this->addSql('DROP TABLE production_route_templates');
        $this->addSql("DELETE role_permissions FROM role_permissions INNER JOIN permissions ON permissions.id = role_permissions.permission_id WHERE permissions.code = 'production_routes.manage'");
        $this->addSql("DELETE FROM permissions WHERE code = 'production_routes.manage'");
    }
}
