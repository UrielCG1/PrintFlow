<?php

namespace App\Repository\Users;

use App\Entity\Users\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(
        PasswordAuthenticatedUserInterface $user,
        string $newHashedPassword,
    ): void {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(
                sprintf('Instances of "%s" are not supported.', $user::class),
            );
        }

        $user->setPassword($newHashedPassword);

        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function countOtherActiveAdministrators(User $excludedUser): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(user.id)')
            ->innerJoin('user.assignedRoles', 'role')
            ->andWhere('user.id != :excludedUserId')
            ->andWhere('user.isActive = :isActive')
            ->andWhere('user.deletedAt IS NULL')
            ->andWhere('role.code = :adminRole')
            ->setParameter('excludedUserId', $excludedUser->getId())
            ->setParameter('isActive', true)
            ->setParameter('adminRole', 'ROLE_ADMIN')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<User> */
    public function findActiveAdministrators(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('user')
            ->select('DISTINCT user')
            ->innerJoin('user.assignedRoles', 'role')
            ->andWhere('user.isActive = true')
            ->andWhere('user.deletedAt IS NULL')
            ->andWhere('role.code = :adminRole')
            ->andWhere('role.isActive = true')
            ->setParameter('adminRole', 'ROLE_ADMIN')
            ->orderBy('user.id', 'ASC')
            ->getQuery()->getResult();

        return $users;
    }

    /** @return list<User> */
    public function findActiveProductionOperators(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('user')
            ->innerJoin('user.assignedRoles', 'role')
            ->andWhere('user.isActive = true')
            ->andWhere('user.deletedAt IS NULL')
            ->andWhere('role.code = :operatorRole')
            ->setParameter('operatorRole', 'ROLE_OPERATOR')
            ->orderBy('user.fullName', 'ASC')
            ->getQuery()->getResult();
        return $users;
    }

    public function paginateForAdministration(
        ?string $search,
        int $page,
        int $limit = 20,
    ): Paginator {
        $builder = $this->createQueryBuilder('user')
            ->leftJoin('user.assignedRoles', 'role')
            ->addSelect('role')
            ->andWhere('user.deletedAt IS NULL')
            ->orderBy('user.fullName', 'ASC');

        if ($search !== null && $search !== '') {
            $builder
                ->andWhere(
                    'LOWER(user.fullName) LIKE :search
                    OR LOWER(user.username) LIKE :search
                    OR LOWER(user.email) LIKE :search',
                )
                ->setParameter('search', '%'.strtolower($search).'%');
        }

        $query = $builder
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();

        return new Paginator($query, true);
    }
}
