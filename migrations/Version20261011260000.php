<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserva las cantidades consumidas y liberadas de cada apartado de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_commitments
                ADD consumed_quantity NUMERIC(20, 6) NOT NULL DEFAULT 0.000000 AFTER committed_quantity,
                ADD released_quantity NUMERIC(20, 6) NOT NULL DEFAULT 0.000000 AFTER consumed_quantity
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE inventory_commitments commitment
            LEFT JOIN (
                SELECT inventory_commitment_id, SUM(allocated_quantity) AS allocated_quantity
                FROM inventory_commitment_consumptions
                GROUP BY inventory_commitment_id
            ) applied ON applied.inventory_commitment_id = commitment.id
            SET commitment.consumed_quantity = COALESCE(applied.allocated_quantity, 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_commitments WHERE consumed_quantity <> 0 OR released_quantity <> 0') > 0, 'No se puede revertir mientras existan apartados conciliados.');
        $this->addSql('ALTER TABLE inventory_commitments DROP consumed_quantity, DROP released_quantity');
    }
}
