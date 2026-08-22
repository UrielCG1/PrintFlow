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
            ->innerJoin('contact.contact', 'person')
            ->andWhere('contact.client = :client')
            ->setParameter('client', $client)
            ->orderBy('contact.isActive', 'DESC')
            ->addOrderBy('contact.isPrimary', 'DESC')
            ->addOrderBy('person.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ClientContact>
     */
    public function findActiveForClient(Client $client): array
    {
        return $this->createQueryBuilder('contact')
            ->innerJoin('contact.contact', 'person')
            ->andWhere('contact.client = :client')
            ->andWhere('contact.isActive = :isActive')
            ->setParameter('client', $client)
            ->setParameter('isActive', true)
            ->orderBy('contact.isPrimary', 'DESC')
            ->addOrderBy('person.firstName', 'ASC')
            ->addOrderBy('person.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForQuotation(
        int $id,
        Client $client,
    ): ?ClientContact {
        return $this->createQueryBuilder('contact')
            ->andWhere('contact.id = :id')
            ->andWhere('contact.client = :client')
            ->andWhere('contact.isActive = :isActive')
            ->setParameter('id', $id)
            ->setParameter('client', $client)
            ->setParameter('isActive', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveRequesterByPublicNumber(string $publicNumber): ?ClientContact
    {
        return $this->createQueryBuilder('contact')
            ->innerJoin('contact.contact', 'person')
            ->innerJoin('contact.client', 'client')
            ->addSelect('person', 'client')
            ->andWhere('UPPER(contact.publicNumber) = :publicNumber')
            ->andWhere('contact.isActive = true')
            ->andWhere('contact.canRequestProducts = true')
            ->andWhere('person.isActive = true')
            ->andWhere('client.isActive = true')
            ->setParameter('publicNumber', strtoupper(trim($publicNumber)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ClientContact> */
    public function findActiveRequestersByEmail(string $email): array
    {
        $email = strtolower(trim($email));
        return $this->createQueryBuilder('contact')
            ->innerJoin('contact.contact', 'person')
            ->innerJoin('contact.client', 'client')
            ->addSelect('person', 'client')
            ->andWhere('contact.isActive = true')
            ->andWhere('contact.canRequestProducts = true')
            ->andWhere('person.isActive = true')
            ->andWhere('client.isActive = true')
            ->andWhere('LOWER(contact.businessEmail) = :email OR LOWER(person.personalEmail) = :email')
            ->setParameter('email', $email)
            ->orderBy('contact.isPrimary', 'DESC')
            ->addOrderBy('contact.id', 'ASC')
            ->getQuery()->getResult();
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
