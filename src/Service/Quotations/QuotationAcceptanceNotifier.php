<?php

declare(strict_types=1);

namespace App\Service\Quotations;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class QuotationAcceptanceNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $staffEmail,
    ) {}

    /** @param list<array{name:string,quantity:string}> $items */
    public function notify(string $folio, string $name, ?string $notes, string $amount, string $currency, array $items): void
    {
        $withChanges = trim((string) $notes) !== '';
        $subject = sprintf('Cotización %s %s', $folio, $withChanges ? 'aceptada con cambios' : 'aceptada');
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($this->staffEmail)
            ->subject($subject)
            ->htmlTemplate('emails/quotations/acceptance_notification.html.twig')
            ->context(compact('folio', 'name', 'notes', 'amount', 'currency', 'items', 'withChanges'));
        $this->mailer->send($email);
    }
}
