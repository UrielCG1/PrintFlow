<?php

namespace App\Repository\Clients;

use App\Entity\Clients\Client;
use App\Entity\Clients\ClientContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientContact>
 */
final class ClientContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientContact::class);
    }

    /**
     * @return list<ClientContact>
     */
    public function findForClient(Client $client): array
    {
        return $this->createQueryBuilder('contact')
            ->andWhere('contact.client = :client')
            ->setParameter('client', $client)
            ->orderBy('contact.isActive', 'DESC')
            ->addOrderBy('contact.isPrimary', 'DESC')
            ->addOrderBy('contact.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ClientContact>
     */
    public function findOtherActivePrimaryContacts(
        Client $client,
        ?ClientContact $except = null,
    ): array {
        $queryBuilder = $this->createQueryBuilder('contact')
            ->andWhere('contact.client = :client')
            ->andWhere('contact.isActive = :isActive')
            ->andWhere('contact.isPrimary = :isPrimary')
            ->setParameter('client', $client)
            ->setParameter('isActive', true)
            ->setParameter('isPrimary', true);

        if ($except?->getId() !== null) {
            $queryBuilder
                ->andWhere('contact.id != :contactId')
                ->setParameter('contactId', $except->getId());
        }

        return $queryBuilder->getQuery()->getResult();
    }
}