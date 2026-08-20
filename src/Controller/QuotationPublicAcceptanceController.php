<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Quotations\Quotation;
use App\Enum\Quotations\QuotationStatus;
use App\Service\Quotations\QuotationAcceptanceNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QuotationPublicAcceptanceController extends AbstractController
{
    #[Route('/cotizacion/aceptar/{token}', name: 'quotation_public_accept', requirements: ['token' => '[a-f0-9]{48,64}'], methods: ['GET', 'POST'])]
    public function __invoke(string $token, Request $request, EntityManagerInterface $em, QuotationAcceptanceNotifier $notifier): Response
    {
        $quotation = $em->getRepository(Quotation::class)->findOneBy(['acceptanceToken' => $token]);
        if (!$quotation) { throw $this->createNotFoundException('El enlace de aceptación no es válido.'); }

        [$folio, $date, $amount, $currency, $items] = $this->internalSummary($quotation);
        $alreadyAccepted = in_array($quotation->getStatus(), [QuotationStatus::ACCEPTED, QuotationStatus::ACCEPTED_WITH_CHANGES], true);
        $expired = $quotation->getExpiresAt() < new \DateTimeImmutable('today');
        $errors = [];

        if ($request->isMethod('POST') && !$alreadyAccepted && !$expired) {
            $name = trim((string) $request->request->get('accepted_by'));
            $notes = trim((string) $request->request->get('observations'));
            if (!$this->isCsrfTokenValid('accept_quotation_'.$token, (string) $request->request->get('_token'))) { $errors[] = 'La sesión del formulario expiró. Recarga la página.'; }
            if ($name === '') { $errors[] = 'El nombre de quien acepta es obligatorio.'; }
            if (!$request->request->getBoolean('confirmed')) { $errors[] = 'Debes confirmar la aceptación para continuar.'; }
            if (mb_strlen($name) > 160) { $errors[] = 'El nombre no puede exceder 160 caracteres.'; }
            if (mb_strlen($notes) > 5000) { $errors[] = 'Las observaciones no pueden exceder 5,000 caracteres.'; }

            if ($errors === []) {
                $ip = substr((string) ($request->getClientIp() ?? 'unknown'), 0, 45);
                $quotation->acceptFromPublicLink($name, new \DateTimeImmutable('now'), $notes ?: null, $ip);
                $em->flush();
                $notifier->notify($folio, $name, $notes ?: null, $amount, $currency, $items);
                return $this->render('quotation/public_acceptance.html.twig', compact('folio', 'date', 'amount', 'currency', 'items') + ['success' => true, 'withChanges' => $notes !== '']);
            }
        }

        return $this->render('quotation/public_acceptance.html.twig', compact('folio', 'date', 'amount', 'currency', 'items', 'errors', 'alreadyAccepted', 'expired') + ['token' => $token, 'success' => false]);
    }

    /** @return array{string,\DateTimeImmutable,string,string,list<array{name:string,quantity:string}>} */
    private function internalSummary(Quotation $q): array
    {
        $items = [];
        foreach ($q->getItems() as $item) { $snapshot = $item->getCommercialItemSnapshot(); $items[] = ['name' => (string) ($snapshot['name'] ?? $item->getCommercialItem()->getName()), 'quantity' => rtrim(rtrim($item->getQuantity(), '0'), '.')]; }
        return [(string) $q->getFolio(), $q->getIssuedAt() ?? $q->getCreatedAt(), $q->getTotal(), $q->getCurrency(), $items];
    }
}
