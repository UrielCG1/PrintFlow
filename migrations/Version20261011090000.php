<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega la liberación trazable de Diseño, archivos privados y el rol Diseño.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE service_order_items
                ADD design_status VARCHAR(30) NOT NULL DEFAULT 'PENDING_REVIEW',
                ADD INDEX idx_service_order_items_design_status (design_status)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE production_files (
                id INT AUTO_INCREMENT NOT NULL,
                service_order_item_id INT NOT NULL,
                created_by_user_id INT DEFAULT NULL,
                purpose VARCHAR(30) NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                internal_path VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                byte_size INT UNSIGNED NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                source VARCHAR(30) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_production_file_item_purpose_version (service_order_item_id, purpose, version_number),
                INDEX idx_production_file_item_purpose_created (service_order_item_id, purpose, created_at),
                INDEX idx_production_file_sha256 (sha256),
                INDEX idx_production_file_created_by (created_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_production_file_item FOREIGN KEY (service_order_item_id) REFERENCES service_order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_file_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_production_file_version CHECK (version_number > 0),
                CONSTRAINT chk_production_file_size CHECK (byte_size > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE service_order_item_design_releases (
                id INT AUTO_INCREMENT NOT NULL,
                service_order_item_id INT NOT NULL,
                artwork_file_id INT NOT NULL,
                reference_file_id INT NOT NULL,
                authorized_by_user_id INT NOT NULL,
                authorization_type VARCHAR(30) NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                instructions LONGTEXT DEFAULT NULL,
                authorized_at DATETIME NOT NULL,
                current_marker INT UNSIGNED DEFAULT 1,
                superseded_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_design_release_item_version (service_order_item_id, version_number),
                UNIQUE INDEX uniq_design_release_item_current (service_order_item_id, current_marker),
                INDEX idx_design_release_artwork (artwork_file_id),
                INDEX idx_design_release_reference (reference_file_id),
                INDEX idx_design_release_authorized_by (authorized_by_user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_design_release_item FOREIGN KEY (service_order_item_id) REFERENCES service_order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_design_release_artwork FOREIGN KEY (artwork_file_id) REFERENCES production_files (id) ON DELETE RESTRICT,
                CONSTRAINT fk_design_release_reference FOREIGN KEY (reference_file_id) REFERENCES production_files (id) ON DELETE RESTRICT,
                CONSTRAINT fk_design_release_authorized_by FOREIGN KEY (authorized_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT chk_design_release_version CHECK (version_number > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO roles (code, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('ROLE_DESIGN', 'Diseño', 'Revisión y autorización interna de archivos por partida.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('production.design.review', 'production', 'design_review', 'Revisar y liberar Diseño', 'Permite validar el arte por partida y liberar el archivo autorizado con su captura de referencia.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN ('production.design.review')
            WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN (
                'dashboard.view', 'service_orders.view', 'production.view',
                'production.design.review', 'production.files.upload', 'production.files.download'
            )
            WHERE roles.code = 'ROLE_DESIGN'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM production_files') > 0
                || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_order_item_design_releases') > 0,
            'No se puede revertir mientras existan archivos o liberaciones de Diseño.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM user_roles
                INNER JOIN roles ON roles.id = user_roles.role_id
                WHERE roles.code = 'ROLE_DESIGN'
                SQL) > 0,
            'No se puede revertir mientras el rol Diseño esté asignado a usuarios.',
        );

        $this->addSql(<<<'SQL'
            DELETE role_permissions FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code = 'production.design.review'
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code = 'production.design.review'");
        $this->addSql("DELETE FROM roles WHERE code = 'ROLE_DESIGN'");
        $this->addSql('DROP TABLE service_order_item_design_releases');
        $this->addSql('DROP TABLE production_files');
        $this->addSql('ALTER TABLE service_order_items DROP INDEX idx_service_order_items_design_status, DROP design_status');
    }
}
