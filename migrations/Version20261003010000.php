<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega clases de cliente, perfiles de costeo, reglas de volumen y snapshots de descuentos V2.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Esta migración sólo puede ejecutarse sobre MySQL o MariaDB.');

        $this->addSql("CREATE TABLE client_classes (
            id INT AUTO_INCREMENT NOT NULL,
            code CHAR(1) NOT NULL,
            name VARCHAR(100) NOT NULL,
            loyalty_discount_percent NUMERIC(7, 4) NOT NULL DEFAULT 0.0000,
            config_revision INT UNSIGNED NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_client_classes_code (code),
            PRIMARY KEY(id),
            CONSTRAINT chk_client_classes_code CHECK (code IN ('A','B','C')),
            CONSTRAINT chk_client_classes_loyalty CHECK (loyalty_discount_percent >= 0 AND loyalty_discount_percent <= 100)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("INSERT IGNORE INTO client_classes (code,name,loyalty_discount_percent,config_revision,is_active,is_system,created_at,updated_at)
            VALUES ('A','Clase A',0.0000,1,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
                   ('B','Clase B',0.0000,1,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
                   ('C','Clase C',0.0000,1,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");

        $this->addSql('ALTER TABLE clients ADD client_class_id INT DEFAULT NULL');
        $this->addSql("UPDATE clients c INNER JOIN client_classes cc ON cc.code='C' SET c.client_class_id=cc.id WHERE c.client_class_id IS NULL");
        $this->addSql('ALTER TABLE clients MODIFY client_class_id INT NOT NULL');
        $this->addSql('ALTER TABLE clients ADD CONSTRAINT FK_CLIENT_CLASS FOREIGN KEY (client_class_id) REFERENCES client_classes (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_CLIENT_CLASS ON clients (client_class_id)');
        $this->addSql('ALTER TABLE client_contacts ADD client_class_override_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE client_contacts ADD CONSTRAINT FK_CLIENT_CONTACT_CLASS_OVERRIDE FOREIGN KEY (client_class_override_id) REFERENCES client_classes (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_CLIENT_CONTACT_CLASS_OVERRIDE ON client_contacts (client_class_override_id)');

        $this->addSql("CREATE TABLE discount_types (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(30) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_discount_types_code (code),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("INSERT IGNORE INTO discount_types (code,name,description,display_order,is_active,is_system,created_at,updated_at)
            VALUES ('VOLUME','Por volumen','Descuento automático por volumen de costeo.',10,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
                   ('LOYALTY','Por lealtad','Descuento automático por clase efectiva del cliente.',20,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
                   ('ADDITIONAL','Descuento adicional','Descuento manual autorizado por un administrador.',30,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");

        $this->addSql("CREATE TABLE commercial_costing_profiles (
            id INT AUTO_INCREMENT NOT NULL,
            commercial_category_id INT NOT NULL,
            costing_unit_id INT NOT NULL,
            calculation_method VARCHAR(60) NOT NULL,
            profile_revision INT UNSIGNED NOT NULL DEFAULT 1,
            strategy_version INT UNSIGNED NOT NULL DEFAULT 1,
            parameters_json JSON NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_costing_profiles_category (commercial_category_id),
            INDEX idx_costing_profiles_active (is_active),
            PRIMARY KEY(id),
            CONSTRAINT FK_COSTING_PROFILE_CATEGORY FOREIGN KEY (commercial_category_id) REFERENCES commercial_categories (id) ON DELETE RESTRICT,
            CONSTRAINT FK_COSTING_PROFILE_UNIT FOREIGN KEY (costing_unit_id) REFERENCES measurement_units (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE volume_discount_rules (
            id INT AUTO_INCREMENT NOT NULL,
            costing_profile_id INT NOT NULL,
            discount_type_id INT NOT NULL,
            min_volume NUMERIC(18,6) NOT NULL,
            discount_percent NUMERIC(7,4) NOT NULL,
            config_revision INT UNSIGNED NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_volume_rules_profile_minimum (costing_profile_id, min_volume),
            INDEX idx_volume_rules_lookup (costing_profile_id, is_active, min_volume),
            PRIMARY KEY(id),
            CONSTRAINT FK_VOLUME_RULE_PROFILE FOREIGN KEY (costing_profile_id) REFERENCES commercial_costing_profiles (id) ON DELETE RESTRICT,
            CONSTRAINT FK_VOLUME_RULE_TYPE FOREIGN KEY (discount_type_id) REFERENCES discount_types (id) ON DELETE RESTRICT,
            CONSTRAINT chk_volume_rules_minimum CHECK (min_volume > 0),
            CONSTRAINT chk_volume_rules_percent CHECK (discount_percent >= 0 AND discount_percent <= 100)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE quotation_items ADD ordered_quantity NUMERIC(18,6) DEFAULT NULL, ADD calculation_origin VARCHAR(20) NOT NULL DEFAULT \'LEGACY_UNKNOWN\'');
        $this->addSql('ALTER TABLE quotation_items MODIFY quantity NUMERIC(18,6) NOT NULL');
        $this->addSql('ALTER TABLE item_price_rules MODIFY min_quantity NUMERIC(18,6) NOT NULL');
        $this->addSql("ALTER TABLE service_order_items MODIFY quantity NUMERIC(18,6) NOT NULL");

        $this->addSql("ALTER TABLE quotations
            ADD discount_breakdown JSON DEFAULT NULL,
            ADD additional_discount_percent NUMERIC(7,4) NOT NULL DEFAULT 0.0000,
            ADD additional_discount_reason LONGTEXT DEFAULT NULL,
            ADD pricing_engine_version VARCHAR(10) NOT NULL DEFAULT 'LEGACY',
            ADD pricing_calculation_version INT UNSIGNED NOT NULL DEFAULT 1,
            ADD pricing_hash CHAR(64) DEFAULT NULL,
            ADD volume_calculation_version INT UNSIGNED NOT NULL DEFAULT 0,
            ADD volume_hash CHAR(64) DEFAULT NULL");
        $this->addSql("UPDATE quotations SET discount_breakdown = JSON_ARRAY() WHERE discount_breakdown IS NULL");
        $this->addSql('ALTER TABLE quotations MODIFY discount_breakdown JSON NOT NULL');
        $this->addSql("UPDATE quotations SET acceptance_token = NULL WHERE status IN ('REQUEST','IN_REVIEW','DRAFT')");

        $this->addSql("CREATE TABLE quotation_discount_applications (
            id INT AUTO_INCREMENT NOT NULL,
            quotation_id INT NOT NULL,
            discount_type_id INT NOT NULL,
            commercial_category_id INT DEFAULT NULL,
            source_volume_rule_id INT DEFAULT NULL,
            source_client_class_id INT DEFAULT NULL,
            created_by_user_id INT DEFAULT NULL,
            pricing_calculation_version INT UNSIGNED NOT NULL,
            scope VARCHAR(20) NOT NULL,
            scope_key VARCHAR(80) NOT NULL,
            percentage NUMERIC(7,4) NOT NULL,
            base_amount NUMERIC(14,2) NOT NULL,
            discount_amount NUMERIC(14,2) NOT NULL,
            reason LONGTEXT DEFAULT NULL,
            context_snapshot JSON NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_quotation_discount_scope (quotation_id, pricing_calculation_version, discount_type_id, scope_key),
            INDEX idx_quotation_discount_quotation (quotation_id, pricing_calculation_version),
            PRIMARY KEY(id),
            CONSTRAINT FK_QDA_QUOTATION FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE RESTRICT,
            CONSTRAINT FK_QDA_TYPE FOREIGN KEY (discount_type_id) REFERENCES discount_types (id) ON DELETE RESTRICT,
            CONSTRAINT FK_QDA_CATEGORY FOREIGN KEY (commercial_category_id) REFERENCES commercial_categories (id) ON DELETE RESTRICT,
            CONSTRAINT FK_QDA_RULE FOREIGN KEY (source_volume_rule_id) REFERENCES volume_discount_rules (id) ON DELETE RESTRICT,
            CONSTRAINT FK_QDA_CLASS FOREIGN KEY (source_client_class_id) REFERENCES client_classes (id) ON DELETE RESTRICT,
            CONSTRAINT FK_QDA_USER FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT chk_qda_scope CHECK (scope IN ('QUOTATION','BUSINESS_LINE')),
            CONSTRAINT chk_qda_percent CHECK (percentage >= 0 AND percentage <= 100),
            CONSTRAINT chk_qda_amounts CHECK (base_amount >= 0 AND discount_amount >= 0)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE quotation_item_discount_allocations (
            id INT AUTO_INCREMENT NOT NULL,
            application_id INT NOT NULL,
            quotation_item_id INT NOT NULL,
            base_amount NUMERIC(14,2) NOT NULL,
            discount_amount NUMERIC(14,2) NOT NULL,
            UNIQUE INDEX uniq_discount_allocation_item (application_id, quotation_item_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_QIDA_APPLICATION FOREIGN KEY (application_id) REFERENCES quotation_discount_applications (id) ON DELETE CASCADE,
            CONSTRAINT FK_QIDA_ITEM FOREIGN KEY (quotation_item_id) REFERENCES quotation_items (id) ON DELETE CASCADE,
            CONSTRAINT chk_qida_amounts CHECK (base_amount >= 0 AND discount_amount >= 0)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE quotation_volume_discount_reviews (
            id INT AUTO_INCREMENT NOT NULL,
            quotation_id INT NOT NULL,
            requested_by_user_id INT DEFAULT NULL,
            reviewed_by_user_id INT DEFAULT NULL,
            volume_calculation_version INT UNSIGNED NOT NULL,
            review_attempt INT UNSIGNED NOT NULL DEFAULT 1,
            volume_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL,
            origin VARCHAR(20) NOT NULL DEFAULT 'SYSTEM',
            notes LONGTEXT DEFAULT NULL,
            requested_at DATETIME NOT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_volume_review_attempt (quotation_id, volume_calculation_version, review_attempt),
            INDEX idx_volume_review_current (quotation_id, volume_calculation_version, status),
            PRIMARY KEY(id),
            CONSTRAINT FK_QVDR_QUOTATION FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE CASCADE,
            CONSTRAINT FK_QVDR_REQUESTED_BY FOREIGN KEY (requested_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT FK_QVDR_REVIEWED_BY FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT chk_volume_review_status CHECK (status IN ('PENDING','APPROVED','REJECTED','INVALIDATED'))
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quotation_volume_discount_reviews');
        $this->addSql('DROP TABLE quotation_item_discount_allocations');
        $this->addSql('DROP TABLE quotation_discount_applications');
        $this->addSql('ALTER TABLE quotations DROP discount_breakdown, DROP additional_discount_percent, DROP additional_discount_reason, DROP pricing_engine_version, DROP pricing_calculation_version, DROP pricing_hash, DROP volume_calculation_version, DROP volume_hash');
        $this->addSql('ALTER TABLE service_order_items MODIFY quantity NUMERIC(14,4) NOT NULL');
        $this->addSql('ALTER TABLE item_price_rules MODIFY min_quantity NUMERIC(14,4) NOT NULL');
        $this->addSql('ALTER TABLE quotation_items MODIFY quantity NUMERIC(14,4) NOT NULL, DROP ordered_quantity, DROP calculation_origin');
        $this->addSql('DROP TABLE volume_discount_rules');
        $this->addSql('DROP TABLE commercial_costing_profiles');
        $this->addSql('ALTER TABLE client_contacts DROP FOREIGN KEY FK_CLIENT_CONTACT_CLASS_OVERRIDE');
        $this->addSql('DROP INDEX IDX_CLIENT_CONTACT_CLASS_OVERRIDE ON client_contacts');
        $this->addSql('ALTER TABLE client_contacts DROP client_class_override_id');
        $this->addSql('ALTER TABLE clients DROP FOREIGN KEY FK_CLIENT_CLASS');
        $this->addSql('DROP INDEX IDX_CLIENT_CLASS ON clients');
        $this->addSql('ALTER TABLE clients DROP client_class_id');
        $this->addSql('DROP TABLE discount_types');
        $this->addSql('DROP TABLE client_classes');
    }
}
