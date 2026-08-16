<?php

namespace App\Application\Clients;

use App\Entity\Clients\Client;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class ClientManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(ClientData $data, User $actor): Client
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Client {
            $client = new Client();
            $this->applyData($client, $data);

            $this->entityManager->persist($client);
            $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'client.created',
                entityType: 'client',
                entityId: $client->getId(),
                newValues: $this->snapshot($client),
            );

            $this->entityManager->flush();

            return $client;
        });
    }

    public function update(Client $client, ClientData $data, User $actor): void
    {
        $oldValues = $this->snapshot($client);

        $this->applyData($client, $data);

        $newValues = $this->snapshot($client);

        if ($oldValues === $newValues) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use (
            $client,
            $actor,
            $oldValues,
            $newValues,
        ): void {
            $this->auditLogger->record(
                actor: $actor,
                action: 'client.updated',
                entityType: 'client',
                entityId: $client->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->entityManager->flush();
        });
    }

    public function setActive(Client $client, bool $isActive, User $actor): void
    {
        if ($client->isActive() === $isActive) {
            return;
        }

        $oldValues = $this->snapshot($client);
        $client->setIsActive($isActive);
        $newValues = $this->snapshot($client);

        $this->entityManager->wrapInTransaction(function () use (
            $client,
            $actor,
            $isActive,
            $oldValues,
            $newValues,
        ): void {
            $this->auditLogger->record(
                actor: $actor,
                action: $isActive ? 'client.activated' : 'client.deactivated',
                entityType: 'client',
                entityId: $client->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->entityManager->flush();
        });
    }

    private function applyData(Client $client, ClientData $data): void
    {
        $client
            ->setClientType($data->clientType)
            ->setBusinessName((string) $data->businessName)
            ->setTaxId($data->taxId)
            ->setLegalName($data->legalName)
            ->setBusinessActivity($data->businessActivity)
            ->setWebsite($data->website)
            ->setBirthDate($data->birthDate)
            ->setTaxRegimeCode($data->taxRegimeCode)
            ->setFiscalPostalCode($data->fiscalPostalCode)
            ->setBillingEmail($data->billingEmail)
            ->setDefaultCfdiUseCode($data->defaultCfdiUseCode)
            ->setCategory($data->category)
            ->setDefaultDiscountPercent(round($data->defaultDiscountPercent, 2))
            ->setEmail($data->email)
            ->setPhone($data->phone)
            ->setNotes($data->notes);
    }
    /**
     * @return array<string, bool|float|int|string|null>
     */
    private function snapshot(Client $client): array
    {
        return [
            'client_type' => $client->getClientType(),
            'business_name' => $client->getBusinessName(),
            'tax_id' => $client->getTaxId(),
            'legal_name' => $client->getLegalName(),
            'business_activity' => $client->getBusinessActivity(),
            'website' => $client->getWebsite(),
            'birth_date' => $client->getBirthDate()?->format('Y-m-d'),
            'tax_regime_code' => $client->getTaxRegimeCode(),
            'fiscal_postal_code' => $client->getFiscalPostalCode(),
            'billing_email' => $client->getBillingEmail(),
            'default_cfdi_use_code' => $client->getDefaultCfdiUseCode(),
            'client_category_id' => $client->getCategory()?->getId(),
            'default_discount_percent' => $client->getDefaultDiscountPercent(),
            'email' => $client->getEmail(),
            'phone' => $client->getPhone(),
            'notes' => $client->getNotes(),
            'is_active' => $client->isActive(),
        ];
    }
}
