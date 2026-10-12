<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autoriza a Administración a configurar mínimos y reabasto por variante de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('inventory.configure', 'inventory', 'configure', 'Configurar mínimos de inventario', 'Permite configurar mínimos y reabasto de las variantes de material.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id FROM roles
            INNER JOIN permissions ON permissions.code = 'inventory.configure'
            WHERE roles.code = 'ROLE_ADMIN'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql("DELETE role_permissions FROM role_permissions INNER JOIN permissions ON permissions.id = role_permissions.permission_id WHERE permissions.code = 'inventory.configure'");
        $this->addSql("DELETE FROM permissions WHERE code = 'inventory.configure'");
    }
}
