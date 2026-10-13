<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relaciona el consumo físico de producción con el apartado de inventario que libera.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');

        $this->addSql(<<<'SQL'
            CREATE TABLE inventory_commitment_consumptions (
                id INT AUTO_INCREMENT NOT NULL,
                inventory_commitment_id INT NOT NULL,
                production_material_usage_id INT NOT NULL,
                allocated_quantity NUMERIC(20, 6) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_commitment_consumption_usage (production_material_usage_id),
                INDEX idx_commitment_consumption_commitment (inventory_commitment_id, created_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_commitment_consumption_commitment FOREIGN KEY (inventory_commitment_id) REFERENCES inventory_commitments (id) ON DELETE RESTRICT,
                CONSTRAINT fk_commitment_consumption_usage FOREIGN KEY (production_material_usage_id) REFERENCES production_material_usages (id) ON DELETE RESTRICT,
                CONSTRAINT chk_commitment_consumption_quantity CHECK (allocated_quantity > 0)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_commitment_consumptions') > 0, 'No se puede revertir mientras existan consumos aplicados a apartados.');
        $this->addSql('DROP TABLE inventory_commitment_consumptions');
    }
}
