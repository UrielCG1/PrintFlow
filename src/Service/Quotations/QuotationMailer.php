<?php

declare(strict_types=1);

namespace App\Service\Quotations;

use App\Application\Quotations\QuotationEmailData;
use App\Entity\Quotations\Quotation;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class QuotationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly QuotationPdfRenderer $quotationPdfRenderer,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly string $privacyResponsible,
        private readonly string $privacyAddress,
        private readonly string $privacyEmail,
    ) {
    }

    public function send(Quotation $quotation, QuotationEmailData $data): ?string
    {
        if (!$quotation->hasBeenIssued()) {
            throw new \DomainException('Solo puede enviarse por correo una cotización emitida.');
        }

        $recipientEmail = strtolower(trim((string) $data->recipientEmail));
        $recipientName = trim((string) $data->recipientName);
        $copyEmail = strtolower(trim((string) $data->copyEmail));
        $message = trim((string) $data->message);

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($recipientEmail, $recipientName))
            ->subject(sprintf('Cotización %s | PrintFlow', $quotation->getFolio()))
            ->htmlTemplate('emails/quotations/quotation_sent.html.twig')
            ->context([
                'quotation' => $quotation,
                'recipientName' => $recipientName,
                'message' => $message !== '' ? $message : null,
                'privacyResponsible' => $this->privacyResponsible,
                'privacyAddress' => $this->privacyAddress,
                'privacyEmail' => $this->privacyEmail,
            ])
            ->attach(
                $this->quotationPdfRenderer->render($quotation),
                $this->quotationPdfRenderer->filename($quotation),
                'application/pdf',
            );

        if ($copyEmail !== '') {
            $email->cc($copyEmail);
        }

        return $this->mailer->send($email)?->getMessageId();
    }
}
