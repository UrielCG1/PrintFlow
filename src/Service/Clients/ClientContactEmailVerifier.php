<?php
declare(strict_types=1);

namespace App\Service\Clients;

use App\Entity\Clients\{Client, ClientContact};
use App\Repository\Clients\ClientContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ClientContactEmailVerifier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientContactRepository $contacts,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    public function send(ClientContact $contact): void
    {
        $email = $contact->getEmail();
        if ($email === null || $email === '' || $contact->isEmailVerified()) { return; }
        $token = bin2hex(random_bytes(32));
        $contact->beginEmailVerification(hash('sha256', $token), new \DateTimeImmutable('+24 hours', new \DateTimeZone('UTC')));
        $this->entityManager->flush();
        try {
            $message = (new TemplatedEmail())
                ->from(new Address($this->fromAddress, $this->fromName))
                ->to(new Address($email, $contact->getFullName()))
                ->subject('Confirma tu correo | Ooxcorp')
                ->htmlTemplate('emails/clients/contact_email_verification.html.twig')
                ->context([
                    'contact' => $contact,
                    'confirmationUrl' => $this->urls->generate('public_client_contact_email_confirm', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);
            $this->mailer->send($message);
        } catch (\Throwable $exception) {
            $contact->clearEmailVerification();
            $this->entityManager->flush();
            throw $exception;
        }
    }

    public function sendPendingForClient(Client $client): int
    {
        $sent = 0;
        foreach ($this->contacts->findForClient($client) as $contact) {
            if ($contact->isEmailVerified() || $contact->getEmailVerificationSentAt() !== null) { continue; }
            $this->send($contact);
            ++$sent;
        }
        return $sent;
    }
}
