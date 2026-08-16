<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alinea la relación uno a uno entre consumo productivo y movimiento de inventario.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(
            'ALTER TABLE production_material_usages
             ADD UNIQUE INDEX UNIQ_552BBD77566C4C68 (inventory_movement_id)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE production_material_usages
             DROP INDEX UNIQ_552BBD77566C4C68',
        );
    }
}
