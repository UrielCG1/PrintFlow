<?php

namespace App\Application\Clients;

use App\Entity\Clients\Client;
use App\Entity\Clients\ClientContact;
use App\Entity\Common\Contact;
use App\Entity\Users\User;
use App\Repository\Clients\ClientContactRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class ClientContactManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientContactRepository $clientContactRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(
        Client $client,
        ClientContactData $data,
        User $actor,
    ): ClientContact {
        if ($client->getClientType() === 'INDIVIDUAL' && $data->isPrimary) { throw new \DomainException('El titular de una persona física es su único contacto principal.'); }
        return $this->entityManager->wrapInTransaction(
            function () use ($client, $data, $actor): ClientContact {
                if ($data->isPrimary) {
                    $this->clearOtherPrimaryContacts($client, null, $actor);

                    // Evita una colisión con la restricción única de MySQL.
                    $this->entityManager->flush();
                }

                [$firstName, $lastName] = $this->splitName((string) $data->fullName);
                $person = (new Contact($firstName))
                    ->setLastName($lastName)
                    ->setWorkDays($data->workDays)
                    ->setWorkHours($data->workHours);
                $contact = new ClientContact($client, $person);
                $this->applyData($contact, $data);

                $this->entityManager->persist($person);
                $this->entityManager->persist($contact);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'client_contact.created',
                    entityType: 'client_contact',
                    entityId: $contact->getId(),
                    newValues: $this->snapshot($contact),
                );

                $this->entityManager->flush();

                return $contact;
            }
        );
    }

    public function update(
        ClientContact $contact,
        ClientContactData $data,
        User $actor,
    ): void {
        if ($contact->getClient()->getClientType() === 'INDIVIDUAL' && $contact->getClient()->getIndividualHolderContact() !== $contact && $data->isPrimary) { throw new \DomainException('El titular de una persona física es su único contacto principal.'); }
        $this->entityManager->wrapInTransaction(
            function () use ($contact, $data, $actor): void {
                $oldValues = $this->snapshot($contact);

                if ($data->isPrimary && !$contact->isPrimary()) {
                    $this->clearOtherPrimaryContacts(
                        $contact->getClient(),
                        $contact,
                        $actor,
                    );

                    $this->entityManager->flush();
                }

                $this->applyData($contact, $data);

                $newValues = $this->snapshot($contact);

                if ($oldValues === $newValues) {
                    return;
                }

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'client_contact.updated',
                    entityType: 'client_contact',
                    entityId: $contact->getId(),
                    oldValues: $oldValues,
                    newValues: $newValues,
                );

                $this->entityManager->flush();
            }
        );
    }

    public function setActive(
        ClientContact $contact,
        bool $isActive,
        User $actor,
    ): void {
        if (!$isActive && $contact->getClient()->getClientType() === 'INDIVIDUAL' && $contact->getClient()->getIndividualHolderContact() === $contact) { throw new \DomainException('No se puede desactivar al titular de una persona física. Convierte primero el cliente a empresa.'); }
        if ($contact->isActive() === $isActive) {
            return;
        }

        $oldValues = $this->snapshot($contact);
        $contact->setIsActive($isActive);
        $newValues = $this->snapshot($contact);

        $this->entityManager->wrapInTransaction(
            function () use ($contact, $actor, $isActive, $oldValues, $newValues): void {
                $this->auditLogger->record(
                    actor: $actor,
                    action: $isActive
                        ? 'client_contact.activated'
                        : 'client_contact.deactivated',
                    entityType: 'client_contact',
                    entityId: $contact->getId(),
                    oldValues: $oldValues,
                    newValues: $newValues,
                );

                $this->entityManager->flush();
            }
        );
    }

    private function applyData(
        ClientContact $contact,
        ClientContactData $data,
    ): void {
        $email = strtolower(trim((string) $data->email));
        if ($email === '') {
            throw new \DomainException('El correo laboral del contacto es obligatorio.');
        }
        if ($this->clientContactRepository->findOneByBusinessEmail($email, $contact->getId()) !== null) {
            throw new \DomainException('El correo laboral ya está registrado en otro contacto.');
        }
        $contact
            ->setFullName((string) $data->fullName)
            ->setJobTitle($data->jobTitle)
            ->setEmail($email)
            ->setPhone($data->phone)
            ->setWorkSchedule($data->workHours)
            ->setIsPrimary($data->isPrimary);

        if ($contact->getClient()->getClientType() === 'INDIVIDUAL' && $contact->getClient()->getIndividualHolderContact() === $contact) {
            $contact->setIsPrimary(true);
            $contact->getClient()->setBusinessName((string) $data->fullName)->setEmail($email);
        }

        if ($contact->getContact() !== null) {
            [$firstName, $lastName] = $this->splitName((string) $data->fullName);
            $contact->getContact()
                ->setFirstName($firstName)
                ->setLastName($lastName)
                ->setWorkDays($data->workDays)
                ->setWorkHours($data->workHours);
        }
    }

    /** @return array{string, ?string} */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];
        return [$parts[0] ?? '', $parts[1] ?? null];
    }

    private function clearOtherPrimaryContacts(
        Client $client,
        ?ClientContact $except,
        User $actor,
    ): void {
        foreach (
            $this->clientContactRepository->findOtherActivePrimaryContacts(
                $client,
                $except,
            ) as $previousPrimary
        ) {
            $oldValues = $this->snapshot($previousPrimary);
            $previousPrimary->setIsPrimary(false);

            $this->auditLogger->record(
                actor: $actor,
                action: 'client_contact.primary_changed',
                entityType: 'client_contact',
                entityId: $previousPrimary->getId(),
                oldValues: $oldValues,
                newValues: $this->snapshot($previousPrimary),
            );
        }
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function snapshot(ClientContact $contact): array
    {
        return [
            'client_id' => $contact->getClient()->getId(),
            'full_name' => $contact->getFullName(),
            'job_title' => $contact->getJobTitle(),
            'email' => $contact->getEmail(),
            'phone' => $contact->getPhone(),
            'work_days' => $contact->getContact()?->getWorkDays(),
            'work_hours' => $contact->getContact()?->getWorkHours(),
            'is_primary' => $contact->isPrimary(),
            'is_individual_holder' => $contact->getClient()->getIndividualHolderContact() === $contact,
            'is_active' => $contact->isActive(),
        ];
    }
}
