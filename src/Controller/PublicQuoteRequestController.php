<?php

namespace App\Controller;

use App\Entity\Quotations\QuoteRequest;
use App\Form\PublicQuoteRequestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicQuoteRequestController extends AbstractController
{
    #[Route('/cotizar', name: 'public_quote_request', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $quoteRequest = new QuoteRequest();

        $form = $this->createForm(
            PublicQuoteRequestType::class,
            $quoteRequest,
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $quoteRequest->setFolio(sprintf(
                'SOL-%s-%s',
                (new \DateTimeImmutable())->format('Ymd'),
                strtoupper(bin2hex(random_bytes(3))),
            ));

            $entityManager->persist($quoteRequest);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'Tu solicitud de cotización fue enviada correctamente.',
            );

            return $this->redirectToRoute('public_quote_request');
        }

        return $this->render('public_quote_request/index.html.twig', [
            'form' => $form,
        ]);
    }
}