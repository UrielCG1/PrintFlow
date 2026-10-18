<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\DesignReviewManager;
use App\Application\Production\DesignSpecificationsPresenter;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionFile;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionPriority;
use App\Enum\Orders\ServiceOrderItemDesignStatus;
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
    private const PREVIEWABLE_CLIENT_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ServiceOrderItemRepository $itemRepository): Response
    {
        $this->denyAccessUnlessGranted('production.view');
        $this->denyAccessUnlessGranted('production.design.review');

        $items = $itemRepository->findForDesignQueue();
        $serviceOrderIds = [];
        foreach ($items as $item) {
            $serviceOrderId = $item->getServiceOrder()->getId();
            if ($serviceOrderId !== null) {
                $serviceOrderIds[$serviceOrderId] = $serviceOrderId;
            }
        }

        $orderPriorities = $itemRepository->findHighestPrioritiesByServiceOrderIds(array_values($serviceOrderIds));
        usort($items, static function (ServiceOrderItem $left, ServiceOrderItem $right) use ($orderPriorities): int {
            $leftOrderId = $left->getServiceOrder()->getId() ?? 0;
            $rightOrderId = $right->getServiceOrder()->getId() ?? 0;
            $leftPriority = $orderPriorities[$leftOrderId] ?? ProductionPriority::NORMAL;
            $rightPriority = $orderPriorities[$rightOrderId] ?? ProductionPriority::NORMAL;
            $priorityOrder = $rightPriority->weight() <=> $leftPriority->weight();

            if ($priorityOrder !== 0) {
                return $priorityOrder;
            }

            $dateOrder = $left->getServiceOrder()->getCreatedAt() <=> $right->getServiceOrder()->getCreatedAt();
            if ($dateOrder !== 0) {
                return $dateOrder;
            }

            $lineOrder = $left->getLineNumber() <=> $right->getLineNumber();

            return $lineOrder !== 0 ? $lineOrder : (($left->getId() ?? 0) <=> ($right->getId() ?? 0));
        });

        $designSummary = [
            ServiceOrderItemDesignStatus::PENDING_REVIEW->value => 0,
            ServiceOrderItemDesignStatus::IN_REVIEW->value => 0,
            ServiceOrderItemDesignStatus::BLOCKED->value => 0,
        ];

        foreach ($items as $item) {
            $status = $item->getDesignStatus()->value;
            if (isset($designSummary[$status])) {
                ++$designSummary[$status];
            }
        }

        return $this->render('admin/design/index.html.twig', [
            'items' => $items,
            'designSummary' => $designSummary,
            'orderPriorities' => $orderPriorities,
        ]);
    }

    #[Route('/partidas/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        ServiceOrderItem $item,
        ProductionFileRepository $fileRepository,
        ServiceOrderItemDesignReleaseRepository $releaseRepository,
        ProductionFileStorage $storage,
        DesignSpecificationsPresenter $specificationsPresenter,
    ): Response {
        $this->denyAccessUnlessGranted('production.view');
        $this->denyAccessUnlessGranted('production.design.review');
        $clientOriginal = $this->resolveClientOriginal($item, $storage);
        $sourceSnapshot = $item->getSourceFileSnapshot() ?? [];
        $sourcePath = $sourceSnapshot['path'] ?? null;

        return $this->render('admin/design/show.html.twig', [
            'item' => $item,
            'files' => $fileRepository->findForItem($item),
            'currentRelease' => $releaseRepository->findCurrent($item),
            'releaseHistory' => $releaseRepository->findHistory($item),
            'hasClientOriginal' => is_string($sourcePath) && trim($sourcePath) !== '',
            'clientOriginalFile' => $clientOriginal === null ? null : [
                'name' => $clientOriginal['name'],
                'mimeType' => $clientOriginal['mimeType'],
                'byteSize' => $clientOriginal['byteSize'],
                'previewable' => $clientOriginal['previewable'],
            ],
            'specifications' => $specificationsPresenter->present($item->getProductionSpecificationsSnapshot()),
            'canReview' => $this->isGranted('production.design.review'),
            'canUploadFiles' => $this->isGranted('production.files.upload'),
        ]);
    }

    #[Route('/partidas/{id}/iniciar-revision', name: 'start_review', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function startReview(Request $request, ServiceOrderItem $item, DesignReviewManager $manager): Response
    {
        $this->denyAccessUnlessGranted('production.view');
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
        $this->denyAccessUnlessGranted('production.view');
        $this->denyAccessUnlessGranted('production.design.review');
        $this->denyAccessUnlessGranted('production.files.upload');
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
        $this->denyAccessUnlessGranted('production.view');
        $this->denyAccessUnlessGranted('production.design.review');
        $this->denyAccessUnlessGranted('production.files.upload');
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
    public function downloadFile(Request $request, ProductionFile $file, ProductionFileStorage $storage): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted('production.files.download');
        $response = new BinaryFileResponse($storage->absolutePath($file->getInternalPath()));
        $response->headers->set('Content-Type', $file->getMimeType());
        $previewable = in_array($file->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        $disposition = $request->query->getBoolean('preview') && $previewable
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $file->getOriginalName());
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/partidas/{id}/original-cliente', name: 'download_client_original', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function downloadClientOriginal(Request $request, ServiceOrderItem $item, ProductionFileStorage $storage): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted('production.view');
        $this->denyAccessUnlessGranted('production.design.review');
        $this->denyAccessUnlessGranted('production.files.download');
        $file = $this->resolveClientOriginal($item, $storage);
        if ($file === null) {
            throw $this->createNotFoundException('La partida no tiene archivo original del cliente.');
        }
        $inline = $request->query->getBoolean('preview') && $file['previewable'];
        $response = new BinaryFileResponse($file['absolutePath']);
        $response->headers->set('Content-Type', $file['mimeType']);
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['name'],
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * @return array{absolutePath: string, name: string, mimeType: string, byteSize: int, previewable: bool}|null
     */
    private function resolveClientOriginal(ServiceOrderItem $item, ProductionFileStorage $storage): ?array
    {
        $snapshot = $item->getSourceFileSnapshot() ?? [];
        $path = $snapshot['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        try {
            $absolutePath = $storage->absoluteClientSource($path);
        } catch (\InvalidArgumentException|\RuntimeException) {
            return null;
        }

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mimeType = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath));
        $byteSize = filesize($absolutePath);
        if ($mimeType === '' || $byteSize === false) {
            return null;
        }

        return [
            'absolutePath' => $absolutePath,
            'name' => is_string($snapshot['original_name'] ?? null) && trim($snapshot['original_name']) !== ''
                ? trim($snapshot['original_name'])
                : 'Archivo original del cliente',
            'mimeType' => $mimeType,
            'byteSize' => $byteSize,
            'previewable' => in_array($mimeType, self::PREVIEWABLE_CLIENT_MIME_TYPES, true),
        ];
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
