<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Declara como UTC las fechas existentes de los intentos de producción; no modifica sus valores.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql(<<<'SQL'
            ALTER TABLE production_operation_executions
                MODIFY started_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                MODIFY active_segment_started_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                MODIFY paused_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                MODIFY completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                MODIFY created_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)',
                MODIFY updated_at DATETIME NOT NULL COMMENT '(DC2Type:utc_datetime_immutable)'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Las fechas de ejecución deben conservar su interpretación UTC.');
    }
}
