<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega la secuencia anual de folios y permisos para emitir y descargar cotizaciones.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                CREATE TABLE quotation_folio_sequences (
                    folio_year SMALLINT UNSIGNED NOT NULL,
                    last_number INT UNSIGNED NOT NULL,
                    PRIMARY KEY (folio_year)
                ) DEFAULT CHARACTER SET utf8mb4
                COLLATE `utf8mb4_unicode_ci`
                ENGINE = InnoDB
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                INSERT INTO permissions (
                    code,
                    module,
                    action,
                    name,
                    description,
                    is_system,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES
                    (
                        'quotations.issue',
                        'quotations',
                        'issue',
                        'Emitir cotizaciones',
                        'Permite asignar el folio definitivo y emitir cotizaciones.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
                    ),
                    (
                        'quotations.download_pdf',
                        'quotations',
                        'download_pdf',
                        'Descargar PDF de cotizaciones',
                        'Permite descargar el PDF de cotizaciones emitidas.',
                        1,
                        1,
                        UTC_TIMESTAMP(),
                        UTC_TIMESTAMP()
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
                INSERT IGNORE INTO role_permissions (
                    role_id,
                    permission_id
                )
                SELECT
                    roles.id,
                    permissions.id
                FROM roles
                INNER JOIN permissions
                    ON permissions.code IN (
                        'quotations.issue',
                        'quotations.download_pdf'
                    )
                WHERE roles.code = 'ROLE_ADMIN'
                SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL.',
        );

        $this->addSql(
            <<<'SQL'
                DELETE role_permissions
                FROM role_permissions
                INNER JOIN permissions
                    ON permissions.id = role_permissions.permission_id
                WHERE permissions.code IN (
                    'quotations.issue',
                    'quotations.download_pdf'
                )
                SQL,
        );

        $this->addSql(
            <<<'SQL'
                DELETE FROM permissions
                WHERE code IN (
                    'quotations.issue',
                    'quotations.download_pdf'
                )
                SQL,
        );

        $this->addSql('DROP TABLE quotation_folio_sequences');
    }
}