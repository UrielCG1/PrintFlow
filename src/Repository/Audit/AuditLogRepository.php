<?php

namespace App\Repository\Audit;

use App\Entity\Audit\AuditLog;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    public function paginateForAdministration(
        ?string $search,
        ?int $actorId,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $page,
        int $limit = 50,
    ): Paginator {
        $queryBuilder = $this->createQueryBuilder('log')
            ->leftJoin('log.actor', 'actor')
            ->addSelect('actor')
            ->orderBy('log.createdAt', 'DESC');

        if ($search !== null && $search !== '') {
            $queryBuilder
                ->andWhere(
                    $queryBuilder->expr()->orX(
                        'LOWER(log.action) LIKE :search',
                        'LOWER(log.entityType) LIKE :search',
                        'LOWER(actor.fullName) LIKE :search',
                        'LOWER(actor.username) LIKE :search',
                    ),
                )
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($actorId !== null) {
            $queryBuilder
                ->andWhere('actor.id = :actorId')
                ->setParameter('actorId', $actorId);
        }

        if ($from !== null) {
            $queryBuilder
                ->andWhere('log.createdAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to !== null) {
            $queryBuilder
                ->andWhere('log.createdAt <= :to')
                ->setParameter('to', $to);
        }

        $queryBuilder
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($queryBuilder->getQuery(), true);
    }
}