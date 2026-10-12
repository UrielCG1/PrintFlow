<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega estándares de tiempo opcionales por paso en las rutas publicadas de Productos.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql(<<<'SQL'
            ALTER TABLE commercial_item_route_steps
                ADD standard_base_minutes INT UNSIGNED DEFAULT NULL,
                ADD standard_minutes_per_unit DECIMAL(10, 4) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE commercial_item_route_steps
                DROP standard_base_minutes,
                DROP standard_minutes_per_unit
            SQL);
    }
}
