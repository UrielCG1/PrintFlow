<?php

namespace App\Controller\Admin\Quotations;

use App\Application\Quotations\QuotationData;
use App\Application\Quotations\QuotationCancellationData;
use App\Application\Quotations\QuotationDecisionData;
use App\Application\Quotations\QuotationEmailData;
use App\Application\Quotations\QuotationItemData;
use App\Application\Quotations\QuotationManager;
use App\Application\Quotations\QuotationRevisionData;
use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use App\Form\Admin\Quotations\QuotationCancellationType;
use App\Form\Admin\Quotations\QuotationDecisionType;
use App\Form\Admin\Quotations\QuotationEmailType;
use App\Form\Admin\Quotations\QuotationRevisionType;
use App\Form\Admin\Quotations\QuotationType;
use App\Repository\Orders\ServiceOrderRepository;
use App\Repository\Clients\ClientRepository;
use App\Repository\Quotations\QuotationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Quotations\QuotationPdfRenderer;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/admin/cotizaciones')]
final class QuotationController extends AbstractController
{
    public function __construct(
        private readonly ServiceOrderRepository $serviceOrderRepository,
        private readonly ClientRepository $clientRepository,
    ) {
    }

    #[Route('', name: 'admin_quotations_index', methods: ['GET'])]
    public function index(QuotationRepository $quotationRepository): Response
    {
        $this->denyAccessUnlessGranted('quotations.view');

        return $this->render('admin/quotations/index.html.twig', [
            'quotations' => $quotationRepository->findRecentForAdministration(),
        ]);
    }

    #[Route('/contexto-cliente/{id}', name: 'admin_quotations_client_context', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function clientContext(int $id): JsonResponse
    {
        if (!$this->isGranted('quotations.create') && !$this->isGranted('quotations.update')) {
            throw $this->createAccessDeniedException();
        }

        $client = $this->clientRepository->findActiveForQuotation($id);

        if ($client === null) {
            throw $this->createNotFoundException('El cliente activo solicitado no existe.');
        }

        return $this->json([
            'id' => $client->getId(),
            'businessName' => $client->getBusinessName(),
            'legalName' => $client->getLegalName(),
            'email' => $client->getEmail(),
            'phone' => $client->getPhone(),
            'defaultDiscountPercent' => $client->getDefaultDiscountPercent(),
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

            return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
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

        return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
    }

    #[Route('/{id}/enviar', name: 'admin_quotations_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.send');

        $form = $this->createForm(
            QuotationEmailType::class,
            QuotationEmailData::forQuotation($quotation),
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var QuotationEmailData $data */
                $data = $form->getData();
                $quotationManager->send($quotation, $data, $this->authenticatedUser());

                $this->addFlash('success', 'La cotización se envió por correo y quedó registrada en su historial.');

                return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
            } catch (TransportExceptionInterface) {
                $form->addError(new FormError('No fue posible enviar el correo. Verifica la configuración SMTP e inténtalo nuevamente.'));
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->renderQuotationDetail($quotation, emailForm: $form);
    }

    #[Route('/{id}/aceptar', name: 'admin_quotations_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.manage_status');

        $form = $this->createForm(QuotationDecisionType::class, new QuotationDecisionData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var QuotationDecisionData $data */
                $data = $form->getData();
                $quotationManager->accept($quotation, $data, $this->authenticatedUser());
                $this->addFlash('success', 'La aceptación comercial quedó registrada correctamente.');

                return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->renderQuotationDetail($quotation, acceptForm: $form);
    }

    #[Route('/{id}/rechazar', name: 'admin_quotations_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.manage_status');

        $form = $this->createForm(QuotationDecisionType::class, new QuotationDecisionData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var QuotationDecisionData $data */
                $data = $form->getData();
                $quotationManager->reject($quotation, $data, $this->authenticatedUser());
                $this->addFlash('success', 'El rechazo comercial quedó registrado correctamente.');

                return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->renderQuotationDetail($quotation, rejectForm: $form);
    }

    #[Route('/{id}/cancelar', name: 'admin_quotations_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.manage_status');

        $form = $this->createForm(QuotationCancellationType::class, new QuotationCancellationData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var QuotationCancellationData $data */
                $data = $form->getData();
                $quotationManager->cancel($quotation, $data, $this->authenticatedUser());
                $this->addFlash('success', 'La cotización quedó cancelada y su historial se conservó.');

                return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->renderQuotationDetail($quotation, cancellationForm: $form);
    }

    #[Route('/{id}/revisar', name: 'admin_quotations_create_revision', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createRevision(
        Request $request,
        Quotation $quotation,
        QuotationManager $quotationManager,
    ): Response {
        $this->denyAccessUnlessGranted('quotations.create_revision');

        $form = $this->createForm(QuotationRevisionType::class, new QuotationRevisionData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var QuotationRevisionData $data */
                $data = $form->getData();
                $revision = $quotationManager->createRevision($quotation, $data, $this->authenticatedUser());
                $this->addFlash('success', 'Se creó una nueva revisión en borrador. Ajusta sus datos antes de emitirla.');

                return $this->redirectToRoute('admin_quotations_edit', ['id' => $revision->getId()]);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->renderQuotationDetail($quotation, revisionForm: $form);
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

    #[Route('/{id}/revisar-aceptacion', name: 'admin_quotations_review_acceptance', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reviewAcceptance(Quotation $quotation, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('quotations.manage_status');
        if (!$this->isCsrfTokenValid('quotation-review-acceptance-'.$quotation->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException('Token CSRF inválido.'); }
        if ($quotation->getStatus() !== \App\Enum\Quotations\QuotationStatus::ACCEPTED_WITH_CHANGES) { throw $this->createNotFoundException('La cotización no tiene cambios pendientes de revisión.'); }
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        $quotation->markAcceptanceReviewedBy($user);
        $em->flush();
        $this->addFlash('success', 'La aceptación con cambios quedó marcada como revisada.');
        return $this->redirectToRoute('admin_quotations_show', ['id' => $quotation->getId()]);
    }

    #[Route('/{id}', name: 'admin_quotations_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Quotation $quotation): Response
    {
        $this->denyAccessUnlessGranted('quotations.view');

        return $this->renderQuotationDetail($quotation);
    }

    private function renderQuotationDetail(
        Quotation $quotation,
        ?FormInterface $emailForm = null,
        ?FormInterface $acceptForm = null,
        ?FormInterface $rejectForm = null,
        ?FormInterface $cancellationForm = null,
        ?FormInterface $revisionForm = null,
    ): Response {
        return $this->render('admin/quotations/show.html.twig', [
            'quotation' => $quotation,
            'serviceOrder' => $this->serviceOrderRepository->findOneBySourceQuotation($quotation),
            'emailForm' => ($emailForm ?? $this->createForm(
                QuotationEmailType::class,
                QuotationEmailData::forQuotation($quotation),
            ))->createView(),
            'acceptForm' => ($acceptForm ?? $this->createForm(
                QuotationDecisionType::class,
                new QuotationDecisionData(),
            ))->createView(),
            'rejectForm' => ($rejectForm ?? $this->createForm(
                QuotationDecisionType::class,
                new QuotationDecisionData(),
            ))->createView(),
            'cancellationForm' => ($cancellationForm ?? $this->createForm(
                QuotationCancellationType::class,
                new QuotationCancellationData(),
            ))->createView(),
            'revisionForm' => ($revisionForm ?? $this->createForm(
                QuotationRevisionType::class,
                new QuotationRevisionData(),
            ))->createView(),
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
