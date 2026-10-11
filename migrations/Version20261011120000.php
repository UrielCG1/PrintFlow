<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registra tiempo activo de intentos y permite capturar materiales operativos manuales sin movimientos de inventario.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE production_operation_executions
                ADD active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                ADD active_segment_started_at DATETIME DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE production_operation_executions
            SET active_segment_started_at = started_at
            WHERE status = 'IN_PROGRESS' AND open_marker IS NOT NULL
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                MODIFY material_variant_id INT DEFAULT NULL,
                MODIFY unit_id INT DEFAULT NULL,
                ADD material_name_snapshot VARCHAR(180) DEFAULT NULL,
                ADD unit_label_snapshot VARCHAR(40) DEFAULT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE production_material_usages usage_row
            INNER JOIN material_variants variant ON variant.id = usage_row.material_variant_id
            INNER JOIN materials material ON material.id = variant.material_id
            INNER JOIN measurement_units unit ON unit.id = usage_row.unit_id
            SET usage_row.material_name_snapshot = COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(usage_row.material_snapshot, '$.material_name')), ''),
                    material.name
                ),
                usage_row.unit_label_snapshot = COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(usage_row.material_snapshot, '$.consumption_unit.symbol')), ''),
                    NULLIF(unit.symbol, ''),
                    unit.code
                )
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                MODIFY material_name_snapshot VARCHAR(180) NOT NULL,
                MODIFY unit_label_snapshot VARCHAR(40) NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede revertirse sobre MySQL o MariaDB.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM production_operation_executions
                WHERE active_seconds > 0 OR active_segment_started_at IS NOT NULL
                SQL) > 0,
            'No se puede revertir mientras haya intentos con medición de tiempo activo.',
        );
        $this->abortIf(
            (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*) FROM production_material_usages
                WHERE material_variant_id IS NULL OR unit_id IS NULL
                SQL) > 0,
            'No se puede revertir mientras existan capturas manuales sin variante y unidad de catálogo.',
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE production_material_usages
                DROP material_name_snapshot,
                DROP unit_label_snapshot,
                MODIFY material_variant_id INT NOT NULL,
                MODIFY unit_id INT NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE production_operation_executions
                DROP active_seconds,
                DROP active_segment_started_at
            SQL);
    }
}
