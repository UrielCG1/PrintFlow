<?php

namespace App\Controller\Admin\Quotations;

use App\Application\Quotations\QuotationData;
use App\Application\Quotations\QuotationItemData;
use App\Application\Quotations\QuotationManager;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Form\Admin\Quotations\QuotationType;
use App\Repository\Quotations\QuotationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Quotations\QuotationPdfRenderer;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

#[Route('/admin/cotizaciones')]
final class QuotationController extends AbstractController
{
    #[Route('', name: 'admin_quotations_index', methods: ['GET'])]
    public function index(QuotationRepository $quotationRepository): Response
    {
        $this->denyAccessUnlessGranted('quotations.view');

        return $this->render('admin/quotations/index.html.twig', [
            'quotations' => $quotationRepository->findRecentForAdministration(),
        ]);
    }

    #[Route('/nueva', name: 'admin_quotations_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.create');

        $data = new QuotationData();
        $data->addItem(new QuotationItemData());

        $form = $this->createForm(QuotationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $quotation = $quotationManager->create(
                    $data,
                    $this->authenticatedUser(),
                );

                $this->addFlash(
                    'success',
                    'La cotización se guardó como borrador correctamente.',
                );

                return $this->redirectToRoute('admin_quotations_edit', [
                    'id' => $quotation->getId(),
                ]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/quotations/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/editar', name: 'admin_quotations_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.view');

        if (!$quotation->isEditable()) {
            $this->addFlash(
                'warning',
                'Esta cotización ya no está en borrador y no puede editarse.',
            );

            return $this->redirectToRoute('admin_quotations_index');
        }

        $this->denyAccessUnlessGranted('quotations.update');

        $data = QuotationData::fromQuotation($quotation);

        $form = $this->createForm(QuotationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $quotationManager->update(
                    $quotation,
                    $data,
                    $this->authenticatedUser(),
                );

                $this->addFlash(
                    'success',
                    'El borrador de cotización se actualizó correctamente.',
                );

                return $this->redirectToRoute('admin_quotations_edit', [
                    'id' => $quotation->getId(),
                ]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/quotations/edit.html.twig', [
            'quotation' => $quotation,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/emitir', name: 'admin_quotations_issue', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function issue(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.issue');

        if (!$this->isCsrfTokenValid(
            'quotation-issue-'.$quotation->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException(
                'El token de seguridad de la emisión no es válido.',
            );
        }

        try {
            $quotationManager->issue(
                $quotation,
                $this->authenticatedUser(),
            );

            $this->addFlash(
                'success',
                sprintf(
                    'La cotización %s se emitió correctamente.',
                    $quotation->getFolio(),
                ),
            );
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_quotations_index');
    }

    #[Route('/{id}/pdf', name: 'admin_quotations_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(
        Quotation $quotation,
        QuotationPdfRenderer $quotationPdfRenderer,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.download_pdf');

        if (!$quotation->hasBeenIssued()) {
            throw $this->createNotFoundException(
                'El PDF solo está disponible para cotizaciones emitidas.',
            );
        }

        $pdf = $quotationPdfRenderer->render($quotation);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => (new ResponseHeaderBag())->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $quotationPdfRenderer->filename($quotation),
            ),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}