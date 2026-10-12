<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryReceipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryReceipt> */
final class InventoryReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryReceipt::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?InventoryReceipt
    {
        return $this->findOneBy(['idempotencyKey' => trim($idempotencyKey)]);
    }
}
