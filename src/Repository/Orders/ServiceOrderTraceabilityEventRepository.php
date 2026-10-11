<?php

declare(strict_types=1);

namespace App\Repository\Orders;

use App\Entity\Orders\ServiceOrderTraceabilityEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceOrderTraceabilityEvent> */
final class ServiceOrderTraceabilityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOrderTraceabilityEvent::class);
    }

    public function findOneByCommandId(string $commandId): ?ServiceOrderTraceabilityEvent
    {
        return $this->findOneBy(['commandId' => $commandId]);
    }
}
