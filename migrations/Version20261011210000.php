<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Registra apartados de material y faltantes por orden de servicio.'; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->addSql("CREATE TABLE inventory_commitments (id INT AUTO_INCREMENT NOT NULL, service_order_id INT NOT NULL, material_variant_id INT NOT NULL, created_by_user_id INT NOT NULL, requested_quantity NUMERIC(20, 6) NOT NULL, committed_quantity NUMERIC(20, 6) NOT NULL, shortage_quantity NUMERIC(20, 6) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_inventory_commitment_order_variant (service_order_id, material_variant_id), INDEX idx_inventory_commitment_status (status, created_at), INDEX IDX_COMMITMENT_ORDER (service_order_id), INDEX IDX_COMMITMENT_VARIANT (material_variant_id), INDEX IDX_COMMITMENT_USER (created_by_user_id), PRIMARY KEY(id), CONSTRAINT fk_commitment_order FOREIGN KEY (service_order_id) REFERENCES service_orders (id) ON DELETE RESTRICT, CONSTRAINT fk_commitment_variant FOREIGN KEY (material_variant_id) REFERENCES material_variants (id) ON DELETE RESTRICT, CONSTRAINT fk_commitment_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT, CONSTRAINT chk_commitment_quantities CHECK (requested_quantity > 0 AND committed_quantity >= 0 AND shortage_quantity >= 0 AND committed_quantity + shortage_quantity = requested_quantity)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }
    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración requiere MySQL o MariaDB.');
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_commitments') > 0, 'No se puede revertir mientras existan apartados de material.');
        $this->addSql('DROP TABLE inventory_commitments');
    }
}
