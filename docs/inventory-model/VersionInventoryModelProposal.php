<?php

/**
 * Propuesta, deliberadamente fuera de migrations/.
 * Doctrine DBAL no ejecuta de forma portable un archivo MySQL con DELIMITER;
 * al aprobarse, schema.sql debe dividirse en addSql() (una sentencia por llamada)
 * y el trigger se crea con una sola sentencia CREATE TRIGGER.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionInventoryModelProposal extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Target inventory, costing and production model (approval required)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This proposal is designed for MySQL 8.',
        );

        throw new \LogicException(
            'Approval gate: split docs/inventory-model/schema.sql into addSql() calls after business decisions and legacy mapping are approved.',
        );
    }

    public function down(Schema $schema): void
    {
        throw new \LogicException(
            'No automatic destructive rollback. Restore the pre-cutover backup or use a reviewed reverse migration.',
        );
    }
}