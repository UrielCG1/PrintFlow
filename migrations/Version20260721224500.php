<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721224500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garantiza un único contacto principal activo por cliente.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $duplicates = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM (
                 SELECT client_id
                 FROM client_contacts
                 WHERE is_active = 1 AND is_primary = 1
                 GROUP BY client_id
                 HAVING COUNT(*) > 1
             ) AS duplicate_clients'
        );

        $this->abortIf(
            $duplicates > 0,
            'Existen clientes con más de un contacto principal activo. Corrige esos registros antes de continuar.'
        );

        $this->addSql(
            'ALTER TABLE client_contacts
                ADD primary_client_id INT GENERATED ALWAYS AS (
                    CASE
                        WHEN is_active = 1 AND is_primary = 1 THEN client_id
                        ELSE NULL
                    END
                ) STORED,
                ADD UNIQUE INDEX uniq_client_contacts_primary_active (primary_client_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.'
        );

        $this->addSql(
            'ALTER TABLE client_contacts
                DROP INDEX uniq_client_contacts_primary_active,
                DROP COLUMN primary_client_id'
        );
    }
}