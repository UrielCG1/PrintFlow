<?php

namespace App\EventSubscriber\Security;

use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class AuthenticationAuditSubscriber
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $user->recordLogin();

        $this->auditLogger->record(
            actor: $user,
            action: 'authentication.login_success',
            entityType: 'users',
            entityId: $user->getId(),
            oldValues: null,
            newValues: [
                'username' => $user->getUsername(),
            ],
        );

        $this->entityManager->flush();
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $this->auditLogger->record(
            actor: $user,
            action: 'authentication.logout',
            entityType: 'users',
            entityId: $user->getId(),
            oldValues: null,
            newValues: [
                'username' => $user->getUsername(),
            ],
        );

        $this->entityManager->flush();
    }
}