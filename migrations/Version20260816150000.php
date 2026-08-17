<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conserva la compatibilidad con instalaciones donde esta versión quedó
 * registrada antes de que su archivo desapareciera del repositorio.
 */
final class Version20260816150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compatibilidad con una migración histórica ya ejecutada; no modifica el esquema.';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
