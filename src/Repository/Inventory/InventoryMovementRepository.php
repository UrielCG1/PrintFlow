<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryMovement;
use App\Enum\Inventory\MovementType;
use Brick\Math\BigDecimal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryMovement> */
final class InventoryMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryMovement::class);
    }

    public function countWasteEventsSince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('movement')
            ->select('COUNT(movement.id)')
            ->andWhere('movement.type = :type')
            ->andWhere('movement.occurredAt >= :since')
            ->setParameter('type', MovementType::WASTE)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{variant_id: string, material_name: string, variant_code: string, unit_symbol: string, waste_quantity: string}>
     */
    public function findWasteSummarySince(\DateTimeInterface $since, int $limit = 10): array
    {
        /** @var list<array{variant_id: string, material_name: string, variant_code: string, unit_symbol: string, waste_quantity: string}> $rows */
        $rows = $this->createQueryBuilder('movement')
            ->select('IDENTITY(movement.variant) AS variant_id')
            ->addSelect('material.name AS material_name')
            ->addSelect('variant.code AS variant_code')
            ->addSelect('unit.symbol AS unit_symbol')
            ->addSelect('SUM(movement.quantity) AS waste_quantity')
            ->innerJoin('movement.variant', 'variant')
            ->innerJoin('variant.material', 'material')
            ->innerJoin('variant.inventoryUnit', 'unit')
            ->andWhere('movement.type = :type')
            ->andWhere('movement.occurredAt >= :since')
            ->setParameter('type', MovementType::WASTE)
            ->setParameter('since', $since)
            ->groupBy('variant.id, material.name, variant.code, unit.symbol')
            ->orderBy('waste_quantity', 'ASC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as &$row) {
            $row['waste_quantity'] = BigDecimal::of($row['waste_quantity'])->abs()->toScale(6)->__toString();
        }
        unset($row);

        return $rows;
    }
}
