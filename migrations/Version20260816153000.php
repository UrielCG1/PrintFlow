<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el catálogo de características comerciales y su configuración por producto para cotizaciones internas.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql("CREATE TABLE commercial_characteristics (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            input_type VARCHAR(20) NOT NULL,
            unit_label VARCHAR(20) DEFAULT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_commercial_characteristics_code (code),
            UNIQUE INDEX uniq_commercial_characteristics_name (name),
            INDEX idx_commercial_characteristics_active_order (is_active, display_order, name),
            CONSTRAINT chk_commercial_characteristics_input_type CHECK (input_type IN ('SELECT', 'DECIMAL', 'TEXT', 'BOOLEAN')),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB");

        $this->addSql("CREATE TABLE commercial_characteristic_options (
            id INT AUTO_INCREMENT NOT NULL,
            characteristic_id INT NOT NULL,
            code VARCHAR(60) NOT NULL,
            name VARCHAR(100) NOT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_commercial_characteristic_options_code (characteristic_id, code),
            UNIQUE INDEX uniq_commercial_characteristic_options_name (characteristic_id, name),
            INDEX idx_commercial_characteristic_options_active_order (characteristic_id, is_active, display_order, name),
            PRIMARY KEY(id),
            CONSTRAINT fk_commercial_characteristic_options_characteristic FOREIGN KEY (characteristic_id) REFERENCES commercial_characteristics (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB");

        $this->addSql("CREATE TABLE commercial_item_characteristics (
            id INT AUTO_INCREMENT NOT NULL,
            commercial_item_id INT NOT NULL,
            characteristic_id INT NOT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_commercial_item_characteristics (commercial_item_id, characteristic_id),
            INDEX idx_commercial_item_characteristics_order (commercial_item_id, display_order),
            PRIMARY KEY(id),
            CONSTRAINT fk_commercial_item_characteristics_item FOREIGN KEY (commercial_item_id) REFERENCES commercial_items (id) ON DELETE RESTRICT,
            CONSTRAINT fk_commercial_item_characteristics_characteristic FOREIGN KEY (characteristic_id) REFERENCES commercial_characteristics (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB");

        $this->addSql("CREATE TABLE commercial_item_characteristic_options (
            id INT AUTO_INCREMENT NOT NULL,
            commercial_item_characteristic_id INT NOT NULL,
            characteristic_option_id INT NOT NULL,
            display_order INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE INDEX uniq_commercial_item_characteristic_options (commercial_item_characteristic_id, characteristic_option_id),
            INDEX idx_commercial_item_characteristic_options_order (commercial_item_characteristic_id, display_order),
            PRIMARY KEY(id),
            CONSTRAINT fk_commercial_item_characteristic_options_configuration FOREIGN KEY (commercial_item_characteristic_id) REFERENCES commercial_item_characteristics (id) ON DELETE CASCADE,
            CONSTRAINT fk_commercial_item_characteristic_options_option FOREIGN KEY (characteristic_option_id) REFERENCES commercial_characteristic_options (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB");

        $this->addSql("INSERT INTO commercial_characteristics (code, name, input_type, unit_label, display_order, is_active, created_at, updated_at) VALUES
            ('FINISHED_WIDTH_CM', 'Ancho terminado', 'DECIMAL', 'cm', 10, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('FINISHED_HEIGHT_CM', 'Alto terminado', 'DECIMAL', 'cm', 20, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('BASIS_WEIGHT', 'Gramaje', 'SELECT', 'g/m²', 30, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('ADHESIVE_TYPE', 'Tipo de adhesivo', 'SELECT', NULL, 40, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('FINISH', 'Acabado', 'SELECT', NULL, 50, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('CUT_TYPE', 'Corte', 'SELECT', NULL, 60, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ('LAMINATION', 'Laminado protector', 'SELECT', NULL, 70, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        $this->addSql("INSERT INTO commercial_characteristic_options (characteristic_id, code, name, display_order, is_active, created_at, updated_at)
            SELECT characteristic.id, option_data.code, option_data.name, option_data.display_order, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            FROM commercial_characteristics characteristic
            INNER JOIN (
                SELECT 'BASIS_WEIGHT' AS characteristic_code, 'G90' AS code, '90 g/m²' AS name, 10 AS display_order
                UNION ALL SELECT 'BASIS_WEIGHT', 'G115', '115 g/m²', 20
                UNION ALL SELECT 'BASIS_WEIGHT', 'G135', '135 g/m²', 30
                UNION ALL SELECT 'ADHESIVE_TYPE', 'PERMANENT', 'Permanente', 10
                UNION ALL SELECT 'ADHESIVE_TYPE', 'REMOVABLE', 'Removible', 20
                UNION ALL SELECT 'FINISH', 'MATTE', 'Mate', 10
                UNION ALL SELECT 'FINISH', 'GLOSS', 'Brillante', 20
                UNION ALL SELECT 'CUT_TYPE', 'STRAIGHT', 'Recto', 10
                UNION ALL SELECT 'CUT_TYPE', 'KISS_CUT', 'Medio corte', 20
                UNION ALL SELECT 'CUT_TYPE', 'DIE_CUT', 'Troquelado', 30
                UNION ALL SELECT 'LAMINATION', 'NONE', 'Sin laminado', 10
                UNION ALL SELECT 'LAMINATION', 'MATTE', 'Mate', 20
                UNION ALL SELECT 'LAMINATION', 'GLOSS', 'Brillante', 30
            ) option_data ON option_data.characteristic_code = characteristic.code");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform), 'Esta migración solo puede ejecutarse en MySQL o MariaDB.');

        $this->addSql('DROP TABLE commercial_item_characteristic_options');
        $this->addSql('DROP TABLE commercial_item_characteristics');
        $this->addSql('DROP TABLE commercial_characteristic_options');
        $this->addSql('DROP TABLE commercial_characteristics');
    }
}
