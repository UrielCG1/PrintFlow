<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alinea definiciones físicas de MySQL con el mapeo Doctrine vigente.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql('ALTER TABLE commercial_categories CHANGE description description LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE commercial_items CHANGE description description LONGTEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE item_price_rules
            CHANGE created_at created_at DATETIME NOT NULL,
            CHANGE updated_at updated_at DATETIME NOT NULL');

        $this->addSql("ALTER TABLE quotations
            CHANGE issued_at issued_at DATETIME DEFAULT NULL,
            CHANGE currency currency VARCHAR(3) DEFAULT 'MXN' NOT NULL,
            CHANGE notes notes LONGTEXT DEFAULT NULL,
            CHANGE discount_percent discount_percent NUMERIC(5, 2) NOT NULL,
            CHANGE tax_rate tax_rate NUMERIC(5, 4) NOT NULL,
            CHANGE subtotal subtotal NUMERIC(14, 2) NOT NULL,
            CHANGE discount_amount discount_amount NUMERIC(14, 2) NOT NULL,
            CHANGE taxable_amount taxable_amount NUMERIC(14, 2) NOT NULL,
            CHANGE tax_amount tax_amount NUMERIC(14, 2) NOT NULL,
            CHANGE total total NUMERIC(14, 2) NOT NULL,
            CHANGE created_at created_at DATETIME NOT NULL,
            CHANGE updated_at updated_at DATETIME NOT NULL");

        $this->addSql('ALTER TABLE quotation_items
            CHANGE created_at created_at DATETIME NOT NULL,
            CHANGE updated_at updated_at DATETIME NOT NULL');

        $this->addSql(
            'ALTER TABLE quotations
             RENAME INDEX fk_quotations_created_by_user
             TO idx_quotations_created_by_user'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform),
            'Esta migración solo puede ejecutarse en MySQL.',
        );

        $this->addSql('ALTER TABLE commercial_categories CHANGE description description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE commercial_items CHANGE description description TEXT DEFAULT NULL');

        $this->addSql("ALTER TABLE item_price_rules
            CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql("ALTER TABLE quotations
            CHANGE issued_at issued_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE currency currency CHAR(3) NOT NULL DEFAULT 'MXN',
            CHANGE notes notes TEXT DEFAULT NULL,
            CHANGE discount_percent discount_percent NUMERIC(5, 2) NOT NULL DEFAULT 0.00,
            CHANGE tax_rate tax_rate NUMERIC(5, 4) NOT NULL DEFAULT 0.1600,
            CHANGE subtotal subtotal NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            CHANGE discount_amount discount_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            CHANGE taxable_amount taxable_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            CHANGE tax_amount tax_amount NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            CHANGE total total NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
            CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql("ALTER TABLE quotation_items
            CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            CHANGE updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql(
            'ALTER TABLE quotations
             RENAME INDEX idx_quotations_created_by_user
             TO fk_quotations_created_by_user'
        );
    }
}