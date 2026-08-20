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

    public function findPublicMatch(string $name, string $email, string $phone): ?Client
    {
        $email = strtolower(trim($email));
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT DISTINCT c.id, CASE WHEN LOWER(c.business_name)=:name OR LOWER(CONCAT(contact.first_name,' ',COALESCE(contact.last_name,'')))=:name THEN 0 ELSE 1 END AS name_match FROM clients c LEFT JOIN client_phones cp ON cp.client_id=c.id AND cp.is_active=1 LEFT JOIN phones client_phone ON client_phone.id=cp.phone_id LEFT JOIN client_contacts cc ON cc.client_id=c.id AND cc.is_active=1 LEFT JOIN contacts contact ON contact.id=cc.contact_id LEFT JOIN contact_phones cop ON cop.contact_id=contact.id AND cop.is_active=1 LEFT JOIN phones contact_phone ON contact_phone.id=cop.phone_id WHERE c.is_active=1 AND ((:email <> '' AND (LOWER(c.email)=:email OR LOWER(cc.business_email)=:email OR LOWER(contact.personal_email)=:email)) OR (:phone <> '' AND (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(client_phone.number,' ',''),'-',''),'(',''),')',''),'+','')=:phone OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(contact_phone.number,' ',''),'-',''),'(',''),')',''),'+','')=:phone))) ORDER BY name_match LIMIT 1",
            ['name'=>mb_strtolower(trim($name)),'email' => $email, 'phone' => $digits],
        );
        if ($id !== false) { return $this->find((int) $id); }

        $normalizedName = $this->normalizePublicName($name);
        if ($normalizedName === '') { return null; }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, business_name FROM clients WHERE is_active = 1',
        );
        foreach ($rows as $row) {
            if ($this->normalizePublicName((string) $row['business_name']) === $normalizedName) {
                return $this->find((int) $row['id']);
            }
        }

        return null;
    }

    private function normalizePublicName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        if (function_exists('transliterator_transliterate')) {
            $name = (string) transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $name);
        } else {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($ascii !== false) { $name = $ascii; }
        }
        $name = preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name;
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
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
