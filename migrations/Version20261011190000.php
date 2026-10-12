<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Distingue el conteo inicial de las recepciones ordinarias de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql("ALTER TABLE inventory_receipts ADD receipt_type VARCHAR(20) NOT NULL DEFAULT 'RECEIPT' AFTER command_fingerprint, ADD INDEX idx_inventory_receipt_type_received_at (receipt_type, received_at)");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM inventory_receipts WHERE receipt_type = 'INITIAL_COUNT'") > 0, 'No se puede revertir mientras existan conteos iniciales registrados.');
        $this->addSql('ALTER TABLE inventory_receipts DROP INDEX idx_inventory_receipt_type_received_at, DROP receipt_type');
    }
}
