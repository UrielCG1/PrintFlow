<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\ProductionBoardMoveManager;
use App\Application\Production\ProductionBoardQuery;
use App\Entity\Users\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/produccion/calendario', name: 'admin_production_board_')]
final class ProductionBoardController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, ProductionBoardQuery $board): Response
    {
        $this->denyAccessUnlessGranted('production.view');
        $manager = $this->isGranted('production.assign') || $this->isGranted('ROLE_ADMIN');
        try {
            $day = $this->day((string) $request->query->get('date', ''));
        } catch (\DomainException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
        $week = $manager && $request->query->get('view') === 'week';
        return $this->render('admin/production/board/index.html.twig', [
            'board' => $board->build($day, $week),
            'canManage' => $manager,
        ]);
    }

    #[Route('/eventos', name: 'events', methods: ['GET'])]
    public function events(Request $request, ProductionBoardQuery $board): JsonResponse
    {
        $this->denyAccessUnlessGranted('production.view');
        $manager = $this->isGranted('production.assign') || $this->isGranted('ROLE_ADMIN');
        try {
            $day = $this->day((string) $request->query->get('date', ''));
            $week = $manager && $request->query->get('view') === 'week';
            $filters = $manager ? $this->filters($request) : [];
            return $this->json($board->build($day, $week, $filters));
        } catch (\DomainException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/reservas/{id<\d+>}/vista-previa', name: 'move_preview', methods: ['POST'])]
    public function movePreview(int $id, Request $request, ProductionBoardMoveManager $moves): JsonResponse
    {
        $this->denyManagerAccess();
        if (!$this->isCsrfTokenValid('production-board-move', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'La sesión caducó. Recarga la página.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $input = $this->input($request);
            $preview = $moves->preview($id, $input['equipment'], $input['start'], $input['end']);
            unset($preview['start_utc'], $preview['end_utc']);
            return $this->json($preview);
        } catch (\DomainException|\JsonException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/reservas/{id<\d+>}/confirmar', name: 'move_confirm', methods: ['POST'])]
    public function moveConfirm(int $id, Request $request, ProductionBoardMoveManager $moves): JsonResponse
    {
        $this->denyManagerAccess();
        if (!$this->isCsrfTokenValid('production-board-move', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'La sesión caducó. Recarga la página.'], Response::HTTP_FORBIDDEN);
        }
        try {
            $input = $this->input($request);
            $actor = $this->getUser();
            if (!$actor instanceof User) {
                throw new \DomainException('Inicia sesión para reprogramar.');
            }
            return $this->json($moves->confirm($id, $input['equipment'], $input['start'], $input['end'], $input['fingerprint'], $input['reason'], $actor));
        } catch (\DomainException|\JsonException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    private function day(string $value): \DateTimeImmutable
    {
        $zone = new \DateTimeZone(ProductionBoardQuery::ZONE);
        if ($value === '') {
            return new \DateTimeImmutable('today', $zone);
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new \DomainException('Selecciona una fecha válida.');
        }
        return $day;
    }

    private function denyManagerAccess(): void
    {
        $this->denyAccessUnlessGranted('production.view');
        if (!$this->isGranted('production.assign') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Solo jefatura puede reprogramar reservas.');
        }
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['equipment', 'area', 'operation', 'responsible', 'status', 'priority', 'order', 'product', 'risk'] as $key) {
            $value = trim((string) $request->query->get($key, ''));
            if (mb_strlen($value) > 80) {
                throw new \DomainException('Un filtro es demasiado largo.');
            }
            $filters[$key] = $value;
        }
        return $filters;
    }

    /** @return array{equipment: int, start: string, end: string, fingerprint: string, reason: string} */
    private function input(Request $request): array
    {
        $data = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['equipment']) || !filter_var($data['equipment'], FILTER_VALIDATE_INT)
            || (int) $data['equipment'] < 1 || !is_string($data['start'] ?? null) || !is_string($data['end'] ?? null)
            || !is_string($data['fingerprint'] ?? '') || !is_string($data['reason'] ?? '')) {
            throw new \DomainException('Selecciona una máquina válida.');
        }
        return [
            'equipment' => (int) $data['equipment'],
            'start' => $data['start'],
            'end' => $data['end'],
            'fingerprint' => $data['fingerprint'] ?? '',
            'reason' => $data['reason'] ?? '',
        ];
    }
}
