<?php

namespace App\Security;

use App\Entity\Users\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive() || $user->getDeletedAt() !== null) {
            throw new CustomUserMessageAccountStatusException(
                'No es posible acceder con esta cuenta.',
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}