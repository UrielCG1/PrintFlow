<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260820010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normaliza metadatos de unidades existentes para el catálogo por dimensión y sus conversiones base.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        // Presentaciones de conteo: no existe una conversión universal entre sí.
        $this->addSql(<<<'SQL'
            UPDATE measurement_units
            SET
                symbol = CASE code
                    WHEN 'PZA' THEN 'pza'
                    WHEN 'SERV' THEN 'serv.'
                    WHEN 'PAQ' THEN 'paq.'
                    WHEN 'JGO' THEN 'jgo.'
                    WHEN 'BOTELLA' THEN 'bot.'
                    WHEN 'ROLLO' THEN 'rollo'
                    WHEN 'HOJA' THEN 'hoja'
                    WHEN 'CAJA' THEN 'caja'
                    WHEN 'CARTUCHO' THEN 'cart.'
                    ELSE symbol
                END,
                dimension_type = 'COUNT',
                base_unit_id = NULL,
                conversion_factor = 1,
                decimal_scale = 0,
                allows_fraction = 0
            WHERE code IN ('PZA','SERV','PAQ','JGO','BOTELLA','ROLLO','HOJA','CAJA','CARTUCHO')
            SQL);

        // Longitud. M es base cuando existe; ML funciona como base comercial alternativa.
        $this->addSql(<<<'SQL'
            UPDATE measurement_units
            SET
                symbol = CASE code
                    WHEN 'M' THEN 'm'
                    WHEN 'ML' THEN 'm'
                    WHEN 'CM' THEN 'cm'
                    WHEN 'MM' THEN 'mm'
                    ELSE symbol
                END,
                dimension_type = 'LENGTH',
                base_unit_id = NULL,
                conversion_factor = CASE code
                    WHEN 'CM' THEN 0.01
                    WHEN 'MM' THEN 0.001
                    ELSE 1
                END,
                decimal_scale = 4,
                allows_fraction = 1
            WHERE code IN ('M','ML','CM','MM')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE measurement_units derived
            LEFT JOIN measurement_units meter ON meter.code = 'M'
            LEFT JOIN measurement_units linear_meter ON linear_meter.code = 'ML'
            SET derived.base_unit_id = COALESCE(meter.id, linear_meter.id)
            WHERE derived.code IN ('CM','MM')
              AND COALESCE(meter.id, linear_meter.id) IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE measurement_units derived
            JOIN measurement_units meter ON meter.code = 'M'
            SET derived.base_unit_id = meter.id, derived.conversion_factor = 1
            WHERE derived.code = 'ML'
            SQL);

        // Área.
        $this->addSql(<<<'SQL'
            UPDATE measurement_units
            SET
                symbol = CASE code WHEN 'M2' THEN 'm²' WHEN 'CM2' THEN 'cm²' ELSE symbol END,
                dimension_type = 'AREA',
                base_unit_id = NULL,
                conversion_factor = CASE code WHEN 'CM2' THEN 0.0001 ELSE 1 END,
                decimal_scale = 4,
                allows_fraction = 1
            WHERE code IN ('M2','CM2')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE measurement_units derived
            JOIN measurement_units base ON base.code = 'M2'
            SET derived.base_unit_id = base.id, derived.conversion_factor = 0.0001
            WHERE derived.code = 'CM2'
            SQL);

        // Tiempo. PrintFlow cotiza servicios normalmente en horas, por eso H/HORA son base.
        $this->addSql(<<<'SQL'
            UPDATE measurement_units
            SET
                symbol = CASE code WHEN 'MIN' THEN 'min' ELSE 'h' END,
                dimension_type = 'TIME',
                base_unit_id = NULL,
                conversion_factor = CASE WHEN code = 'MIN' THEN 0.016666666667 ELSE 1 END,
                decimal_scale = 2,
                allows_fraction = 1
            WHERE code IN ('H','HORA','MIN')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE measurement_units derived
            LEFT JOIN measurement_units hour_unit ON hour_unit.code = 'HORA'
            LEFT JOIN measurement_units legacy_hour ON legacy_hour.code = 'H'
            SET derived.base_unit_id = COALESCE(hour_unit.id, legacy_hour.id),
                derived.conversion_factor = 0.016666666667
            WHERE derived.code = 'MIN'
              AND COALESCE(hour_unit.id, legacy_hour.id) IS NOT NULL
            SQL);

        // Volumen.
        $this->addSql(<<<'SQL'
            UPDATE measurement_units
            SET
                symbol = CASE code WHEN 'L' THEN 'L' ELSE 'mL' END,
                dimension_type = 'VOLUME',
                base_unit_id = NULL,
                conversion_factor = CASE WHEN code IN ('MLT','ML_VOL') THEN 0.001 ELSE 1 END,
                decimal_scale = 4,
                allows_fraction = 1
            WHERE code IN ('L','MLT','ML_VOL')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE measurement_units derived
            JOIN measurement_units base ON base.code = 'L'
            SET derived.base_unit_id = base.id, derived.conversion_factor = 0.001
            WHERE derived.code IN ('MLT','ML_VOL')
            SQL);

        // Masa.
        $this->addSql(<<<'SQL'
            UPDATE measurement_units
            SET
                symbol = CASE code WHEN 'KG' THEN 'kg' ELSE 'g' END,
                dimension_type = 'MASS',
                base_unit_id = NULL,
                conversion_factor = CASE WHEN code = 'G' THEN 0.001 ELSE 1 END,
                decimal_scale = 4,
                allows_fraction = 1
            WHERE code IN ('KG','G')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE measurement_units derived
            JOIN measurement_units base ON base.code = 'KG'
            SET derived.base_unit_id = base.id, derived.conversion_factor = 0.001
            WHERE derived.code = 'G'
            SQL);

        // Cualquier unidad personalizada legada sin símbolo obtiene un fallback visible y editable.
        $this->addSql("UPDATE measurement_units SET symbol = LOWER(code) WHERE TRIM(symbol) = ''");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'La migración normaliza datos preexistentes y no puede reconstruir de forma segura sus valores anteriores.',
        );
    }
}
