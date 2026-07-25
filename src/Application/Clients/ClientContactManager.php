<?php

namespace App\Application\Clients;

use App\Entity\Clients\Client;
use App\Entity\Clients\ClientContact;
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
        return $this->entityManager->wrapInTransaction(
            function () use ($client, $data, $actor): ClientContact {
                if ($data->isPrimary) {
                    $this->clearOtherPrimaryContacts($client, null, $actor);

                    // Evita una colisión con la restricción única de MySQL.
                    $this->entityManager->flush();
                }

                $contact = new ClientContact($client);
                $this->applyData($contact, $data);

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
        $contact
            ->setFullName((string) $data->fullName)
            ->setJobTitle($data->jobTitle)
            ->setEmail($data->email)
            ->setPhone($data->phone)
            ->setIsPrimary($data->isPrimary);
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
            'is_primary' => $contact->isPrimary(),
            'is_active' => $contact->isActive(),
        ];
    }
}