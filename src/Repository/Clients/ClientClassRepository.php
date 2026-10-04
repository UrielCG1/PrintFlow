<?php

declare(strict_types=1);

namespace App\Repository\Clients;

use App\Entity\Clients\ClientClass;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class ClientClassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientClass::class);
    }

    public function findByCode(string $code): ?ClientClass
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }
}
