<?php

namespace App\Service\Audit;

use App\Entity\Audit\AuditLog;
use App\Entity\Users\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'plain_password',
        'current_password',
        'new_password',
        'temporary_password',
        'password_confirmation',
        'token',
        'reset_token',
        'access_token',
        'refresh_token',
        'csrf_token',
        'api_key',
        'client_secret',
        'secret',
        'authorization',
        'private_key',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Registra el evento, pero no ejecuta flush.
     * El caso de uso controla la transacción completa.
     */
    public function record(
        ?User $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        $request = $this->requestStack->getCurrentRequest();

        $auditLog = (new AuditLog())
            ->setActor($actor)
            ->setAction($action)
            ->setEntityType($entityType)
            ->setEntityId($entityId !== null ? (string) $entityId : null)
            ->setOldValues($this->sanitize($oldValues))
            ->setNewValues($this->sanitize($newValues))
            ->setIpAddress($request?->getClientIp())
            ->setUserAgent($request?->headers->get('User-Agent'));

        $this->entityManager->persist($auditLog);

        return $auditLog;
    }

    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array($this->normalizeKey((string) $key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            }
        }

        return $values;
    }

    private function normalizeKey(string $key): string
    {
        $key = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;

        return strtolower(str_replace(['-', ' '], '_', $key));
    }
}