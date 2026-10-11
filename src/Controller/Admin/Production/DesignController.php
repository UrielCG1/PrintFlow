<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\DesignReviewManager;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionFile;
use App\Entity\Users\User;
use App\Repository\Orders\ServiceOrderItemRepository;
use App\Repository\Production\ProductionFileRepository;
use App\Repository\Production\ServiceOrderItemDesignReleaseRepository;
use App\Service\Production\ProductionFileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/diseno', name: 'admin_design_')]
final class DesignController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ServiceOrderItemRepository $itemRepository): Response
    {
        $this->denyAccessUnlessGranted('production.view');

        return $this->render('admin/design/index.html.twig', [
            'items' => $itemRepository->findForDesignQueue(),
        ]);
    }

    #[Route('/partidas/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        ServiceOrderItem $item,
        ProductionFileRepository $fileRepository,
        ServiceOrderItemDesignReleaseRepository $releaseRepository,
    ): Response {
        $this->denyAccessUnlessGranted('production.view');

        return $this->render('admin/design/show.html.twig', [
            'item' => $item,
            'files' => $fileRepository->findForItem($item),
            'currentRelease' => $releaseRepository->findCurrent($item),
            'releaseHistory' => $releaseRepository->findHistory($item),
            'hasClientOriginal' => is_string(($item->getSourceFileSnapshot() ?? [])['path'] ?? null),
            'canReview' => $this->isGranted('production.design.review'),
        ]);
    }

    #[Route('/partidas/{id}/iniciar-revision', name: 'start_review', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function startReview(Request $request, ServiceOrderItem $item, DesignReviewManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.design.review');
        $this->assertCsrf($request, 'design-start-review-'.$item->getId());

        try {
            $manager->startReview($item, $this->authenticatedUser());
            $this->addFlash('success', 'La revisión de Diseño quedó iniciada para esta partida.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_design_show', ['id' => $item->getId()]);
    }

    #[Route('/partidas/{id}/autorizar-original', name: 'authorize_original', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function authorizeOriginal(Request $request, ServiceOrderItem $item, DesignReviewManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.design.review');
        $this->assertCsrf($request, 'design-authorize-original-'.$item->getId());

        try {
            $manager->authorizeClientOriginal(
                $item,
                $this->requiredUpload($request, 'reference'),
                $request->request->getString('instructions'),
                $this->authenticatedUser(),
            );
            $this->addFlash('success', 'Se autorizó el original del cliente y la partida quedó lista para Impresión.');
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_design_show', ['id' => $item->getId()]);
    }

    #[Route('/partidas/{id}/autorizar-arte-preparado', name: 'authorize_prepared', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function authorizePrepared(Request $request, ServiceOrderItem $item, DesignReviewManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.design.review');
        $this->assertCsrf($request, 'design-authorize-prepared-'.$item->getId());

        try {
            $manager->authorizePreparedArtwork(
                $item,
                $this->requiredUpload($request, 'artwork'),
                $this->requiredUpload($request, 'reference'),
                $request->request->getString('instructions'),
                $this->authenticatedUser(),
            );
            $this->addFlash('success', 'El arte preparado y su captura fueron autorizados para Impresión.');
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_design_show', ['id' => $item->getId()]);
    }

    #[Route('/archivos/{id}', name: 'download_file', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function downloadFile(ProductionFile $file, ProductionFileStorage $storage): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted('production.files.download');
        $response = new BinaryFileResponse($storage->absolutePath($file->getInternalPath()));
        $response->setContentType($file->getMimeType());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getOriginalName());
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/partidas/{id}/original-cliente', name: 'download_client_original', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function downloadClientOriginal(ServiceOrderItem $item, ProductionFileStorage $storage): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted('production.files.download');
        $snapshot = $item->getSourceFileSnapshot() ?? [];
        $path = $snapshot['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            throw $this->createNotFoundException('La partida no tiene archivo original del cliente.');
        }
        $name = is_string($snapshot['original_name'] ?? null) ? $snapshot['original_name'] : 'original-del-cliente';
        $response = new BinaryFileResponse($storage->absoluteClientSource($path));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }
    }

    private function requiredUpload(Request $request, string $name): UploadedFile
    {
        $file = $request->files->get($name);
        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('Adjunta el archivo requerido antes de continuar.');
        }

        return $file;
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
