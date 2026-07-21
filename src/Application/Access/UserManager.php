<?php

namespace App\Application\Access;

use App\DTO\Access\CreateUserData;
use App\DTO\Access\UpdateUserData;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Repository\Users\UserRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(CreateUserData $data, User $actor): User
    {
        $username = strtolower(trim($data->username));
        $email = strtolower(trim($data->email));
        $roles = $this->validateRoles($data->roles);

        $this->assertUniqueCredentials($username, $email);

        if (mb_strlen($data->plainPassword) < 12) {
            throw new \DomainException(
                'La contraseña temporal debe tener al menos 12 caracteres.',
            );
        }

        return $this->entityManager->wrapInTransaction(function () use (
            $data,
            $actor,
            $username,
            $email,
            $roles,
        ): User {
            $user = (new User())
                ->setFullName($data->fullName)
                ->setUsername($username)
                ->setEmail($email)
                ->setPhone($data->phone)
                ->setMustChangePassword(true);

            $user->setPassword(
                $this->passwordHasher->hashPassword($user, $data->plainPassword),
            );

            $this->syncRoles($user, $roles);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'user.created',
                entityType: 'users',
                entityId: $user->getId(),
                newValues: $this->snapshot($user),
            );

            $this->entityManager->flush();

            return $user;
        });
    }

    public function update(User $user, UpdateUserData $data, User $actor): void
    {
        $username = strtolower(trim($data->username));
        $email = strtolower(trim($data->email));
        $roles = $this->validateRoles($data->roles);

        $this->assertUniqueCredentials($username, $email, $user);

        $oldValues = $this->snapshot($user);
        $this->assertCanKeepAdministrator($user, $roles, $user->isActive());

        if (
            $actor->getId() === $user->getId()
            && $this->roleCodes($user->getAssignedRoles()->toArray()) !== $this->roleCodes($roles)
        ) {
            throw new \DomainException(
                'No puedes modificar tus propios roles.',
            );
        }

        $user
            ->setFullName($data->fullName)
            ->setUsername($username)
            ->setEmail($email)
            ->setPhone($data->phone);

        $this->syncRoles($user, $roles);

        $this->auditLogger->record(
            actor: $actor,
            action: 'user.updated',
            entityType: 'users',
            entityId: $user->getId(),
            oldValues: $oldValues,
            newValues: $this->snapshot($user),
        );

        $this->entityManager->flush();
    }

    public function setActive(User $user, bool $isActive, User $actor): void
    {
        if ($actor->getId() === $user->getId() && !$isActive) {
            throw new \DomainException(
                'No puedes desactivar tu propia cuenta.',
            );
        }

        $this->assertCanKeepAdministrator(
            $user,
            $user->getAssignedRoles()->toArray(),
            $isActive,
        );

        $oldValues = $this->snapshot($user);

        $user->setIsActive($isActive);

        $this->auditLogger->record(
            actor: $actor,
            action: $isActive ? 'user.activated' : 'user.deactivated',
            entityType: 'users',
            entityId: $user->getId(),
            oldValues: $oldValues,
            newValues: $this->snapshot($user),
        );

        $this->entityManager->flush();
    }

    public function resetPassword(
        User $user,
        string $temporaryPassword,
        User $actor,
    ): void {
        if (mb_strlen($temporaryPassword) < 12) {
            throw new \DomainException(
                'La contraseña temporal debe tener al menos 12 caracteres.',
            );
        }

        $user
            ->setPassword(
                $this->passwordHasher->hashPassword($user, $temporaryPassword),
            )
            ->setMustChangePassword(true);

        $this->auditLogger->record(
            actor: $actor,
            action: 'user.password_reset',
            entityType: 'users',
            entityId: $user->getId(),
            newValues: [
                'mustChangePassword' => true,
            ],
        );

        $this->entityManager->flush();
    }

    public function changeOwnPassword(
        User $user,
        string $currentPassword,
        string $newPassword,
    ): void {
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            throw new \DomainException('La contraseña actual no es correcta.');
        }

        if (mb_strlen($newPassword) < 12) {
            throw new \DomainException(
                'La nueva contraseña debe tener al menos 12 caracteres.',
            );
        }

        $user
            ->setPassword(
                $this->passwordHasher->hashPassword($user, $newPassword),
            )
            ->setMustChangePassword(false);

        $this->auditLogger->record(
            actor: $user,
            action: 'user.password_changed',
            entityType: 'users',
            entityId: $user->getId(),
            newValues: [
                'mustChangePassword' => false,
            ],
        );

        $this->entityManager->flush();
    }

    private function assertUniqueCredentials(
        string $username,
        string $email,
        ?User $currentUser = null,
    ): void {
        $usernameOwner = $this->userRepository->findOneBy(['username' => $username]);
        $emailOwner = $this->userRepository->findOneBy(['email' => $email]);

        if (
            $usernameOwner instanceof User
            && $usernameOwner->getId() !== $currentUser?->getId()
        ) {
            throw new \DomainException('El nombre de usuario ya está en uso.');
        }

        if (
            $emailOwner instanceof User
            && $emailOwner->getId() !== $currentUser?->getId()
        ) {
            throw new \DomainException('El correo electrónico ya está en uso.');
        }
    }

    /**
     * @param Role[] $roles
     *
     * @return Role[]
     */
    private function validateRoles(array $roles): array
    {
        if ($roles === []) {
            throw new \DomainException('Debes asignar al menos un rol.');
        }

        foreach ($roles as $role) {
            if (!$role instanceof Role || !$role->isActive()) {
                throw new \DomainException(
                    'Solo es posible asignar roles activos.',
                );
            }
        }

        return array_values(array_unique($roles, SORT_REGULAR));
    }

    /**
     * @param Role[] $roles
     */
    private function syncRoles(User $user, array $roles): void
    {
        foreach ($user->getAssignedRoles()->toArray() as $assignedRole) {
            if (!in_array($assignedRole, $roles, true)) {
                $user->removeRole($assignedRole);
            }
        }

        foreach ($roles as $role) {
            $user->addRole($role);
        }
    }

    /**
     * @param Role[] $desiredRoles
     */
    private function assertCanKeepAdministrator(
        User $user,
        array $desiredRoles,
        bool $willRemainActive,
    ): void {
        $isCurrentAdministrator = in_array(
            'ROLE_ADMIN',
            $this->roleCodes($user->getAssignedRoles()->toArray()),
            true,
        );

        $willRemainAdministrator = in_array(
            'ROLE_ADMIN',
            $this->roleCodes($desiredRoles),
            true,
        );

        if (
            $isCurrentAdministrator
            && (!$willRemainActive || !$willRemainAdministrator)
            && $this->userRepository->countOtherActiveAdministrators($user) === 0
        ) {
            throw new \DomainException(
                'Debe existir al menos un administrador activo en el sistema.',
            );
        }
    }

    /**
     * @param Role[] $roles
     *
     * @return string[]
     */
    private function roleCodes(array $roles): array
    {
        $codes = array_map(
            static fn (Role $role): string => $role->getCode(),
            $roles,
        );

        sort($codes);

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'fullName' => $user->getFullName(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'isActive' => $user->isActive(),
            'mustChangePassword' => $user->mustChangePassword(),
            'roles' => $this->roleCodes(
                $user->getAssignedRoles()->toArray(),
            ),
        ];
    }
}