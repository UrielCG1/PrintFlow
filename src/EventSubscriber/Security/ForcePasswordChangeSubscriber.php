<?php

namespace App\EventSubscriber\Security;

use App\Entity\Users\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class ForcePasswordChangeSubscriber
{
    private const ALLOWED_ROUTES = [
        'app_change_password',
        'app_logout',
        'app_login',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || !$user->mustChangePassword()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if (
            is_string($route)
            && in_array($route, self::ALLOWED_ROUTES, true)
        ) {
            return;
        }

        if (str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $event->setResponse(
            new RedirectResponse(
                $this->urlGenerator->generate('app_change_password'),
            ),
        );
    }
}