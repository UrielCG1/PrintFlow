<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719164200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the initial PrintFlow RBAC and audit schema.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_logs (
              id INT AUTO_INCREMENT NOT NULL,
              action VARCHAR(120) NOT NULL,
              entity_type VARCHAR(120) NOT NULL,
              entity_id VARCHAR(64) DEFAULT NULL,
              old_values JSON DEFAULT NULL,
              new_values JSON DEFAULT NULL,
              ip_address VARCHAR(45) DEFAULT NULL,
              user_agent VARCHAR(512) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              actor_id INT DEFAULT NULL,
              INDEX IDX_D62F285810DAF24A (actor_id),
              INDEX IDX_AUDIT_LOGS_ENTITY (entity_type, entity_id),
              INDEX IDX_AUDIT_LOGS_CREATED_AT (created_at),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permissions (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(120) NOT NULL,
              module VARCHAR(60) NOT NULL,
              action VARCHAR(60) NOT NULL,
              name VARCHAR(120) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              is_system TINYINT NOT NULL,
              is_active TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX UNIQ_PERMISSIONS_CODE (code),
              UNIQUE INDEX UNIQ_PERMISSIONS_MODULE_ACTION (module, action),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE roles (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(80) NOT NULL,
              name VARCHAR(100) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              is_system TINYINT NOT NULL,
              is_active TINYINT NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              UNIQUE INDEX UNIQ_ROLES_CODE (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE role_permissions (
              role_id INT NOT NULL,
              permission_id INT NOT NULL,
              INDEX IDX_1FBA94E6D60322AC (role_id),
              INDEX IDX_1FBA94E6FED90CCA (permission_id),
              PRIMARY KEY (role_id, permission_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
              id INT AUTO_INCREMENT NOT NULL,
              full_name VARCHAR(160) NOT NULL,
              username VARCHAR(60) NOT NULL,
              email VARCHAR(180) NOT NULL,
              password VARCHAR(255) NOT NULL,
              phone VARCHAR(30) DEFAULT NULL,
              avatar_path VARCHAR(255) DEFAULT NULL,
              is_active TINYINT NOT NULL,
              must_change_password TINYINT NOT NULL,
              last_login_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              deleted_at DATETIME DEFAULT NULL,
              UNIQUE INDEX UNIQ_USERS_USERNAME (username),
              UNIQUE INDEX UNIQ_USERS_EMAIL (email),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE user_roles (
              user_id INT NOT NULL,
              role_id INT NOT NULL,
              INDEX IDX_54FCD59FA76ED395 (user_id),
              INDEX IDX_54FCD59FD60322AC (role_id),
              PRIMARY KEY (user_id, role_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              audit_logs
            ADD
              CONSTRAINT FK_D62F285810DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              role_permissions
            ADD
              CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              role_permissions
            ADD
              CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user_roles
            ADD
              CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user_roles
            ADD
              CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_D62F285810DAF24A');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6D60322AC');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6FED90CCA');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FD60322AC');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE user_roles');
    }
}
