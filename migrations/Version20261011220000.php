<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Registra decisiones administrativas sobre faltantes de inventario.'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql("CREATE TABLE inventory_shortage_decisions (id INT AUTO_INCREMENT NOT NULL, inventory_commitment_id INT NOT NULL, decided_by_user_id INT NOT NULL, type VARCHAR(40) NOT NULL, reason LONGTEXT NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, superseded_at DATETIME DEFAULT NULL, INDEX idx_shortage_decision_commitment_active (inventory_commitment_id, is_active), INDEX IDX_SHORTAGE_DECISION_USER (decided_by_user_id), PRIMARY KEY(id), CONSTRAINT fk_shortage_decision_commitment FOREIGN KEY (inventory_commitment_id) REFERENCES inventory_commitments (id) ON DELETE RESTRICT, CONSTRAINT fk_shortage_decision_user FOREIGN KEY (decided_by_user_id) REFERENCES users (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('inventory.shortage.approve', 'inventory', 'shortage_approve', 'Resolver faltantes de inventario', 'Permite decidir espera de compra o autorizar producción parcial ante faltantes.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id FROM roles
            INNER JOIN permissions ON permissions.code = 'inventory.shortage.approve'
            WHERE roles.code = 'ROLE_ADMIN'
            SQL);
    }
    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_shortage_decisions') > 0, 'No se puede revertir mientras existan decisiones de faltante.');
        $this->addSql("DELETE role_permissions FROM role_permissions INNER JOIN permissions ON permissions.id = role_permissions.permission_id WHERE permissions.code = 'inventory.shortage.approve'");
        $this->addSql("DELETE FROM permissions WHERE code = 'inventory.shortage.approve'");
        $this->addSql('DROP TABLE inventory_shortage_decisions');
    }
}
