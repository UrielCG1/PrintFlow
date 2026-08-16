<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Obtiene el descuento desde la categoría y permite categorizar sucursales de clientes.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Solo MySQL o MariaDB.');
        $this->addSql('ALTER TABLE clients DROP default_discount_percent');
        $this->addSql("ALTER TABLE client_branches ADD client_category_id INT DEFAULT NULL COMMENT 'Categoría propia de la sucursal; NULL hereda la del cliente', ADD INDEX idx_client_branches_category (client_category_id), ADD CONSTRAINT FK_CLIENT_BRANCH_CATEGORY FOREIGN KEY (client_category_id) REFERENCES client_categories (id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client_branches DROP FOREIGN KEY FK_CLIENT_BRANCH_CATEGORY, DROP INDEX idx_client_branches_category, DROP client_category_id');
        $this->addSql("ALTER TABLE clients ADD default_discount_percent DOUBLE PRECISION DEFAULT 0 NOT NULL COMMENT 'Descuento anterior a la normalización por categoría'");
        $this->addSql('UPDATE clients c INNER JOIN client_categories cc ON cc.id=c.client_category_id SET c.default_discount_percent=cc.discount_percentage');
    }
}
