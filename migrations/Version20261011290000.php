<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011290000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vincula solicitudes de compra con las demandas de órdenes que estaban en espera de material.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_purchase_request_line_demands (
                id INT AUTO_INCREMENT NOT NULL,
                purchase_request_line_id INT NOT NULL,
                inventory_commitment_id INT NOT NULL,
                planned_quantity NUMERIC(20, 6) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_purchase_request_line_demand (purchase_request_line_id, inventory_commitment_id),
                INDEX idx_purchase_request_demand_commitment (inventory_commitment_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_purchase_request_demand_line FOREIGN KEY (purchase_request_line_id) REFERENCES inventory_purchase_request_lines (id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchase_request_demand_commitment FOREIGN KEY (inventory_commitment_id) REFERENCES inventory_commitments (id) ON DELETE RESTRICT,
                CONSTRAINT chk_purchase_request_demand_quantity CHECK (planned_quantity > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_purchase_request_line_demands') > 0, 'No se puede revertir mientras existan demandas de compra vinculadas.');
        $this->addSql('DROP TABLE inventory_purchase_request_line_demands');
    }
}
