<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011230000 extends AbstractMigration
{
    public function getDescription(): string { return 'Configura sustituciones de material y registra sustituciones aplicadas a faltantes.'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql("CREATE TABLE material_substitution_rules (id INT AUTO_INCREMENT NOT NULL, original_variant_id INT NOT NULL, substitute_variant_id INT NOT NULL, substitute_per_original NUMERIC(24, 12) NOT NULL, notes LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_substitution_rule_variants (original_variant_id, substitute_variant_id), INDEX IDX_SUBSTITUTION_ORIGINAL (original_variant_id), INDEX IDX_SUBSTITUTION_SUBSTITUTE (substitute_variant_id), PRIMARY KEY(id), CONSTRAINT fk_substitution_original FOREIGN KEY (original_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT, CONSTRAINT fk_substitution_substitute FOREIGN KEY (substitute_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE inventory_commitment_substitutions (id INT AUTO_INCREMENT NOT NULL, original_commitment_id INT NOT NULL, substitute_commitment_id INT NOT NULL, substitution_rule_id INT NOT NULL, authorized_by_user_id INT NOT NULL, original_shortage_quantity NUMERIC(20, 6) NOT NULL, substitute_quantity NUMERIC(20, 6) NOT NULL, reason LONGTEXT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_commitment_substitution_original (original_commitment_id), INDEX IDX_COMMITMENT_SUBSTITUTE (substitute_commitment_id), INDEX IDX_COMMITMENT_SUBSTITUTION_RULE (substitution_rule_id), INDEX IDX_COMMITMENT_SUBSTITUTION_USER (authorized_by_user_id), PRIMARY KEY(id), CONSTRAINT fk_commitment_substitution_original FOREIGN KEY (original_commitment_id) REFERENCES inventory_commitments (id) ON DELETE RESTRICT, CONSTRAINT fk_commitment_substitution_substitute FOREIGN KEY (substitute_commitment_id) REFERENCES inventory_commitments (id) ON DELETE RESTRICT, CONSTRAINT fk_commitment_substitution_rule FOREIGN KEY (substitution_rule_id) REFERENCES material_substitution_rules (id) ON DELETE RESTRICT, CONSTRAINT fk_commitment_substitution_user FOREIGN KEY (authorized_by_user_id) REFERENCES users (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, module, action, name, description, is_system, is_active, created_at, updated_at)
            VALUES ('inventory.substitutions.manage', 'inventory', 'substitutions_manage', 'Configurar y autorizar sustituciones', 'Permite mantener reglas técnicas y aplicar sustitutos a faltantes de material.', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE module = VALUES(module), action = VALUES(action), name = VALUES(name), description = VALUES(description), is_system = 1, is_active = 1, updated_at = UTC_TIMESTAMP()
            SQL);
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id FROM roles
            INNER JOIN permissions ON permissions.code = 'inventory.substitutions.manage'
            WHERE roles.code = 'ROLE_ADMIN'
            SQL);
    }
    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_commitment_substitutions') > 0, 'No se puede revertir mientras existan sustituciones aplicadas.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM material_substitution_rules') > 0, 'No se puede revertir mientras existan reglas de sustitución.');
        $this->addSql("DELETE role_permissions FROM role_permissions INNER JOIN permissions ON permissions.id = role_permissions.permission_id WHERE permissions.code = 'inventory.substitutions.manage'");
        $this->addSql("DELETE FROM permissions WHERE code = 'inventory.substitutions.manage'");
        $this->addSql('DROP TABLE inventory_commitment_substitutions');
        $this->addSql('DROP TABLE material_substitution_rules');
    }
}
