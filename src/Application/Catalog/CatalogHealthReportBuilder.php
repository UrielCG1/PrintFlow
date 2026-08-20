<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CatalogHealthSeverity;
use App\Repository\Catalog\CommercialCategoryRepository;
use App\Repository\Catalog\CommercialCharacteristicOptionRepository;
use App\Repository\Catalog\CommercialCharacteristicRepository;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Repository\Catalog\CommercialItemRepository;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialRepository;

/** Construye una fotografía de solo lectura sobre la salud del catálogo. */
final class CatalogHealthReportBuilder
{
    public function __construct(
        private readonly CommercialItemRepository $itemRepository,
        private readonly CommercialCategoryRepository $categoryRepository,
        private readonly MeasurementUnitRepository $measurementUnitRepository,
        private readonly CommercialCharacteristicRepository $characteristicRepository,
        private readonly CommercialCharacteristicOptionRepository $optionRepository,
        private readonly CommercialItemCharacteristicRepository $configurationRepository,
        private readonly MaterialRepository $materialRepository,
        private readonly CatalogHealthEvaluator $evaluator,
    ) {
    }

    /**
     * @return array{
     *     findings: list<CatalogHealthFinding>,
     *     summary: array{monitored: int, findings: int, healthy: int, incomplete: int, attention: int, unused: int},
     *     areas: array<string, array{label: string, total: int, findings: int}>
     * }
     */
    public function build(): array
    {
        $items = $this->itemRepository->findAllForHealthAssessment();
        $categories = $this->categoryRepository->findBy([], [
            'isActive' => 'DESC',
            'displayOrder' => 'ASC',
            'name' => 'ASC',
            'id' => 'ASC',
        ]);
        $units = $this->measurementUnitRepository->findAllForHealthAssessment();
        $characteristics = $this->characteristicRepository->findBy([], [
            'isActive' => 'DESC',
            'displayOrder' => 'ASC',
            'name' => 'ASC',
            'id' => 'ASC',
        ]);

        $itemIds = $this->ids($items);
        $configurations = $this->configurationRepository->findForItemIdsForHealthAssessment($itemIds);
        $configurationsByItem = $this->groupConfigurationsByItem($configurations);

        $categoryIds = $this->ids($categories);
        $categoryUsage = $this->itemRepository->summarizeUsageByCategoryIds($categoryIds);

        $unitIds = $this->ids($units);
        $commercialUnitUsage = $this->itemRepository->summarizeUsageByMeasurementUnitIds($unitIds);
        $materialUnitUsage = $this->materialRepository->summarizeUsageByMeasurementUnitIds($unitIds);
        $activeDerivedCounts = $this->measurementUnitRepository->countActiveDerivedByBaseUnitIds($unitIds);

        $characteristicIds = $this->ids($characteristics);
        $optionSummary = $this->optionRepository->summarizeByCharacteristicIds($characteristicIds);
        $characteristicUsage = $this->configurationRepository->summarizeUsageByCharacteristicIds($characteristicIds);

        $findings = [];

        foreach ($items as $item) {
            $finding = $this->evaluator->evaluateItem(
                $item,
                $configurationsByItem[(int) $item->getId()] ?? [],
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        foreach ($categories as $category) {
            $finding = $this->evaluator->evaluateCategory(
                $category,
                $categoryUsage[(int) $category->getId()] ?? ['total' => 0, 'active' => 0],
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        foreach ($units as $unit) {
            $unitId = (int) $unit->getId();
            $finding = $this->evaluator->evaluateUnit(
                $unit,
                $commercialUnitUsage[$unitId] ?? ['total' => 0, 'active' => 0],
                $materialUnitUsage[$unitId] ?? ['total' => 0, 'active' => 0],
                $activeDerivedCounts[$unitId] ?? 0,
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        foreach ($characteristics as $characteristic) {
            $characteristicId = (int) $characteristic->getId();
            $finding = $this->evaluator->evaluateCharacteristic(
                $characteristic,
                $optionSummary[$characteristicId] ?? ['total' => 0, 'active' => 0],
                $characteristicUsage[$characteristicId] ?? ['total' => 0, 'active' => 0],
            );
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        usort($findings, static function (CatalogHealthFinding $left, CatalogHealthFinding $right): int {
            $byPriority = $right->severity->priority() <=> $left->severity->priority();
            if ($byPriority !== 0) {
                return $byPriority;
            }

            $byArea = strcmp($left->area, $right->area);
            if ($byArea !== 0) {
                return $byArea;
            }

            return strcasecmp($left->entityName, $right->entityName);
        });

        $areaDefinitions = [
            'items' => ['label' => 'Productos y servicios', 'total' => count($items), 'findings' => 0],
            'categories' => ['label' => 'Categorías', 'total' => count($categories), 'findings' => 0],
            'units' => ['label' => 'Unidades de medida', 'total' => count($units), 'findings' => 0],
            'characteristics' => ['label' => 'Características', 'total' => count($characteristics), 'findings' => 0],
        ];

        $severityCounts = [
            CatalogHealthSeverity::INCOMPLETE->value => 0,
            CatalogHealthSeverity::ATTENTION->value => 0,
            CatalogHealthSeverity::UNUSED->value => 0,
        ];

        foreach ($findings as $finding) {
            ++$severityCounts[$finding->severity->value];
            ++$areaDefinitions[$finding->area]['findings'];
        }

        $monitored = count($items) + count($categories) + count($units) + count($characteristics);

        return [
            'findings' => $findings,
            'summary' => [
                'monitored' => $monitored,
                'findings' => count($findings),
                'healthy' => max(0, $monitored - count($findings)),
                'incomplete' => $severityCounts[CatalogHealthSeverity::INCOMPLETE->value],
                'attention' => $severityCounts[CatalogHealthSeverity::ATTENTION->value],
                'unused' => $severityCounts[CatalogHealthSeverity::UNUSED->value],
            ],
            'areas' => $areaDefinitions,
        ];
    }

    /**
     * @param list<object> $entities
     * @return list<int>
     */
    private function ids(array $entities): array
    {
        $ids = [];

        foreach ($entities as $entity) {
            if (!method_exists($entity, 'getId')) {
                continue;
            }

            $id = $entity->getId();
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<CommercialItemCharacteristic> $configurations
     * @return array<int, list<CommercialItemCharacteristic>>
     */
    private function groupConfigurationsByItem(array $configurations): array
    {
        $grouped = [];

        foreach ($configurations as $configuration) {
            $itemId = (int) $configuration->getCommercialItem()->getId();
            $grouped[$itemId] ??= [];
            $grouped[$itemId][] = $configuration;
        }

        return $grouped;
    }
}
