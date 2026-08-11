<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Completa el ciclo comercial de cotizaciones: revisiones, envíos SMTP con evidencia y decisiones comerciales.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(
            <<<'SQL'
                ALTER TABLE quotations
                    ADD previous_revision_id INT DEFAULT NULL,
                    ADD revision_number INT UNSIGNED NOT NULL DEFAULT 1,
                    ADD decision_channel VARCHAR(20) DEFAULT NULL,
                    ADD decision_contact VARCHAR(160) DEFAULT NULL,
                    ADD decision_at DATETIME DEFAULT NULL,
                    ADD decision_notes LONGTEXT DEFAULT NULL,
                    ADD decision_evidence_reference VARCHAR(500) DEFAULT NULL,
                    ADD UNIQUE INDEX uniq_quotations_previous_revision (previous_revision_id),
                    ADD CONSTRAINT fk_quotations_previous_revision
                        FOREIGN KEY (previous_revision_id) REFERENCES quotations (id) ON DELETE RESTRICT
                SQL,
        );

        $this->addSql('ALTER TABLE quotations DROP CONSTRAINT chk_quotations_status');
        $this->addSql(
            <<<'SQL'
                ALTER TABLE quotations
                    ADD CONSTRAINT chk_quotations_status CHECK (
                        status IN (
                            'DRAFT',
                            'ISSUED',
                            'SENT',
                            'ACCEPTED',
                            'REJECTED',
                            'EXPIRED',
                            'CANCELLED',
                            'SUPERSEDED'
                        )
                    )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE quotation_email_dispatches (
                    id INT AUTO_INCREMENT NOT NULL,
                    quotation_id INT NOT NULL,
                    sent_by_user_id INT NOT NULL,
                    recipient_email VARCHAR(180) NOT NULL,
                    recipient_name VARCHAR(160) DEFAULT NULL,
                    copy_email VARCHAR(180) DEFAULT NULL,
                    subject VARCHAR(200) NOT NULL,
                    message_note LONGTEXT DEFAULT NULL,
                    message_id VARCHAR(255) DEFAULT NULL,
                    sent_at DATETIME NOT NULL,
                    INDEX idx_quotation_email_dispatches_quotation_sent_at (quotation_id, sent_at),
                    INDEX idx_quotation_email_dispatches_actor (sent_by_user_id),
                    PRIMARY KEY (id),
                    CONSTRAINT fk_quotation_email_dispatches_quotation
                        FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE RESTRICT,
                    CONSTRAINT fk_quotation_email_dispatches_sent_by_user
                        FOREIGN KEY (sent_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO permissions (
                    code, module, action, name, description, is_system, is_active, created_at, updated_at
                ) VALUES
                    (
                        'quotations.send',
                        'Cotizaciones',
                        'send',
                        'Enviar cotizaciones por correo',
                        'Permite enviar una cotización emitida con el PDF adjunto y registrar la evidencia SMTP.',
                        1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                    ),
                    (
                        'quotations.manage_status',
                        'Cotizaciones',
                        'manage_status',
                        'Registrar respuesta comercial',
                        'Permite registrar aceptación, rechazo o cancelación de una cotización emitida.',
                        1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                    ),
                    (
                        'quotations.create_revision',
                        'Cotizaciones',
                        'create_revision',
                        'Crear revisiones de cotizaciones',
                        'Permite generar una nueva revisión enlazada a una cotización histórica sin alterar su contenido.',
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
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT roles.id, permissions.id
                FROM roles
                INNER JOIN permissions
                    ON permissions.code IN (
                        'quotations.send',
                        'quotations.manage_status',
                        'quotations.create_revision'
                    )
                WHERE roles.code = 'ROLE_ADMIN'
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM quotations WHERE status = 'SUPERSEDED'") > 0,
            'No se puede revertir mientras existan cotizaciones reemplazadas por una revisión.',
        );

        $this->addSql('DROP TABLE quotation_email_dispatches');
        $this->addSql('ALTER TABLE quotations DROP CONSTRAINT fk_quotations_previous_revision');
        $this->addSql('ALTER TABLE quotations DROP INDEX uniq_quotations_previous_revision');
        $this->addSql(
            'ALTER TABLE quotations
                DROP previous_revision_id,
                DROP revision_number,
                DROP decision_channel,
                DROP decision_contact,
                DROP decision_at,
                DROP decision_notes,
                DROP decision_evidence_reference',
        );

        $this->addSql('ALTER TABLE quotations DROP CONSTRAINT chk_quotations_status');
        $this->addSql(
            <<<'SQL'
                ALTER TABLE quotations
                    ADD CONSTRAINT chk_quotations_status CHECK (
                        status IN ('DRAFT', 'ISSUED', 'SENT', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'CANCELLED')
                    )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions ON permissions.id = role_permissions.permission_id
                WHERE permissions.code = 'quotations.create_revision'
                SQL,
        );
        $this->addSql("DELETE FROM permissions WHERE code = 'quotations.create_revision'");
    }
}
