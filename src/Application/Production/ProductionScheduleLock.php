<?php

declare(strict_types=1);

namespace App\Application\Production;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

/** Serializes the short critical sections that create or move machine reservations. */
final class ProductionScheduleLock
{
    private const LOCK_NAME = 'printflow:production-schedule';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function acquire(int $timeoutSeconds = 15): bool
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return false;
        }

        if ((int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, $timeoutSeconds]) !== 1) {
            throw new \DomainException('La agenda está siendo actualizada por otra persona. Inténtalo de nuevo.');
        }

        return true;
    }

    public function release(bool $acquired): void
    {
        if ($acquired) {
            $this->entityManager->getConnection()->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    /** @template T @param callable(): T $action @return T */
    public function synchronized(callable $action): mixed
    {
        $acquired = $this->acquire();
        try {
            return $action();
        } finally {
            $this->release($acquired);
        }
    }
}
