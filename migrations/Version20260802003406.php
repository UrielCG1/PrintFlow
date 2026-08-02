<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260802003406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quote_request (id INT AUTO_INCREMENT NOT NULL, folio VARCHAR(30) NOT NULL, public_token VARCHAR(64) NOT NULL, status VARCHAR(30) NOT NULL, customer_number VARCHAR(50) DEFAULT NULL, full_name VARCHAR(150) NOT NULL, email VARCHAR(180) NOT NULL, phone VARCHAR(30) NOT NULL, company_name VARCHAR(180) DEFAULT NULL, contact_preference VARCHAR(20) NOT NULL, product_type VARCHAR(150) NOT NULL, quantity INT NOT NULL, width NUMERIC(10, 2) DEFAULT NULL, height NUMERIC(10, 2) DEFAULT NULL, measurement_unit VARCHAR(20) DEFAULT NULL, material VARCHAR(150) DEFAULT NULL, print_sides VARCHAR(30) DEFAULT NULL, finishes JSON DEFAULT NULL, design_status VARCHAR(30) NOT NULL, needed_at DATE DEFAULT NULL, delivery_method VARCHAR(30) NOT NULL, postal_code VARCHAR(10) DEFAULT NULL, requires_invoice TINYINT NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, quotation_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D478271BB4EA4E60 (quotation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quote_request ADD CONSTRAINT FK_D478271BB4EA4E60 FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quote_request DROP FOREIGN KEY FK_D478271BB4EA4E60');
        $this->addSql('DROP TABLE quote_request');
    }
}
