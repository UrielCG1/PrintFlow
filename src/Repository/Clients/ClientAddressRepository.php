<?php

namespace App\Repository\Clients;

use App\Entity\Clients\Client;
use App\Entity\Clients\ClientAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClientAddress>
 */
final class ClientAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientAddress::class);
    }

    /**
     * @return list<ClientAddress>
     */
    public function findForClient(Client $client): array
    {
        return $this->createQueryBuilder('address')
            ->andWhere('address.client = :client')
            ->setParameter('client', $client)
            ->orderBy('address.isActive', 'DESC')
            ->addOrderBy('address.isDefaultFiscal', 'DESC')
            ->addOrderBy('address.isDefaultDelivery', 'DESC')
            ->addOrderBy('address.label', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ClientAddress>
     */
    public function findOtherActiveDefaultFiscalAddresses(
        Client $client,
        ?ClientAddress $except = null,
    ): array {
        return $this->findOtherActiveDefaultAddresses(
            client: $client,
            defaultField: 'isDefaultFiscal',
            except: $except,
        );
    }

    /**
     * @return list<ClientAddress>
     */
    public function findOtherActiveDefaultDeliveryAddresses(
        Client $client,
        ?ClientAddress $except = null,
    ): array {
        return $this->findOtherActiveDefaultAddresses(
            client: $client,
            defaultField: 'isDefaultDelivery',
            except: $except,
        );
    }

    /**
     * @return list<ClientAddress>
     */
    private function findOtherActiveDefaultAddresses(
        Client $client,
        string $defaultField,
        ?ClientAddress $except,
    ): array {
        $queryBuilder = $this->createQueryBuilder('address')
            ->andWhere('address.client = :client')
            ->andWhere('address.isActive = :isActive')
            ->andWhere(sprintf('address.%s = :isDefault', $defaultField))
            ->setParameter('client', $client)
            ->setParameter('isActive', true)
            ->setParameter('isDefault', true);

        if ($except?->getId() !== null) {
            $queryBuilder
                ->andWhere('address.id != :addressId')
                ->setParameter('addressId', $except->getId());
        }

        return $queryBuilder->getQuery()->getResult();
    }
}