<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Enum\Inventory\InventoryCommitmentStatus;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;
use App\Repository\Users\UserRepository;
use Brick\Math\BigDecimal;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Envía después de confirmar la solicitud, sin bloquear el apartado si falla SMTP. */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'sendQueued')]
final class InventoryShortageEmailNotifier
{
    /** @var array<int, string> faltante previo al primer incremento de la solicitud */
    private array $queuedShortages = [];

    public function __construct(
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function queue(InventoryCommitment $commitment, string $previousShortage): void
    {
        $id = $commitment->getId();
        if ($id !== null && BigDecimal::of($commitment->getShortageQuantity())->isGreaterThan(BigDecimal::of($previousShortage))) {
            $currentBaseline = $this->queuedShortages[$id] ?? $previousShortage;
            $this->queuedShortages[$id] = BigDecimal::of($currentBaseline)->isLessThan(BigDecimal::of($previousShortage))
                ? $currentBaseline : $previousShortage;
        }
    }

    public function sendQueued(): void
    {
        if ($this->queuedShortages === []) {
            return;
        }

        $queued = $this->queuedShortages;
        $this->queuedShortages = [];
        try {
            $admins = $this->userRepository->findActiveAdministrators();
        } catch (\Throwable $exception) {
            $this->logger->error('No se pudieron consultar los destinatarios del aviso de inventario.', ['exception' => $exception]);
            return;
        }
        if ($admins === []) {
            $this->logger->warning('Hay faltantes de inventario nuevos, pero no existe un administrador activo para recibir correo.');
            return;
        }
        foreach ($queued as $id => $previousShortage) {
            try {
                $commitment = $this->commitmentRepository->find($id);
                if (!$commitment instanceof InventoryCommitment
                    || !in_array($commitment->getStatus(), [InventoryCommitmentStatus::PARTIAL, InventoryCommitmentStatus::SHORTAGE], true)
                    || !BigDecimal::of($commitment->getShortageQuantity())->isGreaterThan(BigDecimal::of($previousShortage))
                    || $this->decisionRepository->findActiveForCommitment($commitment) !== null) {
                    continue;
                }

                $order = $commitment->getServiceOrder();
                $url = $this->urlGenerator->generate('admin_service_orders_show', ['id' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
                    .'#material-commitment-'.$id;
            } catch (\Throwable $exception) {
                $this->logger->error('No se pudo preparar el aviso de faltante de inventario.', ['commitment_id' => $id, 'exception' => $exception]);
                continue;
            }
            foreach ($admins as $admin) {
                if (!filter_var($admin->getEmail(), FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                try {
                    $this->mailer->send(self::createEmail($commitment, $admin, $url, $this->fromAddress, $this->fromName));
                } catch (\Throwable $exception) {
                    $this->logger->error('No se pudo enviar el aviso de faltante de inventario.', [
                        'commitment_id' => $id,
                        'admin_id' => $admin->getId(),
                        'exception' => $exception,
                    ]);
                }
            }
        }
    }

    public static function createEmail(InventoryCommitment $commitment, \App\Entity\Users\User $admin, string $url, string $fromAddress, string $fromName): TemplatedEmail
    {
        $order = $commitment->getServiceOrder();

        return (new TemplatedEmail())
            ->from(new Address($fromAddress, $fromName))
            ->to(new Address($admin->getEmail(), $admin->getFullName()))
            ->subject('Autorizar material faltante · '.$order->getFolio())
            ->htmlTemplate('emails/inventory/shortage_authorization.html.twig')
            ->context(['commitment' => $commitment, 'order' => $order, 'decisionUrl' => $url]);
    }
}
