<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Permite conciliar apartados de inventario reducidos o retirados.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql('ALTER TABLE inventory_commitments DROP CHECK chk_commitment_quantities');
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_commitments
                ADD CONSTRAINT chk_commitment_quantities CHECK (
                    requested_quantity >= 0
                    AND committed_quantity >= 0
                    AND consumed_quantity >= 0
                    AND released_quantity >= 0
                    AND shortage_quantity >= 0
                    AND consumed_quantity + released_quantity <= committed_quantity
                    AND committed_quantity + shortage_quantity >= requested_quantity
                    AND committed_quantity + shortage_quantity <= requested_quantity + released_quantity
                )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*)
                FROM inventory_commitments
                WHERE requested_quantity <= 0
                   OR committed_quantity < 0
                   OR shortage_quantity < 0
                   OR committed_quantity + shortage_quantity <> requested_quantity
                SQL) > 0,
            'No se puede revertir mientras existan apartados conciliados que incumplan la restricción anterior.',
        );

        $this->addSql('ALTER TABLE inventory_commitments DROP CHECK chk_commitment_quantities');
        $this->addSql(<<<'SQL'
            ALTER TABLE inventory_commitments
                ADD CONSTRAINT chk_commitment_quantities CHECK (
                    requested_quantity > 0
                    AND committed_quantity >= 0
                    AND shortage_quantity >= 0
                    AND committed_quantity + shortage_quantity = requested_quantity
                )
            SQL);
    }
}
