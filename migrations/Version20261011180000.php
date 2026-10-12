<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autoriza la consulta, recepción e impresión de etiquetas del inventario para Administración y Producción.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES
                ('inventory.view', 'inventory', 'view', 'Consultar inventario', 'Permite consultar la operación de inventario.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('inventory.receive', 'inventory', 'receive', 'Registrar recepciones de material', 'Permite registrar material físico que ingresa a bodega.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('inventory.labels.print', 'inventory', 'labels_print', 'Imprimir etiquetas de material', 'Permite generar e imprimir etiquetas QR para las unidades físicas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            INNER JOIN permissions ON permissions.code IN ('inventory.view', 'inventory.receive', 'inventory.labels.print')
            WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_PRODUCTION')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql(<<<'SQL'
            DELETE role_permissions FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code IN ('inventory.view', 'inventory.receive', 'inventory.labels.print')
            SQL);
        $this->addSql("DELETE FROM permissions WHERE code IN ('inventory.view', 'inventory.receive', 'inventory.labels.print')");
    }
}
