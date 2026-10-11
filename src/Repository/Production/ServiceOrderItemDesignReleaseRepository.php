<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ServiceOrderItemDesignRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceOrderItemDesignRelease> */
final class ServiceOrderItemDesignReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceOrderItemDesignRelease::class);
    }

    public function findCurrent(ServiceOrderItem $item): ?ServiceOrderItemDesignRelease
    {
        return $this->findOneBy(['serviceOrderItem' => $item, 'currentMarker' => 1]);
    }

    public function findCurrentForUpdate(ServiceOrderItem $item): ?ServiceOrderItemDesignRelease
    {
        /** @var ServiceOrderItemDesignRelease|null $release */
        $release = $this->createQueryBuilder('release')
            ->andWhere('release.serviceOrderItem = :item')
            ->andWhere('release.currentMarker = 1')
            ->setParameter('item', $item)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $release;
    }

    public function nextVersion(ServiceOrderItem $item): int
    {
        return (int) $this->createQueryBuilder('release')
            ->select('COALESCE(MAX(release.versionNumber), 0)')
            ->andWhere('release.serviceOrderItem = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult() + 1;
    }

    /** @return list<ServiceOrderItemDesignRelease> */
    public function findHistory(ServiceOrderItem $item): array
    {
        /** @var list<ServiceOrderItemDesignRelease> $releases */
        $releases = $this->createQueryBuilder('release')
            ->innerJoin('release.artworkFile', 'artwork')
            ->innerJoin('release.referenceFile', 'reference')
            ->innerJoin('release.authorizedBy', 'authorizedBy')
            ->addSelect('artwork', 'reference', 'authorizedBy')
            ->andWhere('release.serviceOrderItem = :item')
            ->setParameter('item', $item)
            ->orderBy('release.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $releases;
    }
}
