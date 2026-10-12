<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Security\Voter\PermissionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PermissionVoterTest extends TestCase
{
    public function testAdministratorCanAccessANewModulePermissionBeforeItIsSeeded(): void
    {
        $admin = new User();
        $admin->addRole((new Role())->setCode('ROLE_ADMIN')->setName('Administrador'));
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        $decision = (new PermissionVoter())->vote($token, null, ['inventory.view']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $decision);
    }
}
