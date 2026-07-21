<?php

namespace App\Controller\Admin\Audit;

use App\Repository\Audit\AuditLogRepository;
use App\Repository\Users\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bitacora', name: 'admin_audit_')]
final class AuditLogController extends AbstractController
{
    private const PAGE_SIZE = 50;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        AuditLogRepository $auditLogs,
        UserRepository $users,
    ): Response {
        $this->denyAccessUnlessGranted('audit_log.view');

        $search = trim((string) $request->query->get('q', ''));
        $actorId = $request->query->getInt('actor') ?: null;
        $fromValue = (string) $request->query->get('from', '');
        $toValue = (string) $request->query->get('to', '');
        $page = max(1, $request->query->getInt('page', 1));

        $logs = $auditLogs->paginateForAdministration(
            search: $search,
            actorId: $actorId,
            from: $this->parseDate($fromValue, false),
            to: $this->parseDate($toValue, true),
            page: $page,
            limit: self::PAGE_SIZE,
        );

        $totalPages = max(1, (int) ceil(count($logs) / self::PAGE_SIZE));

        $routeParameters = array_filter([
            'q' => $search,
            'actor' => $actorId,
            'from' => $fromValue,
            'to' => $toValue,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->render('admin/audit/logs/index.html.twig', [
            'logs' => $logs,
            'users' => $users->findBy(
                ['isActive' => true, 'deletedAt' => null],
                ['fullName' => 'ASC'],
            ),
            'search' => $search,
            'actorId' => $actorId,
            'from' => $fromValue,
            'to' => $toValue,
            'page' => $page,
            'totalPages' => $totalPages,
            'routeParameters' => $routeParameters,
        ]);
    }

    private function parseDate(string $value, bool $endOfDay): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $timezone = new DateTimeZone('America/Mexico_City');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            return null;
        }

        $date = $endOfDay
            ? $date->setTime(23, 59, 59)
            : $date->setTime(0, 0);

        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}