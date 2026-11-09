<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Users\{Permission, Role, User};
use App\Tests\Support\{IntegrationFixtures, IsolatedKernel};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AccessAdministrationTest extends TestCase
{
    private IsolatedKernel $kernel;
    private KernelBrowser $browser;
    private int $adminId;
    private int $roleId;
    private int $inactivePermissionId;
    private int $activePermissionId;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) self::markTestSkipped('Requires pdo_sqlite');
        $directory = dirname(__DIR__, 2).'/var/access-qa/'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->kernel = new IsolatedKernel($directory);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $fixtures = IntegrationFixtures::seed($em);
        $this->adminId = $fixtures['user']->getId();
        $active = (new Permission())->setCode('access.sample.view')->setModule('access')->setAction('sample.view')->setName('Ver muestra');
        $inactive = (new Permission())->setCode('access.old.view')->setModule('access')->setAction('old.view')->setName('Anterior')->setIsActive(false);
        $role = (new Role())->setCode('ROLE_SAMPLE')->setName('Muestra')->addPermission($active)->addPermission($inactive);
        foreach ([$active, $inactive, $role] as $entity) $em->persist($entity);
        $em->flush();
        $this->roleId = $role->getId();
        $this->activePermissionId = $active->getId();
        $this->inactivePermissionId = $inactive->getId();
        $this->browser = new KernelBrowser($this->kernel);
        $this->browser->loginUser($fixtures['user']);
    }

    protected function tearDown(): void { $this->kernel->shutdown(); }

    public function testScreensAndInactivePermissionSurvivesEdit(): void
    {
        foreach (['/admin/usuarios', '/admin/usuarios/nuevo', '/admin/usuarios/'.$this->adminId.'/editar', '/admin/usuarios/'.$this->adminId.'/restablecer-contrasena', '/admin/roles', '/admin/roles/nuevo', '/admin/roles/'.$this->roleId] as $path) {
            $this->browser->request('GET', $path);
            self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $path);
        }
        $crawler = $this->browser->request('GET', '/admin/roles/'.$this->roleId.'/editar');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), $this->browser->getResponse()->getContent());
        self::assertStringContainsString('permiso(s) inactivo(s)', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-code="access.sample.view"]'));
        $token = $crawler->filter('input[name="update_role[_token]"]')->attr('value');
        $this->browser->request('POST', '/admin/roles/'.$this->roleId.'/editar', ['update_role' => ['name' => 'Manipulado', 'description' => '', 'permissions' => [$this->activePermissionId], '_token' => 'forged']]);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/admin/roles/'.$this->roleId.'/editar', ['update_role' => ['name' => 'Manipulado', 'description' => '', 'permissions' => [999999], '_token' => $token]]);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());
        $crawler = $this->browser->request('GET', '/admin/roles/'.$this->roleId.'/editar');
        $form = $crawler->selectButton('Guardar cambios')->form();
        $form['update_role[name]'] = 'Muestra editada';
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode(), $this->browser->getResponse()->getContent());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $role = $em->find(Role::class, $this->roleId);
        self::assertSame('Muestra editada', $role->getName());
        self::assertTrue($role->getPermissions()->contains($em->find(Permission::class, $this->activePermissionId)));
        self::assertTrue($role->getPermissions()->contains($em->find(Permission::class, $this->inactivePermissionId)));
        $this->browser->request('GET', '/admin/usuarios?page=999');
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('page=1', $this->browser->getResponse()->headers->get('Location'));
    }

    public function testAuthorizationCsrfAndProtectedAdministrator(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $adminRole = $em->getRepository(Role::class)->findOneBy(['code' => 'ROLE_ADMIN']);
        $this->browser->request('GET', '/admin/roles/'.$adminRole->getId().'/editar');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('acceso completo', $this->browser->getResponse()->getContent());
        $this->browser->request('POST', '/admin/roles/'.$adminRole->getId().'/editar', []);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/admin/usuarios/'.$this->adminId.'/estado', ['_token' => 'forged']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $viewPermission = (new Permission())->setCode('role.view')->setModule('role')->setAction('view')->setName('Consultar roles');
        $viewRole = (new Role())->setCode('ROLE_VIEWER_QA')->setName('Consulta')->addPermission($viewPermission);
        $readOnly = (new User())->setUsername('read-only')->setFullName('Consulta QA')->setEmail('view@example.invalid')->setPassword('unused')->setMustChangePassword(false)->addRole($viewRole);
        foreach ([$viewPermission, $viewRole, $readOnly] as $entity) $em->persist($entity);
        $em->flush(); $this->browser->loginUser($readOnly);
        $this->browser->request('GET', '/admin/roles/'.$this->roleId);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/admin/roles/'.$this->roleId.'/editar', []);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $viewer = (new User())->setUsername('no-access')->setFullName('Sin acceso')->setEmail('no-access@example.invalid')->setPassword('unused')->setMustChangePassword(false);
        $em->persist($viewer); $em->flush(); $this->browser->loginUser($viewer);
        foreach (['/admin/usuarios', '/admin/usuarios/nuevo', '/admin/usuarios/'.$this->adminId.'/editar', '/admin/usuarios/'.$this->adminId.'/restablecer-contrasena', '/admin/roles', '/admin/roles/nuevo', '/admin/roles/'.$this->roleId, '/admin/roles/'.$this->roleId.'/editar'] as $path) {
            $this->browser->request('GET', $path);
            self::assertSame(403, $this->browser->getResponse()->getStatusCode(), $path);
        }
        $this->browser->request('POST', '/admin/usuarios/'.$this->adminId.'/estado', ['_token' => 'forged']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }
    public function testInactiveRoleIsRetainedAndLastAdministratorIsProtected(): void
    {
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $activeRole = $em->find(Role::class, $this->roleId);
        $inactiveRole = (new Role())->setCode('ROLE_OLD')->setName('Antiguo')->setIsActive(false);
        $target = (new User())->setUsername('target')->setFullName('Usuario objetivo')->setEmail('target@example.invalid')->setPassword('unused')->setMustChangePassword(false)->addRole($activeRole)->addRole($inactiveRole);
        $em->persist($inactiveRole); $em->persist($target); $em->flush();
        $targetId = $target->getId(); $inactiveId = $inactiveRole->getId();
        $crawler = $this->browser->request('GET', '/admin/usuarios/'.$targetId.'/editar');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertStringContainsString('Roles inactivos conservados', $crawler->text());
        $form = $crawler->selectButton('Guardar cambios')->form();
        $form['update_user[fullName]'] = 'Usuario actualizado';
        $this->browser->submit($form);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode(), $this->browser->getResponse()->getContent());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        self::assertTrue($em->find(User::class, $targetId)->getAssignedRoles()->contains($em->find(Role::class, $inactiveId)));

        $admin = $em->find(User::class, $this->adminId);
        $manager = $this->kernel->getContainer()->get('test.service_container')->get(\App\Application\Access\UserManager::class);
        try {
            $manager->setActive($admin, false, $admin);
            self::fail('Self-deactivation must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('propia cuenta', $exception->getMessage());
        }
        $data = new \App\DTO\Access\UpdateUserData();
        $data->fullName = $admin->getFullName(); $data->username = $admin->getUsername();
        $data->email = $admin->getEmail(); $data->phone = $admin->getPhone();
        $data->roles = [$em->find(Role::class, $this->roleId), ...$admin->getAssignedRoles()->toArray()];
        try {
            $manager->update($admin, $data, $admin);
            self::fail('Changing own roles must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('propios roles', $exception->getMessage());
        }
        $operatorRole = (new Role())->setCode('ROLE_ACCESS_OP')->setName('Accesos QA');
        foreach (['user.view', 'user.deactivate'] as $code) {
            [$module, $action] = explode('.', $code, 2);
            $permission = (new Permission())->setCode($code)->setModule($module)->setAction($action)->setName($code);
            $operatorRole->addPermission($permission); $em->persist($permission);
        }
        $operator = (new User())->setUsername('operator')->setFullName('Operador QA')->setEmail('operator@example.invalid')->setPassword('unused')->setMustChangePassword(false)->addRole($operatorRole);
        $em->persist($operatorRole); $em->persist($operator); $em->flush();
        $this->browser->loginUser($operator);
        $crawler = $this->browser->request('GET', '/admin/usuarios');
        $token = $crawler->filter('form[action="/admin/usuarios/'.$this->adminId.'/estado"] input[name="_token"]')->attr('value');
        $this->browser->request('POST', '/admin/usuarios/'.$this->adminId.'/estado', ['_token' => $token]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        self::assertTrue($em->find(User::class, $this->adminId)->isActive());
        $this->browser->followRedirect();
        self::assertStringContainsString('Debe existir al menos un administrador activo', $this->browser->getResponse()->getContent());
    }

    public function testCreateAndResetRequireValidFormAndPreservePasswordRules(): void
    {
        $crawler = $this->browser->request('GET', '/admin/usuarios/nuevo');
        $token = $crawler->filter('input[name="create_user[_token]"]')->attr('value');
        $payload = ['fullName' => 'Persona QA', 'username' => 'persona-qa', 'email' => 'persona@example.invalid', 'phone' => '', 'roles' => [$this->roleId], 'plainPassword' => ['first' => 'temporal-qa-2026', 'second' => 'temporal-qa-2026'], '_token' => 'forged'];
        $this->browser->request('POST', '/admin/usuarios/nuevo', ['create_user' => $payload]);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        self::assertNull($em->getRepository(User::class)->findOneBy(['username' => 'persona-qa']));
        $payload['_token'] = $token;
        $this->browser->request('POST', '/admin/usuarios/nuevo', ['create_user' => $payload]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode(), $this->browser->getResponse()->getContent());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'persona-qa']);
        self::assertNotNull($user);
        self::assertTrue($user->mustChangePassword());
        self::assertNotSame('temporal-qa-2026', $user->getPassword());
        $id = $user->getId();
        $crawler = $this->browser->request('GET', '/admin/usuarios/'.$id.'/restablecer-contrasena');
        $token = $crawler->filter('input[name="reset_user_password[_token]"]')->attr('value');
        $this->browser->request('POST', '/admin/usuarios/'.$id.'/restablecer-contrasena', ['reset_user_password' => ['temporaryPassword' => ['first' => 'corta', 'second' => 'corta'], '_token' => $token]]);
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());
        $this->browser->request('POST', '/admin/usuarios/'.$id.'/restablecer-contrasena', ['reset_user_password' => ['temporaryPassword' => ['first' => 'nueva-temporal-2026', 'second' => 'nueva-temporal-2026'], '_token' => $token]]);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        $em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $user = $em->find(User::class, $id);
        self::assertTrue($user->mustChangePassword());
        self::assertTrue(password_verify('nueva-temporal-2026', $user->getPassword()));
    }

}
