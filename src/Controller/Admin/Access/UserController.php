<?php

namespace App\Controller\Admin\Access;

use App\Application\Access\UserManager;
use App\DTO\Access\CreateUserData;
use App\DTO\Access\ResetUserPasswordData;
use App\DTO\Access\UpdateUserData;
use App\Entity\Users\User;
use App\Form\Admin\Access\CreateUserType;
use App\Form\Admin\Access\ResetUserPasswordType;
use App\Form\Admin\Access\UpdateUserType;
use App\Repository\Users\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/usuarios', name: 'admin_users_')]
final class UserController extends AbstractController
{
    private const PAGE_SIZE = 20;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
    ): Response {
        $this->denyAccessUnlessGranted('user.view');

        $search = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $paginator = $userRepository->paginateForAdministration(
            search: $search !== '' ? $search : null,
            page: $page,
            limit: self::PAGE_SIZE,
        );

        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return $this->render('admin/access/users/index.html.twig', [
            'users' => iterator_to_array($paginator),
            'search' => $search,
            'page' => min($page, $totalPages),
            'total_pages' => $totalPages,
            'total_users' => $total,
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        UserManager $userManager,
    ): Response {
        $this->denyAccessUnlessGranted('user.create');

        $data = new CreateUserData();
        $form = $this->createForm(CreateUserType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $userManager->create($data, $this->currentUser());

                $this->addFlash(
                    'success',
                    'La cuenta fue creada. La persona deberá cambiar su contraseña temporal al iniciar sesión.',
                );

                return $this->redirectToRoute('admin_users_index');
            } catch (\DomainException $exception) {
                $form->addError(
                    new \Symfony\Component\Form\FormError($exception->getMessage()),
                );
            }
        }

        return $this->render('admin/access/users/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        User $user,
        Request $request,
        UserManager $userManager,
    ): Response {
        $this->denyAccessUnlessGranted('user.update');

        $currentUser = $this->currentUser();

        $data = new UpdateUserData();
        $data->fullName = $user->getFullName();
        $data->username = $user->getUsername();
        $data->email = $user->getEmail();
        $data->phone = $user->getPhone();
        $data->roles = $user->getAssignedRoles()->toArray();

        $form = $this->createForm(UpdateUserType::class, $data, [
            'allow_role_edit' => $currentUser->getId() !== $user->getId(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $userManager->update($user, $data, $currentUser);

                $this->addFlash('success', 'La cuenta se actualizó correctamente.');

                return $this->redirectToRoute('admin_users_index');
            } catch (\DomainException $exception) {
                $form->addError(
                    new \Symfony\Component\Form\FormError($exception->getMessage()),
                );
            }
        }

        return $this->render('admin/access/users/edit.html.twig', [
            'form' => $form,
            'managed_user' => $user,
        ]);
    }

    #[Route('/{id}/estado', name: 'toggle_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleStatus(
        User $user,
        Request $request,
        UserManager $userManager,
    ): Response {
        $this->denyAccessUnlessGranted('user.deactivate');

        if (!$this->isCsrfTokenValid(
            'user_status_'.$user->getId(),
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        try {
            $userManager->setActive(
                user: $user,
                isActive: !$user->isActive(),
                actor: $this->currentUser(),
            );

            $this->addFlash(
                'success',
                $user->isActive()
                    ? 'La cuenta fue activada.'
                    : 'La cuenta fue desactivada.',
            );
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_users_index');
    }

    #[Route('/{id}/restablecer-contrasena', name: 'reset_password', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function resetPassword(
        User $user,
        Request $request,
        UserManager $userManager,
    ): Response {
        $this->denyAccessUnlessGranted('user.reset_password');

        $data = new ResetUserPasswordData();
        $form = $this->createForm(ResetUserPasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $userManager->resetPassword(
                    user: $user,
                    temporaryPassword: $data->temporaryPassword,
                    actor: $this->currentUser(),
                );

                $this->addFlash(
                    'success',
                    'La contraseña temporal se actualizó. La persona deberá cambiarla al iniciar sesión.',
                );

                return $this->redirectToRoute('admin_users_index');
            } catch (\DomainException $exception) {
                $form->addError(
                    new \Symfony\Component\Form\FormError($exception->getMessage()),
                );
            }
        }

        return $this->render('admin/access/users/reset_password.html.twig', [
            'form' => $form,
            'managed_user' => $user,
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