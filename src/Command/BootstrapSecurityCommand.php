<?php

namespace App\Command;

use App\Entity\Users\Permission;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:security:bootstrap',
    description: 'Crea los roles, permisos y el primer administrador de PrintFlow.',
)]
final class BootstrapSecurityCommand extends Command
{
    private const ROLES = [
        'ROLE_ADMIN' => [
            'name' => 'Administrador',
            'description' => 'Administración completa del sistema.',
        ],
        'ROLE_SALES' => [
            'name' => 'Ventas',
            'description' => 'Gestión comercial de clientes, cotizaciones y órdenes.',
        ],
        'ROLE_PRODUCTION' => [
            'name' => 'Producción',
            'description' => 'Seguimiento operativo de órdenes y trazabilidad.',
        ],
        'ROLE_OPERATOR' => [
            'name' => 'Operador',
            'description' => 'Ejecución y actualización de etapas asignadas.',
        ],
    ];

    private const PERMISSIONS = [
        'dashboard.view' => ['dashboard', 'view', 'Ver inicio'],

        'user.view' => ['users', 'view', 'Consultar usuarios'],
        'user.create' => ['users', 'create', 'Crear usuarios'],
        'user.update' => ['users', 'update', 'Editar usuarios'],
        'user.deactivate' => ['users', 'deactivate', 'Desactivar usuarios'],
        'user.reset_password' => ['users', 'reset_password', 'Restablecer contraseñas'],

        'role.view' => ['roles', 'view', 'Consultar roles'],
        'role.manage' => ['roles', 'manage', 'Administrar roles'],
        'permission.view' => ['permissions', 'view', 'Consultar permisos'],
        'role_permission.manage' => ['roles', 'manage_permissions', 'Asignar permisos a roles'],
        'audit_log.view' => ['audit_logs', 'view', 'Consultar bitácora'],

        'client.view' => ['clients', 'view', 'Consultar clientes'],
        'client.create' => ['clients', 'create', 'Crear clientes'],
        'client.update' => ['clients', 'update', 'Editar clientes'],
        'client.delete' => ['clients', 'delete', 'Eliminar clientes'],

        'suppliers.view' => ['suppliers', 'view', 'Consultar proveedores'],
        'suppliers.create' => ['suppliers', 'create', 'Crear proveedores'],
        'suppliers.update' => ['suppliers', 'update', 'Editar proveedores'],
        'suppliers.toggle_status' => ['suppliers', 'toggle_status', 'Activar o desactivar proveedores'],

        'service.view' => ['services', 'view', 'Consultar servicios'],
        'service.manage' => ['services', 'manage', 'Administrar servicios'],
        'operation.view' => ['operations', 'view', 'Consultar operaciones'],
        'operation.manage' => ['operations', 'manage', 'Administrar operaciones'],

        'material_categories.view' => ['materials', 'categories_view', 'Consultar categorías de materiales'],
        'material_categories.create' => ['materials', 'categories_create', 'Crear categorías de materiales'],
        'material_categories.update' => ['materials', 'categories_update', 'Editar categorías de materiales'],
        'material_categories.toggle_status' => ['materials', 'categories_toggle_status', 'Activar o desactivar categorías de materiales'],

        'materials.view' => ['materials', 'view', 'Consultar materiales'],
        'materials.create' => ['materials', 'create', 'Crear materiales'],
        'materials.update' => ['materials', 'update', 'Editar materiales'],
        'materials.toggle_status' => ['materials', 'toggle_status', 'Activar o desactivar materiales'],

        'inventory.adjust_stock' => ['inventory', 'adjust_stock', 'Ajustar existencias'],

        'equipment.view' => ['equipment', 'view', 'Consultar equipos'],
        'equipment.manage' => ['equipment', 'manage', 'Administrar equipos'],

        'quote.view' => ['quotes', 'view', 'Consultar cotizaciones'],
        'quote.create' => ['quotes', 'create', 'Crear cotizaciones'],
        'quote.update' => ['quotes', 'update', 'Editar cotizaciones'],
        'quote.delete' => ['quotes', 'delete', 'Eliminar cotizaciones'],
        'quote.apply_discount' => ['quotes', 'apply_discount', 'Aplicar descuentos'],
        'quote.download_pdf' => ['quotes', 'download_pdf', 'Descargar PDF de cotización'],
        'quote.send_email' => ['quotes', 'send_email', 'Enviar cotización por correo'],

        'order.view' => ['orders', 'view', 'Consultar órdenes'],
        'order.create' => ['orders', 'create', 'Crear órdenes'],
        'order.update' => ['orders', 'update', 'Editar órdenes'],
        'order.change_status' => ['orders', 'change_status', 'Cambiar estado de órdenes'],
        'order.close' => ['orders', 'close', 'Cerrar órdenes'],
        'order.download_pdf' => ['orders', 'download_pdf', 'Descargar PDF de orden'],
        'order.send_email' => ['orders', 'send_email', 'Enviar orden por correo'],

        'traceability.view' => ['traceability', 'view', 'Consultar trazabilidad'],
        'traceability.assign' => ['traceability', 'assign', 'Asignar etapas de producción'],
        'traceability.update_stage' => ['traceability', 'update_stage', 'Actualizar etapa de producción'],
    ];

