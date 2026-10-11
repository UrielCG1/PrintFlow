<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010171000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publica una nueva revisión de rutas existentes cuyo Diseño inicial era condicional u opcional.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración solo puede ejecutarse sobre MySQL o MariaDB.',
        );

        /*
         * No se modifica la versión histórica de ruta ni sus snapshots. Se
         * publica una revisión derivada y se cambia únicamente la referencia
         * de Producto para las órdenes futuras.
         */
        $this->addSql(<<<'SQL'
            INSERT INTO commercial_item_route_versions (
                commercial_item_id, source_template_id, version_number,
                name_snapshot, definition_snapshot, published_by_user_id,
                published_at, created_at
            )
            SELECT
                previous_version.commercial_item_id,
                previous_version.source_template_id,
                previous_version.version_number + 1,
                CONCAT(LEFT(previous_version.name_snapshot, 96), ' - Diseno obligatorio'),
                JSON_SET(
                    previous_version.definition_snapshot,
                    '$.migration', JSON_OBJECT(
                        'code', 'PHASE2_DESIGN_FIRST',
                        'source_version_id', previous_version.id,
                        'reason', 'Normaliza Diseño obligatorio como primer paso para órdenes futuras.'
                    ),
                    '$.steps[0].condition', 'ALWAYS',
                    '$.steps[0].condition_config', NULL,
                    '$.steps[0].is_required', TRUE
                ),
                previous_version.published_by_user_id,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            FROM commercial_items
            INNER JOIN commercial_item_route_versions AS previous_version
                ON previous_version.id = commercial_items.current_route_version_id
            INNER JOIN commercial_item_route_steps AS first_step
                ON first_step.route_version_id = previous_version.id
                AND first_step.sequence_number = 1
            INNER JOIN operations AS first_operation
                ON first_operation.id = first_step.operation_id
            INNER JOIN operation_areas AS first_area
                ON first_area.id = first_operation.operation_area_id
            WHERE first_operation.code = 'PRE-DISENO'
              AND first_area.code = 'PREPRESS'
              AND (
                  first_step.condition_code <> 'ALWAYS'
                  OR first_step.is_required = 0
              )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO commercial_item_route_steps (
                route_version_id, operation_id, sequence_number,
                condition_code, condition_config, is_required, operation_snapshot
            )
            SELECT
                corrected_version.id,
                previous_step.operation_id,
                previous_step.sequence_number,
                CASE WHEN previous_step.sequence_number = 1 THEN 'ALWAYS' ELSE previous_step.condition_code END,
                CASE WHEN previous_step.sequence_number = 1 THEN NULL ELSE previous_step.condition_config END,
                CASE WHEN previous_step.sequence_number = 1 THEN 1 ELSE previous_step.is_required END,
                previous_step.operation_snapshot
            FROM commercial_items
            INNER JOIN commercial_item_route_versions AS previous_version
                ON previous_version.id = commercial_items.current_route_version_id
            INNER JOIN commercial_item_route_versions AS corrected_version
                ON corrected_version.commercial_item_id = previous_version.commercial_item_id
                AND corrected_version.version_number = previous_version.version_number + 1
                AND JSON_UNQUOTE(JSON_EXTRACT(corrected_version.definition_snapshot, '$.migration.code')) = 'PHASE2_DESIGN_FIRST'
            INNER JOIN commercial_item_route_steps AS previous_step
                ON previous_step.route_version_id = previous_version.id
            INNER JOIN commercial_item_route_steps AS first_step
                ON first_step.route_version_id = previous_version.id
                AND first_step.sequence_number = 1
            INNER JOIN operations AS first_operation
                ON first_operation.id = first_step.operation_id
            INNER JOIN operation_areas AS first_area
                ON first_area.id = first_operation.operation_area_id
            WHERE first_operation.code = 'PRE-DISENO'
              AND first_area.code = 'PREPRESS'
              AND (
                  first_step.condition_code <> 'ALWAYS'
                  OR first_step.is_required = 0
              )
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE commercial_items
            INNER JOIN commercial_item_route_versions AS previous_version
                ON previous_version.id = commercial_items.current_route_version_id
            INNER JOIN commercial_item_route_versions AS corrected_version
                ON corrected_version.commercial_item_id = previous_version.commercial_item_id
                AND corrected_version.version_number = previous_version.version_number + 1
                AND JSON_UNQUOTE(JSON_EXTRACT(corrected_version.definition_snapshot, '$.migration.code')) = 'PHASE2_DESIGN_FIRST'
            SET commercial_items.current_route_version_id = corrected_version.id
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
                SELECT COUNT(*)
                FROM service_order_items
                INNER JOIN commercial_item_route_versions
                    ON commercial_item_route_versions.id = service_order_items.route_version_id
                WHERE JSON_UNQUOTE(JSON_EXTRACT(commercial_item_route_versions.definition_snapshot, '$.migration.code')) = 'PHASE2_DESIGN_FIRST'
                SQL) > 0,
            'No se puede revertir mientras una orden conserve la revisión de ruta con Diseño obligatorio.',
        );

        $this->addSql(<<<'SQL'
            UPDATE commercial_items
            INNER JOIN commercial_item_route_versions AS corrected_version
                ON corrected_version.id = commercial_items.current_route_version_id
            INNER JOIN commercial_item_route_versions AS previous_version
                ON previous_version.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(corrected_version.definition_snapshot, '$.migration.source_version_id')) AS UNSIGNED)
            SET commercial_items.current_route_version_id = previous_version.id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(corrected_version.definition_snapshot, '$.migration.code')) = 'PHASE2_DESIGN_FIRST'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE commercial_item_route_steps
            FROM commercial_item_route_steps
            INNER JOIN commercial_item_route_versions
                ON commercial_item_route_versions.id = commercial_item_route_steps.route_version_id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(commercial_item_route_versions.definition_snapshot, '$.migration.code')) = 'PHASE2_DESIGN_FIRST'
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM commercial_item_route_versions
            WHERE JSON_UNQUOTE(JSON_EXTRACT(definition_snapshot, '$.migration.code')) = 'PHASE2_DESIGN_FIRST'
            SQL);
    }
}
