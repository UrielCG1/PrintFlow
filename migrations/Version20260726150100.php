<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alinea los campos de texto de materiales con el mapeo LONGTEXT de Doctrine.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            'ALTER TABLE materials CHANGE description description LONGTEXT DEFAULT NULL, CHANGE notes notes LONGTEXT DEFAULT NULL',
        );

        $this->addSql(
            'ALTER TABLE material_categories CHANGE description description LONGTEXT DEFAULT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql(
            'ALTER TABLE materials CHANGE description description TEXT DEFAULT NULL, CHANGE notes notes TEXT DEFAULT NULL',
        );

        $this->addSql(
            'ALTER TABLE material_categories CHANGE description description TEXT DEFAULT NULL',
        );
    }
}