    private const ROLE_PERMISSIONS = [
        'ROLE_ADMIN' => ['*'],

        'ROLE_SALES' => [
            'dashboard.view',
            'client.view',
            'client.create',
            'client.update',
            'quote.view',
            'quote.create',
            'quote.update',
            'quote.delete',
            'quote.apply_discount',
            'quote.download_pdf',
            'quote.send_email',
            'order.view',
            'order.create',
            'order.update',
            'order.download_pdf',
            'order.send_email',
        ],

        'ROLE_PRODUCTION' => [
            'dashboard.view',
            'order.view',
            'order.update',
            'order.change_status',
            'traceability.view',
            'traceability.assign',
            'traceability.update_stage',
            'materials.view',
            'equipment.view',
        ],

        'ROLE_OPERATOR' => [
            'dashboard.view',
            'order.view',
            'traceability.view',
            'traceability.update_stage',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roles = $this->seedRoles();
        $permissions = $this->seedPermissions();
        $this->assignPermissions($roles, $permissions);

        $this->entityManager->flush();

        $userRepository = $this->entityManager->getRepository(User::class);
        $admin = $userRepository->findOneBy(['username' => 'admin']);

        if ($admin instanceof User) {
            $output->writeln('<info>Roles y permisos verificados. El usuario "admin" ya existe.</info>');

            return Command::SUCCESS;
        }

        $helper = $this->getHelper('question');

        $fullName = trim((string) $helper->ask(
            $input,
            $output,
            new Question('Nombre completo del administrador [Administrador PrintFlow]: ', 'Administrador PrintFlow'),
        ));

        $username = strtolower(trim((string) $helper->ask(
            $input,
            $output,
            new Question('Usuario [admin]: ', 'admin'),
        )));

        $email = strtolower(trim((string) $helper->ask(
            $input,
            $output,
            new Question('Correo electrónico del administrador: '),
        )));

        if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Nombre, usuario y correo válido son obligatorios.</error>');

            return Command::FAILURE;
        }

        if (
            $userRepository->findOneBy(['username' => $username]) instanceof User
            || $userRepository->findOneBy(['email' => $email]) instanceof User
        ) {
            $output->writeln('<error>Ya existe una cuenta con ese usuario o correo.</error>');

            return Command::FAILURE;
        }

        $passwordQuestion = new Question('Contraseña del administrador: ');
        $passwordQuestion->setHidden(true);

        $passwordConfirmationQuestion = new Question('Confirma la contraseña: ');
        $passwordConfirmationQuestion->setHidden(true);

        $password = (string) $helper->ask($input, $output, $passwordQuestion);
        $passwordConfirmation = (string) $helper->ask($input, $output, $passwordConfirmationQuestion);

        if (strlen($password) < 12) {
            $output->writeln('<error>La contraseña debe tener al menos 12 caracteres.</error>');

            return Command::FAILURE;
        }

        if (!hash_equals($password, $passwordConfirmation)) {
            $output->writeln('<error>Las contraseñas no coinciden.</error>');

            return Command::FAILURE;
        }

        $admin = (new User())
            ->setFullName($fullName)
            ->setUsername($username)
            ->setEmail($email)
            ->setMustChangePassword(false);

        $admin->setPassword($this->passwordHasher->hashPassword($admin, $password));
        $admin->addRole($roles['ROLE_ADMIN']);

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $output->writeln('<info>Administrador creado correctamente.</info>');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, Role>
     */
    private function seedRoles(): array
    {
        $repository = $this->entityManager->getRepository(Role::class);
        $roles = [];

        foreach (self::ROLES as $code => $definition) {
            $role = $repository->findOneBy(['code' => $code]);

            if (!$role instanceof Role) {
                $role = (new Role())
                    ->setCode($code)
                    ->setName($definition['name'])
                    ->setDescription($definition['description'])
                    ->setIsSystem(true)
                    ->setIsActive(true);

                $this->entityManager->persist($role);
            }

            $roles[$code] = $role;
        }

        return $roles;
    }

    /**
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $repository = $this->entityManager->getRepository(Permission::class);
        $permissions = [];

        foreach (self::PERMISSIONS as $code => [$module, $action, $name]) {
            $permission = $repository->findOneBy(['code' => $code]);

            if (!$permission instanceof Permission) {
                $permission = (new Permission())
                    ->setCode($code)
                    ->setModule($module)
                    ->setAction($action)
                    ->setName($name)
                    ->setIsSystem(true)
                    ->setIsActive(true);

                $this->entityManager->persist($permission);
            }

            $permissions[$code] = $permission;
        }

        return $permissions;
    }

    /**
     * @param array<string, Role> $roles
     * @param array<string, Permission> $permissions
     */
    private function assignPermissions(array $roles, array $permissions): void
    {
        foreach (self::ROLE_PERMISSIONS as $roleCode => $permissionCodes) {
            $role = $roles[$roleCode];

            if ($permissionCodes === ['*']) {
                foreach ($permissions as $permission) {
                    $role->addPermission($permission);
                }

                continue;
            }

            foreach ($permissionCodes as $permissionCode) {
                $role->addPermission($permissions[$permissionCode]);
            }
        }
    }
}