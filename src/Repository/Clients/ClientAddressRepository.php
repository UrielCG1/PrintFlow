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
            ->addOrderBy('address.isDefault', 'DESC')
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
            addressType: 'FISCAL',
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
            addressType: 'DELIVERY',
            except: $except,
        );
    }

    /**
     * @return list<ClientAddress>
     */
    private function findOtherActiveDefaultAddresses(
        Client $client,
        string $addressType,
        ?ClientAddress $except,
    ): array {
        $queryBuilder = $this->createQueryBuilder('address')
            ->andWhere('address.client = :client')
            ->andWhere('address.isActive = :isActive')
            ->andWhere('address.addressType = :addressType')
            ->andWhere('address.isDefault = :isDefault')
            ->setParameter('client', $client)
            ->setParameter('isActive', true)
            ->setParameter('addressType', $addressType)
            ->setParameter('isDefault', true);

        if ($except?->getId() !== null) {
            $queryBuilder
                ->andWhere('address.id != :addressId')
                ->setParameter('addressId', $except->getId());
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
