<?php

namespace App\Controller\Account;

use App\Application\Access\UserManager;
use App\DTO\Access\ChangePasswordData;
use App\Entity\Users\User;
use App\Form\Account\ChangePasswordType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordController extends AbstractController
{
    #[Route(
        '/cuenta/cambiar-contrasena',
        name: 'app_change_password',
        methods: ['GET', 'POST'],
    )]
    public function change(
        Request $request,
        UserManager $userManager,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $data = new ChangePasswordData();
        $form = $this->createForm(ChangePasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $userManager->changeOwnPassword(
                    user: $user,
                    currentPassword: $data->currentPassword,
                    newPassword: $data->newPassword,
                );

                $this->addFlash(
                    'success',
                    'Tu contraseña se actualizó correctamente.',
                );

                return $this->redirectToRoute('app_dashboard');
            } catch (\DomainException $exception) {
                $form->addError(
                    new \Symfony\Component\Form\FormError(
                        $exception->getMessage(),
                    ),
                );
            }
        }

        return $this->render('account/change_password.html.twig', [
            'form' => $form,
            'must_change_password' => $user->mustChangePassword(),
        ]);
    }
}