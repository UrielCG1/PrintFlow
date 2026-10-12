<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\InventoryCommitmentSubstitution;
use App\Entity\Orders\ServiceOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InventoryCommitmentSubstitution> */
final class InventoryCommitmentSubstitutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, InventoryCommitmentSubstitution::class); }
    /** @return list<InventoryCommitmentSubstitution> */
    public function findForOrder(ServiceOrder $order): array
    {
        /** @var list<InventoryCommitmentSubstitution> $substitutions */
        $substitutions = $this->createQueryBuilder('substitution')->innerJoin('substitution.originalCommitment', 'original')->innerJoin('substitution.substituteCommitment', 'substitute')->addSelect('original', 'substitute')->andWhere('original.serviceOrder = :order')->setParameter('order', $order)->orderBy('substitution.createdAt', 'ASC')->getQuery()->getResult();
        return $substitutions;
    }
}
