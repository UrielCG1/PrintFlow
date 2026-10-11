<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Separa importes comerciales y habilita lectura operativa para operadores.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            INSERT INTO permissions (
                code, module, action, name, description, is_system, is_active, created_at, updated_at
            ) VALUES (
                'service_orders.view_commercial',
                'service_orders',
                'view_commercial',
                'Consultar importes comerciales de órdenes',
                'Permite consultar precios y totales comerciales en una orden de servicio.',
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
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            CROSS JOIN permissions
            WHERE roles.code IN ('ROLE_ADMIN', 'ROLE_SALES')
              AND permissions.code = 'service_orders.view_commercial'
            SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles
            CROSS JOIN permissions
            WHERE roles.code = 'ROLE_OPERATOR'
              AND permissions.code = 'service_orders.view'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            DELETE role_permissions
            FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            INNER JOIN roles ON roles.id = role_permissions.role_id
            WHERE permissions.code = 'service_orders.view_commercial'
              AND roles.code IN ('ROLE_ADMIN', 'ROLE_SALES')
            SQL);

        $this->addSql(<<<'SQL'
            DELETE role_permissions
            FROM role_permissions
            INNER JOIN permissions ON permissions.id = role_permissions.permission_id
            INNER JOIN roles ON roles.id = role_permissions.role_id
            WHERE permissions.code = 'service_orders.view'
              AND roles.code = 'ROLE_OPERATOR'
            SQL);

        $this->addSql("DELETE FROM permissions WHERE code = 'service_orders.view_commercial'");
    }
}
