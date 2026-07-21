<?php

namespace App\Controller\Admin\Access;

use App\Application\Access\RoleManager;
use App\DTO\Access\CreateRoleData;
use App\DTO\Access\UpdateRoleData;
use App\Entity\Users\Role;
use App\Entity\Users\User;
use App\Form\Admin\Access\CreateRoleType;
use App\Form\Admin\Access\UpdateRoleType;
use App\Repository\Users\RoleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/roles', name: 'admin_roles_')]
final class RoleController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(RoleRepository $roleRepository): Response
    {
        $this->denyAccessUnlessGranted('role.view');

        return $this->render('admin/access/roles/index.html.twig', [
            'roles' => $roleRepository->findBy([], [
                'isSystem' => 'DESC',
                'name' => 'ASC',
            ]),
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        RoleManager $roleManager,
    ): Response {
        $this->denyAccessUnlessGranted('role.manage');

        $data = new CreateRoleData();
        $form = $this->createForm(CreateRoleType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $roleManager->create($data, $this->currentUser());

                $this->addFlash('success', 'El rol fue creado correctamente.');

                return $this->redirectToRoute('admin_roles_index');
            } catch (\DomainException $exception) {
                $form->addError(
                    new \Symfony\Component\Form\FormError($exception->getMessage()),
                );
            }
        }

        return $this->render('admin/access/roles/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        Role $role,
        Request $request,
        RoleManager $roleManager,
    ): Response {
        $this->denyAccessUnlessGranted('role.manage');

        $data = new UpdateRoleData();
        $data->name = $role->getName();
        $data->description = $role->getDescription();
        $data->permissions = $role->getPermissions()->toArray();

        $isProtected = $role->getCode() === 'ROLE_ADMIN';

        if ($isProtected && $request->isMethod('POST')) {
            throw $this->createAccessDeniedException(
                'El rol Administrador no puede modificarse.',
            );
        }

        $form = $this->createForm(UpdateRoleType::class, $data, [
            'allow_permission_edit' => !$isProtected,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $roleManager->update($role, $data, $this->currentUser());

                $this->addFlash('success', 'El rol se actualizó correctamente.');

                return $this->redirectToRoute('admin_roles_index');
            } catch (\DomainException $exception) {
                $form->addError(
                    new \Symfony\Component\Form\FormError($exception->getMessage()),
                );
            }
        }

        return $this->render('admin/access/roles/edit.html.twig', [
            'form' => $form,
            'role' => $role,
            'is_protected' => $isProtected,
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}