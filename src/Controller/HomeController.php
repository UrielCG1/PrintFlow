<?php

namespace App\Controller;

use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'app_name' => 'PrintFlow',
            'php_version' => PHP_VERSION,
            'symfony_version' => Kernel::VERSION,
            'environment' => $this->getParameter('kernel.environment'),
        ]);
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'app' => 'PrintFlow',
            'php_version' => PHP_VERSION,
            'symfony_version' => Kernel::VERSION,
            'environment' => $this->getParameter('kernel.environment'),
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }
}