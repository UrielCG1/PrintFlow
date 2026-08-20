<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stores purchase-order and response-screenshot metadata for quotation acceptance evidence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quotations ADD purchase_order_number VARCHAR(120) DEFAULT NULL, ADD purchase_order_file JSON DEFAULT NULL, ADD response_screenshot_file JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quotations DROP purchase_order_number, DROP purchase_order_file, DROP response_screenshot_file');
    }
}
