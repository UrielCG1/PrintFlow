<?php

declare(strict_types=1);

namespace App\Repository\Inventory;

use App\Entity\Inventory\MaterialSubstitutionRule;
use App\Entity\Materials\MaterialVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MaterialSubstitutionRule> */
final class MaterialSubstitutionRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, MaterialSubstitutionRule::class); }
    /** @return list<MaterialSubstitutionRule> */
    public function findActiveForOriginal(MaterialVariant $variant): array
    {
        /** @var list<MaterialSubstitutionRule> $rules */
        $rules = $this->createQueryBuilder('rule')->innerJoin('rule.substituteVariant', 'substitute')->innerJoin('substitute.material', 'material')->innerJoin('substitute.inventoryUnit', 'unit')->addSelect('substitute', 'material', 'unit')->andWhere('rule.originalVariant = :variant')->andWhere('rule.isActive = true')->andWhere('substitute.isActive = true')->andWhere('material.isActive = true')->setParameter('variant', $variant)->orderBy('material.name', 'ASC')->addOrderBy('substitute.code', 'ASC')->getQuery()->getResult();
        return $rules;
    }
    /** @return list<MaterialSubstitutionRule> */
    public function findAllForConfiguration(): array
    {
        /** @var list<MaterialSubstitutionRule> $rules */
        $rules = $this->createQueryBuilder('rule')->innerJoin('rule.originalVariant', 'original')->innerJoin('original.material', 'originalMaterial')->innerJoin('rule.substituteVariant', 'substitute')->innerJoin('substitute.material', 'substituteMaterial')->addSelect('original', 'originalMaterial', 'substitute', 'substituteMaterial')->orderBy('rule.isActive', 'DESC')->addOrderBy('originalMaterial.name', 'ASC')->getQuery()->getResult();
        return $rules;
    }
}
