<?php

namespace App\Application\Access;

use App\DTO\Access\CreateRoleData;
use App\DTO\Access\UpdateRoleData;
use App\Entity\Users\Permission;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Repository\Users\PermissionRepository;
use App\Repository\Users\RoleRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

class RoleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoleRepository $roleRepository,
        private readonly PermissionRepository $permissionRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(CreateRoleData $data, User $actor): Role
    {
        $code = strtoupper(trim($data->code));

        if (!preg_match('/^ROLE_[A-Z0-9_]{3,75}$/', $code)) {
            throw new \DomainException(
                'El código debe iniciar con ROLE_ y usar solo letras mayúsculas, números o guion bajo.',
            );
        }

        if ($code === 'ROLE_ADMIN') {
            throw new \DomainException(
                'ROLE_ADMIN es un rol reservado del sistema.',
            );
        }

        if ($this->roleRepository->findOneBy(['code' => $code]) instanceof Role) {
            throw new \DomainException('Ya existe un rol con ese código.');
        }

        $permissions = $this->validatePermissions($data->permissions);

        return $this->entityManager->wrapInTransaction(function () use (
            $code,
            $data,
            $permissions,
            $actor,
        ): Role {
            $role = (new Role())
                ->setCode($code)
                ->setName($data->name)
                ->setDescription($data->description)
                ->setIsSystem(false)
                ->setIsActive(true);

            $this->syncPermissions($role, $permissions);

            $this->entityManager->persist($role);
            $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'role.created',
                entityType: 'roles',
                entityId: $role->getId(),
                newValues: $this->snapshot($role),
            );

            $this->entityManager->flush();

            return $role;
        });
    }

    public function update(Role $role, UpdateRoleData $data, User $actor): void
    {
        $this->assertRoleCanBeModified($role);

        $oldValues = $this->snapshot($role);
        $permissions = $this->validatePermissions($data->permissions);

        $role
            ->setName($data->name)
            ->setDescription($data->description);

        $this->syncPermissions($role, $permissions);

        $this->auditLogger->record(
            actor: $actor,
            action: 'role.updated',
            entityType: 'roles',
            entityId: $role->getId(),
            oldValues: $oldValues,
            newValues: $this->snapshot($role),
        );

        $this->entityManager->flush();
    }

    /**
     * @param Permission[] $permissions
     *
     * @return Permission[]
     */
    private function validatePermissions(array $permissions): array
    {
        if ($permissions === []) {
            throw new \DomainException(
                'Debes asignar al menos un permiso al rol.',
            );
        }

        foreach ($permissions as $permission) {
            if (!$permission instanceof Permission || !$permission->isActive()) {
                throw new \DomainException(
                    'Solo se pueden asignar permisos activos.',
                );
            }
        }

        return array_values(array_unique($permissions, SORT_REGULAR));
    }

    /**
     * @param Permission[] $permissions
     */
    private function syncPermissions(Role $role, array $permissions): void
    {
        foreach ($role->getPermissions()->toArray() as $assignedPermission) {
            if (!in_array($assignedPermission, $permissions, true)) {
                $role->removePermission($assignedPermission);
            }
        }

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
    }

    private function assertRoleCanBeModified(Role $role): void
    {
        if ($role->getCode() === 'ROLE_ADMIN') {
            throw new \DomainException(
                'El rol Administrador está protegido y siempre conserva todos los permisos.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Role $role): array
    {
        $permissions = array_map(
            static fn (Permission $permission): string => $permission->getCode(),
            $role->getPermissions()->toArray(),
        );

        sort($permissions);

        return [
            'code' => $role->getCode(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'isSystem' => $role->isSystem(),
            'isActive' => $role->isActive(),
            'permissions' => $permissions,
        ];
    }
}