<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Asigna la confirmación de entrega a Ventas y sincroniza los permisos existentes del catálogo de equipos para Administración.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id FROM roles
            INNER JOIN permissions ON permissions.code = 'delivery.confirm'
            WHERE roles.code = 'ROLE_SALES'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE rp FROM role_permissions rp
            INNER JOIN roles ON roles.id = rp.role_id
            INNER JOIN permissions ON permissions.id = rp.permission_id
            WHERE roles.code = 'ROLE_QUALITY_DELIVERY' AND permissions.code = 'delivery.confirm'
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES
                ('equipment.create', 'equipment', 'create', 'Crear equipos', 'Permite registrar equipos operativos.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('equipment.update', 'equipment', 'update', 'Editar equipos', 'Permite actualizar la ficha técnica de equipos.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
                ('equipment.change_status', 'equipment', 'change_status', 'Cambiar estado de equipos', 'Permite cambiar disponibilidad de equipos.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id FROM roles
            INNER JOIN permissions ON permissions.code IN ('equipment.create', 'equipment.update', 'equipment.change_status')
            WHERE roles.code = 'ROLE_ADMIN'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La confirmación de entrega debe conservar su autorización por Ventas.');
    }
}
