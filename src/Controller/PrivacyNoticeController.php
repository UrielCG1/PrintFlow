<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrivacyNoticeController extends AbstractController
{
    public function __construct(
        private readonly string $privacyResponsible,
        private readonly string $privacyAddress,
        private readonly string $privacyEmail,
    ) {
    }

    #[Route('/aviso-de-privacidad', name: 'app_privacy_notice', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('privacy/notice.html.twig', [
            'privacyResponsible' => $this->privacyResponsible,
            'privacyAddress' => $this->privacyAddress,
            'privacyEmail' => $this->privacyEmail,
        ]);
    }
}
