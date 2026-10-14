<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCycleCount;
use App\Entity\Inventory\InventoryMovement;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\MaterialVariantCost;
use App\Enum\Inventory\InventoryCycleCountStatus;
use App\Enum\Inventory\InventoryStockUnitStatus;
use App\Enum\Inventory\MovementType;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;

/** Builds a read-only, risk-ranked route for cyclic QR counts. */
final class InventoryCycleCountPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryCycleCountPriorityScorer $scorer,
    ) {
    }

    /** @return list<InventoryCycleCountPriority> */
    public function suggest(int $limit = 12, ?\DateTimeImmutable $now = null): array
    {
        $limit = max(1, min($limit, 50));
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stockUnits = $this->findCountableUnits();
        if ($stockUnits === []) {
            return [];
        }
        $pendingStockUnitIds = $this->pendingStockUnitIds($stockUnits);

        $lastCounts = $this->lastCountsByStockUnit($stockUnits);
        $activityCounts = $this->recentActivityByStockUnit($stockUnits, $now->modify('-30 days'));
        $differenceCounts = $this->recentDifferencesByStockUnit($stockUnits, $now->modify('-180 days'));
        $unitCosts = $this->movingCostByVariant($stockUnits);
        $priorities = [];

        foreach ($stockUnits as $stockUnit) {
            $stockUnitId = $stockUnit->getId();
            $variantId = $stockUnit->getVariant()->getId();
            if ($stockUnitId === null || $variantId === null || isset($pendingStockUnitIds[$stockUnitId])) {
                continue;
            }

            $lastCountedAt = $this->toUtcDate($lastCounts[$stockUnitId] ?? null);
            $estimatedValue = BigDecimal::of($stockUnit->getRemainingQuantity())
                ->multipliedBy($unitCosts[$variantId] ?? '0')
                ->toScale(6)
                ->__toString();
            $assessment = $this->scorer->assess(
                $lastCountedAt,
                $activityCounts[$stockUnitId] ?? 0,
                $differenceCounts[$stockUnitId] ?? 0,
                $estimatedValue,
                $now,
            );
            $priorities[] = new InventoryCycleCountPriority(
                $stockUnit,
                $assessment->score,
                $assessment->level,
                $lastCountedAt,
                $activityCounts[$stockUnitId] ?? 0,
                $differenceCounts[$stockUnitId] ?? 0,
                $assessment->reasons,
            );
        }

        usort($priorities, static function (InventoryCycleCountPriority $left, InventoryCycleCountPriority $right): int {
            $score = $right->score <=> $left->score;
            if ($score !== 0) {
                return $score;
            }

            $leftLastCount = $left->lastCountedAt?->getTimestamp() ?? PHP_INT_MIN;
            $rightLastCount = $right->lastCountedAt?->getTimestamp() ?? PHP_INT_MIN;
            if ($leftLastCount !== $rightLastCount) {
                return $leftLastCount <=> $rightLastCount;
            }

            return $left->stockUnit->getUnitCode() <=> $right->stockUnit->getUnitCode();
        });

        return array_slice($priorities, 0, $limit);
    }

    /** @return list<InventoryStockUnit> */
    private function findCountableUnits(): array
    {
        /** @var list<InventoryStockUnit> $stockUnits */
        $stockUnits = $this->entityManager->createQueryBuilder()
            ->select('stockUnit', 'variant', 'material', 'unit')
            ->from(InventoryStockUnit::class, 'stockUnit')
            ->innerJoin('stockUnit.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->andWhere('stockUnit.status IN (:statuses)')
            ->andWhere('stockUnit.remainingQuantity > :zero')
            ->setParameter('statuses', [InventoryStockUnitStatus::AVAILABLE, InventoryStockUnitStatus::BLOCKED])
            ->setParameter('zero', '0.000000')
            ->orderBy('stockUnit.receivedAt', 'ASC')
            ->addOrderBy('stockUnit.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $stockUnits;
    }

    /** @param list<InventoryStockUnit> $stockUnits @return array<int, string> */
    private function lastCountsByStockUnit(array $stockUnits): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(cycleCount.stockUnit) AS stock_unit_id', 'MAX(cycleCount.countedAt) AS counted_at')
            ->from(InventoryCycleCount::class, 'cycleCount')
            ->andWhere('cycleCount.stockUnit IN (:stockUnits)')
            ->setParameter('stockUnits', $stockUnits)
            ->groupBy('cycleCount.stockUnit')
            ->getQuery()
            ->getScalarResult();

        return $this->indexStringValues($rows, 'stock_unit_id', 'counted_at');
    }

    /** @param list<InventoryStockUnit> $stockUnits @return array<int, true> */
    private function pendingStockUnitIds(array $stockUnits): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(cycleCount.stockUnit) AS stock_unit_id')
            ->from(InventoryCycleCount::class, 'cycleCount')
            ->andWhere('cycleCount.stockUnit IN (:stockUnits)')
            ->andWhere('cycleCount.status = :status')
            ->setParameter('stockUnits', $stockUnits)
            ->setParameter('status', InventoryCycleCountStatus::PENDING_REVIEW)
            ->getQuery()
            ->getScalarResult();
        $ids = [];
        foreach ($rows as $row) {
            if (isset($row['stock_unit_id'])) {
                $ids[(int) $row['stock_unit_id']] = true;
            }
        }

        return $ids;
    }

    /** @param list<InventoryStockUnit> $stockUnits @return array<int, int> */
    private function recentActivityByStockUnit(array $stockUnits, \DateTimeImmutable $since): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(movement.stockUnit) AS stock_unit_id', 'COUNT(movement.id) AS event_count')
            ->from(InventoryMovement::class, 'movement')
            ->andWhere('movement.stockUnit IN (:stockUnits)')
            ->andWhere('movement.type IN (:types)')
            ->andWhere('movement.occurredAt >= :since')
            ->setParameter('stockUnits', $stockUnits)
            ->setParameter('types', [MovementType::PRODUCTION_CONSUMPTION, MovementType::WASTE, MovementType::RETURN])
            ->setParameter('since', $since)
            ->groupBy('movement.stockUnit')
            ->getQuery()
            ->getScalarResult();

        return $this->indexIntegerValues($rows, 'stock_unit_id', 'event_count');
    }

    /** @param list<InventoryStockUnit> $stockUnits @return array<int, int> */
    private function recentDifferencesByStockUnit(array $stockUnits, \DateTimeImmutable $since): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(cycleCount.stockUnit) AS stock_unit_id', 'COUNT(cycleCount.id) AS difference_count')
            ->from(InventoryCycleCount::class, 'cycleCount')
            ->andWhere('cycleCount.stockUnit IN (:stockUnits)')
            ->andWhere('cycleCount.status = :status')
            ->andWhere('cycleCount.differenceQuantity <> :zero')
            ->andWhere('cycleCount.reviewedAt >= :since')
            ->setParameter('stockUnits', $stockUnits)
            ->setParameter('status', InventoryCycleCountStatus::APPROVED)
            ->setParameter('zero', '0.000000')
            ->setParameter('since', $since)
            ->groupBy('cycleCount.stockUnit')
            ->getQuery()
            ->getScalarResult();

        return $this->indexIntegerValues($rows, 'stock_unit_id', 'difference_count');
    }

    /** @param list<InventoryStockUnit> $stockUnits @return array<int, string> */
    private function movingCostByVariant(array $stockUnits): array
    {
        $variants = array_map(static fn (InventoryStockUnit $stockUnit) => $stockUnit->getVariant(), $stockUnits);
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(balance.variant) AS variant_id', 'balance.movingAverageCostMxn AS unit_cost')
            ->from(MaterialVariantCost::class, 'balance')
            ->andWhere('balance.variant IN (:variants)')
            ->setParameter('variants', $variants)
            ->getQuery()
            ->getScalarResult();

        return $this->indexStringValues($rows, 'variant_id', 'unit_cost');
    }

    /** @param list<array<string, mixed>> $rows @return array<int, int> */
    private function indexIntegerValues(array $rows, string $idField, string $valueField): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (!isset($row[$idField])) {
                continue;
            }
            $values[(int) $row[$idField]] = (int) ($row[$valueField] ?? 0);
        }

        return $values;
    }

    /** @param list<array<string, mixed>> $rows @return array<int, string> */
    private function indexStringValues(array $rows, string $idField, string $valueField): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (!isset($row[$idField])) {
                continue;
            }
            $values[(int) $row[$idField]] = (string) ($row[$valueField] ?? '');
        }

        return $values;
    }

    private function toUtcDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
