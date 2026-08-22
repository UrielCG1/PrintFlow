<?php
declare(strict_types=1);

namespace App\Controller;

use App\Repository\Clients\ClientContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientContactEmailConfirmationController extends AbstractController
{
    #[Route('/confirmar-correo-contacto/{token}', name: 'public_client_contact_email_confirm', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function __invoke(string $token, ClientContactRepository $contacts, EntityManagerInterface $entityManager): Response
    {
        $contact = $contacts->findByEmailVerificationTokenHash(hash('sha256', $token));
        $success = false;
        $alreadyVerified = false;
        if ($contact !== null) {
            $alreadyVerified = $contact->isEmailVerified();
            if (!$alreadyVerified && $contact->canConfirmEmail(hash('sha256', $token), new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                $contact->confirmEmail();
                $entityManager->flush();
                $success = true;
            }
        }
        return $this->render('clients/contact_email_confirmation.html.twig', ['success' => $success, 'alreadyVerified' => $alreadyVerified]);
    }
}
