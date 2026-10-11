<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserva medidas técnicas corregidas y permite dividir partidas sin duplicar su vínculo de cotización.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql('ALTER TABLE service_order_items ADD production_specifications_snapshot JSON DEFAULT NULL, ADD split_parent_item_id INT DEFAULT NULL, ADD split_position INT UNSIGNED DEFAULT NULL');
        $this->addSql('UPDATE service_order_items SET production_specifications_snapshot = specifications_snapshot WHERE production_specifications_snapshot IS NULL');
        $this->addSql('ALTER TABLE service_order_items MODIFY production_specifications_snapshot JSON NOT NULL, MODIFY source_quotation_item_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_order_items ADD INDEX idx_service_order_items_split_parent_position (split_parent_item_id, split_position)');
        $this->addSql('ALTER TABLE service_order_items ADD CONSTRAINT fk_service_order_items_split_parent FOREIGN KEY (split_parent_item_id) REFERENCES service_order_items (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_order_items ADD CONSTRAINT chk_service_order_items_split_position CHECK (split_position IS NULL OR split_position > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_order_items WHERE split_parent_item_id IS NOT NULL OR production_specifications_snapshot <> specifications_snapshot') > 0,
            'No se puede revertir mientras existan partidas divididas o medidas técnicas corregidas.',
        );

        $this->addSql('ALTER TABLE service_order_items DROP FOREIGN KEY fk_service_order_items_split_parent');
        $this->addSql('ALTER TABLE service_order_items DROP INDEX idx_service_order_items_split_parent_position');
        $this->addSql('ALTER TABLE service_order_items DROP CHECK chk_service_order_items_split_position');
        $this->addSql('ALTER TABLE service_order_items MODIFY source_quotation_item_id INT NOT NULL');
        $this->addSql('ALTER TABLE service_order_items DROP split_parent_item_id, DROP split_position, DROP production_specifications_snapshot');
    }
}
