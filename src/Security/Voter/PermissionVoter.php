<?php

namespace App\Security\Voter;

use App\Entity\Users\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject === null
            && preg_match(
                '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/',
                $attribute,
            ) === 1;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof UserInterface || !$user instanceof User) {
            return false;
        }

        if (!$user->isActive() || $user->getDeletedAt() !== null) {
            return false;
        }

        foreach ($user->getAssignedRoles() as $role) {
            if ($role->isActive() && $role->hasPermission($attribute)) {
                return true;
            }
        }

        return false;
    }
}