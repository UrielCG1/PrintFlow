<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816154000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega permisos para administrar características comerciales y configurarlas por Producto.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql("INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at) VALUES
            ('catalog.characteristics.manage', 'Catálogo', 'characteristics_manage', 'Administrar características comerciales', 'Crear, editar y activar características y sus opciones controladas.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('catalog.items.configure_characteristics', 'Catálogo', 'items_configure_characteristics', 'Configurar características por Producto', 'Asignar características y opciones permitidas a Productos comerciales.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system), is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()");

        $this->addSql("INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT role.id, permission.id
            FROM roles AS role
            CROSS JOIN permissions AS permission
            WHERE role.code = 'ROLE_ADMIN'
                AND permission.code IN ('catalog.characteristics.manage', 'catalog.items.configure_characteristics')");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql("DELETE role_permissions
            FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            WHERE permissions.code IN ('catalog.characteristics.manage', 'catalog.items.configure_characteristics')");
        $this->addSql("DELETE FROM permissions WHERE code IN ('catalog.characteristics.manage', 'catalog.items.configure_characteristics')");
    }
}
