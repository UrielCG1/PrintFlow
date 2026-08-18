<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega perfil y snapshots de especificaciones técnicas para las partidas del cotizador interno.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql("ALTER TABLE commercial_items ADD quotation_specification_profile VARCHAR(30) NOT NULL DEFAULT 'NONE' AFTER type");

        $this->addSql('ALTER TABLE quotation_items ADD specifications_snapshot JSON DEFAULT NULL AFTER price_rule_snapshot, ADD specification_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER specifications_snapshot');
        $this->addSql('UPDATE quotation_items SET specifications_snapshot = JSON_OBJECT() WHERE specifications_snapshot IS NULL');
        $this->addSql('ALTER TABLE quotation_items MODIFY specifications_snapshot JSON NOT NULL');

        $this->addSql('ALTER TABLE service_order_items ADD specifications_snapshot JSON DEFAULT NULL AFTER price_rule_snapshot, ADD specification_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER specifications_snapshot');
        $this->addSql('UPDATE service_order_items SET specifications_snapshot = JSON_OBJECT() WHERE specifications_snapshot IS NULL');
        $this->addSql('ALTER TABLE service_order_items MODIFY specifications_snapshot JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('ALTER TABLE service_order_items DROP specifications_snapshot, DROP specification_schema_version');
        $this->addSql('ALTER TABLE quotation_items DROP specifications_snapshot, DROP specification_schema_version');
        $this->addSql('ALTER TABLE commercial_items DROP quotation_specification_profile');
    }
}
