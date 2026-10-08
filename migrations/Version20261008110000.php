<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Amplía el estado de cotizaciones para permitir ACCEPTED_WITH_CHANGES y sincroniza su restricción.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            $this->connection->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation_statuses'") === false,
            'Primero debe existir el catálogo quotation_statuses de la migración de cotizaciones.',
        );

        $foreignKey = $this->connection->fetchOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations'
               AND CONSTRAINT_NAME = 'fk_quotations_status_catalog' AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
        );
        $statusCheck = $this->connection->fetchOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'quotations'
               AND CONSTRAINT_NAME = 'chk_quotations_status' AND CONSTRAINT_TYPE = 'CHECK'",
        );

        if ($foreignKey !== false) {
            $this->addSql('ALTER TABLE quotations DROP FOREIGN KEY fk_quotations_status_catalog');
        }
        if ($statusCheck !== false) {
            $this->addSql('ALTER TABLE quotations DROP CONSTRAINT chk_quotations_status');
        }

        $this->addSql('ALTER TABLE quotations MODIFY status VARCHAR(30) NOT NULL');
        $this->addSql("INSERT IGNORE INTO quotation_statuses (code, name, display_order, is_terminal, is_active) VALUES ('ACCEPTED_WITH_CHANGES', 'Aceptada con cambios', 70, 1, 1)");
        $this->addSql("ALTER TABLE quotations ADD CONSTRAINT chk_quotations_status CHECK (status IN ('REQUEST','IN_REVIEW','DRAFT','ISSUED','SENT','ACCEPTED','ACCEPTED_WITH_CHANGES','REJECTED','EXPIRED','CANCELLED','SUPERSEDED'))");
        $this->addSql('ALTER TABLE quotations ADD CONSTRAINT fk_quotations_status_catalog FOREIGN KEY (status) REFERENCES quotation_statuses (code) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'No se puede reducir status a 20 caracteres sin perder aceptaciones con cambios.');
    }
}
