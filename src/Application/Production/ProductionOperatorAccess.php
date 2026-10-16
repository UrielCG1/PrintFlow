<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Users\User;

/** La asignación limita a operadores; un administrador activo puede intervenir sin cambiarla. */
final class ProductionOperatorAccess
{
    public static function canActFor(?User $assigned, User $actor): bool
    {
        if (!$actor->isActive() || $actor->getDeletedAt() !== null) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $actor->getRoles(), true)) {
            return true;
        }

        return $assigned !== null
            && ($assigned === $actor || ($assigned->getId() !== null && $assigned->getId() === $actor->getId()));
    }
}
