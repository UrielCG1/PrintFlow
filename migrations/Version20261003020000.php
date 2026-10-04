<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Retira el porcentaje de descuento legado de la segmentación de clientes.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse sobre MySQL o MariaDB.');
        $this->addSql('ALTER TABLE client_categories DROP COLUMN discount_percentage');
    }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE client_categories ADD discount_percentage NUMERIC(5,2) NOT NULL DEFAULT 0.00'); }
}
