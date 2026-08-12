<?php

namespace App\Repository\Clients;

use App\Application\Clients\ClientPage;
use App\Entity\Clients\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
final class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    /**
     * Recupera el cliente real que puede utilizarse en un borrador de
     * cotización. El manager debe usar esta consulta en lugar de confiar en la
     * entidad que Symfony resolvió a partir del request.
     */
    public function findActiveForQuotation(int $id): ?Client
    {
        return $this->createQueryBuilder('client')
            ->andWhere('client.id = :id')
            ->andWhere('client.isActive = :isActive')
            ->setParameter('id', $id)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function paginateForAdministration(
        string $search,
        ?bool $isActive,
        int $page,
        int $perPage = 20,
    ): ClientPage {
        $queryBuilder = $this->createQueryBuilder('client')
            ->orderBy('client.isActive', 'DESC')
            ->addOrderBy('client.businessName', 'ASC');

        if ($isActive !== null) {
            $queryBuilder
                ->andWhere('client.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(
                    'LOWER(client.businessName) LIKE :search
                    OR LOWER(client.taxId) LIKE :search
                    OR LOWER(client.email) LIKE :search
                    OR client.phone LIKE :search'
                )
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(client.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pageCount);

        /** @var list<Client> $items */
        $items = $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return new ClientPage($items, $total, $page, $pageCount);
    }
}