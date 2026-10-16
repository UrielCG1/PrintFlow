<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionOperatorAccess;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use PHPUnit\Framework\TestCase;

final class ProductionOperatorAccessTest extends TestCase
{
    public function testActiveAdministratorMayActWithoutChangingTheAssignedOperator(): void
    {
        $assigned = $this->user('assigned');
        $admin = $this->user('admin')->addRole((new Role())->setCode('ROLE_ADMIN')->setName('Administrador'));

        self::assertTrue(ProductionOperatorAccess::canActFor(null, $admin));
        self::assertTrue(ProductionOperatorAccess::canActFor($assigned, $admin));
        self::assertTrue(ProductionOperatorAccess::canActFor($assigned, $assigned));
        self::assertFalse(ProductionOperatorAccess::canActFor($assigned, $this->user('other')));
        self::assertFalse(ProductionOperatorAccess::canActFor(null, $this->user('other')));
    }

    public function testDisabledAdministratorAccountOrRoleCannotBypassAssignment(): void
    {
        $assigned = $this->user('assigned');
        $inactiveRole = (new Role())->setCode('ROLE_ADMIN')->setName('Administrador')->setIsActive(false);
        $roleDisabled = $this->user('role-disabled')->addRole($inactiveRole);
        $accountDisabled = $this->user('account-disabled')->addRole((new Role())->setCode('ROLE_ADMIN')->setName('Administrador'));
        $accountDisabled->setIsActive(false);

        self::assertFalse(ProductionOperatorAccess::canActFor($assigned, $roleDisabled));
        self::assertFalse(ProductionOperatorAccess::canActFor($assigned, $accountDisabled));
    }

    private function user(string $username): User
    {
        return (new User())
            ->setUsername($username)
            ->setFullName($username)
            ->setEmail($username.'@example.invalid')
            ->setPassword('not-used-in-this-test');
    }
}
