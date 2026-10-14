<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Inventory\InventoryReadinessEvaluator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:inventory:readiness',
    description: 'Revisa sin cambios si el catálogo está listo para el conteo inicial y las etiquetas QR de inventario.',
)]
final class InventoryCatalogReadinessCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InventoryReadinessEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Devuelve error si queda cualquier observación de preparación; útil como puerta de salida del piloto.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Preparación del catálogo para inventario');
        $io->note('Esta revisión es de solo lectura: no crea saldos, lotes, movimientos, reservas ni etiquetas.');

        try {
            $variants = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    material.code AS material_code,
                    material.name AS material_name,
                    variant.code AS variant_code,
                    variant.specifications,
                    variant.minimum_stock,
                    variant.reorder_point,
                    variant.reorder_quantity,
                    material.is_hazardous AS material_is_hazardous,
                    material.storage_conditions,
                    inventory_unit.code AS inventory_unit_code,
                    inventory_unit.dimension_type AS inventory_unit_dimension,
                    inventory_unit.allows_fraction AS inventory_unit_allows_fraction,
                    consumption_unit.code AS consumption_unit_code,
                    consumption_unit.dimension_type AS consumption_unit_dimension
                FROM material_variants variant
                INNER JOIN materials material ON material.id = variant.material_id
                INNER JOIN measurement_units inventory_unit ON inventory_unit.id = variant.inventory_unit_id
                INNER JOIN measurement_units consumption_unit ON consumption_unit.id = variant.consumption_unit_id
                WHERE material.is_active = 1
                  AND material.is_stock_item = 1
                  AND material.is_consumable = 1
                  AND variant.is_active = 1
                ORDER BY material.name ASC, variant.code ASC, variant.id ASC
                SQL,
            );
            $movementCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_movements');
            $lotCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_lots');
        } catch (\Throwable $exception) {
            $io->error('No fue posible leer el catálogo de inventario. Verifica migraciones, conexión y permisos de solo lectura antes de continuar.');

            return Command::FAILURE;
        }

        $report = $this->evaluator->evaluate($variants, $movementCount, $lotCount);
        $summary = $report['summary'];
        $io->definitionList(
            ['Variantes activas controladas' => (string) $summary['variants']],
            ['Listas para continuar sin observaciones' => (string) $summary['ready']],
            ['Variantes que requieren revisión' => (string) $summary['requires_review']],
        );

        if ($report['findings'] === []) {
            $io->success('No se detectaron pendientes de configuración en esta revisión. El conteo físico inicial sigue siendo un paso operativo independiente.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (array $finding): array => [
                $finding['severity'],
                $finding['material'],
                $finding['variant'],
                $finding['rule'],
                $finding['action'],
            ],
            $report['findings'],
        );
        $io->table(['Nivel', 'Material', 'Variante', 'Regla', 'Acción requerida'], $rows);
        $io->warning('Resuelve o documenta las observaciones antes de activar saldos, compromisos, consumos y QR por unidad física.');

        if ((bool) $input->getOption('strict')) {
            $io->error('La revisión estricta no permite iniciar el piloto mientras existan observaciones de preparación.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
