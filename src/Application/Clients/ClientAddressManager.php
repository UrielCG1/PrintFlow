<?php

namespace App\Application\Clients;

use App\Entity\Clients\Client;
use App\Entity\Clients\ClientAddress;
use App\Entity\Common\Address;
use App\Entity\Users\User;
use App\Repository\Clients\ClientAddressRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class ClientAddressManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientAddressRepository $clientAddressRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(
        Client $client,
        ClientAddressData $data,
        User $actor,
    ): ClientAddress {
        return $this->entityManager->wrapInTransaction(
            function () use ($client, $data, $actor): ClientAddress {
                $this->clearOtherDefaultAddresses(
                    client: $client,
                    except: null,
                    clearFiscal: $data->isDefaultFiscal,
                    clearDelivery: $data->isDefaultDelivery,
                    actor: $actor,
                );

                // Evita una colisión temporal con los índices únicos de MySQL.
                $this->entityManager->flush();

                $address = new ClientAddress($client);
                $this->applyData($address, $data);

                $sharedAddress = new Address(
                    (string) $data->street,
                    (string) $data->exteriorNumber,
                    (string) $data->postalCode,
                    (string) $data->municipality,
                );
                $address->setAddress($sharedAddress);
                $this->syncSharedAddress($sharedAddress, $data);

                $this->entityManager->persist($sharedAddress);
                $this->entityManager->persist($address);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'client_address.created',
                    entityType: 'client_address',
                    entityId: $address->getId(),
                    newValues: $this->snapshot($address),
                );

                $this->entityManager->flush();

                return $address;
            }
        );
    }

    public function update(
        ClientAddress $address,
        ClientAddressData $data,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(
            function () use ($address, $data, $actor): void {
                $oldValues = $this->snapshot($address);

                $this->clearOtherDefaultAddresses(
                    client: $address->getClient(),
                    except: $address,
                    clearFiscal: $data->isDefaultFiscal,
                    clearDelivery: $data->isDefaultDelivery,
                    actor: $actor,
                );

                // Primero libera las direcciones predeterminadas anteriores.
                $this->entityManager->flush();

                $this->applyData($address, $data);

                $newValues = $this->snapshot($address);

                if ($oldValues === $newValues) {
                    return;
                }

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'client_address.updated',
                    entityType: 'client_address',
                    entityId: $address->getId(),
                    oldValues: $oldValues,
                    newValues: $newValues,
                );

                $this->entityManager->flush();
            }
        );
    }

    public function setActive(
        ClientAddress $address,
        bool $isActive,
        User $actor,
    ): void {
        if ($address->isActive() === $isActive) {
            return;
        }

        $oldValues = $this->snapshot($address);
        $address->setIsActive($isActive);

        $this->entityManager->wrapInTransaction(
            function () use ($address, $isActive, $actor, $oldValues): void {
                $this->auditLogger->record(
                    actor: $actor,
                    action: $isActive
                        ? 'client_address.activated'
                        : 'client_address.deactivated',
                    entityType: 'client_address',
                    entityId: $address->getId(),
                    oldValues: $oldValues,
                    newValues: $this->snapshot($address),
                );

                $this->entityManager->flush();
            }
        );
    }

    private function applyData(
        ClientAddress $address,
        ClientAddressData $data,
    ): void {
        $address
            ->setLabel((string) $data->label)
            ->setRecipientName($data->recipientName)
            ->setStreet((string) $data->street)
            ->setExteriorNumber((string) $data->exteriorNumber)
            ->setInteriorNumber($data->interiorNumber)
            ->setNeighborhood($data->neighborhood)
            ->setPostalCode((string) $data->postalCode)
            ->setMunicipality((string) $data->municipality)
            ->setState((string) $data->state)
            ->setReferences($data->references)
            ->setIsFiscalAddress($data->isFiscalAddress)
            ->setIsDeliveryAddress($data->isDeliveryAddress)
            ->setIsDefaultFiscal($data->isDefaultFiscal)
            ->setIsDefaultDelivery($data->isDefaultDelivery);

        $address->setAddressType(
            $data->isDeliveryAddress ? 'DELIVERY' : ($data->isFiscalAddress ? 'FISCAL' : 'COMMERCIAL')
        );

        if ($address->getAddress() !== null) {
            $this->syncSharedAddress($address->getAddress(), $data);
        }
    }

    private function syncSharedAddress(Address $address, ClientAddressData $data): void
    {
        $address->setStreet((string) $data->street)
            ->setExteriorNumber((string) $data->exteriorNumber)
            ->setInteriorNumber($data->interiorNumber)
            ->setNeighborhood($data->neighborhood)
            ->setPostalCode((string) $data->postalCode)
            ->setCity((string) $data->municipality)
            ->setState((string) $data->state)
            ->setNotes($data->references);
    }

    private function clearOtherDefaultAddresses(
        Client $client,
        ?ClientAddress $except,
        bool $clearFiscal,
        bool $clearDelivery,
        User $actor,
    ): void {
        if (!$clearFiscal && !$clearDelivery) {
            return;
        }

        /** @var array<int, ClientAddress> $addresses */
        $addresses = [];

        if ($clearFiscal) {
            foreach (
                $this->clientAddressRepository->findOtherActiveDefaultFiscalAddresses(
                    $client,
                    $except,
                ) as $address
            ) {
                $addresses[(int) $address->getId()] = $address;
            }
        }

        if ($clearDelivery) {
            foreach (
                $this->clientAddressRepository->findOtherActiveDefaultDeliveryAddresses(
                    $client,
                    $except,
                ) as $address
            ) {
                $addresses[(int) $address->getId()] = $address;
            }
        }

        foreach ($addresses as $address) {
            $oldValues = $this->snapshot($address);
            $changed = false;

            if ($clearFiscal && $address->isDefaultFiscal()) {
                $address->setIsDefaultFiscal(false);
                $changed = true;
            }

            if ($clearDelivery && $address->isDefaultDelivery()) {
                $address->setIsDefaultDelivery(false);
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'client_address.default_changed',
                entityType: 'client_address',
                entityId: $address->getId(),
                oldValues: $oldValues,
                newValues: $this->snapshot($address),
            );
        }
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private function snapshot(ClientAddress $address): array
    {
        return [
            'client_id' => $address->getClient()->getId(),
            'label' => $address->getLabel(),
            'recipient_name' => $address->getRecipientName(),
            'street' => $address->getStreet(),
            'exterior_number' => $address->getExteriorNumber(),
            'interior_number' => $address->getInteriorNumber(),
            'neighborhood' => $address->getNeighborhood(),
            'postal_code' => $address->getPostalCode(),
            'municipality' => $address->getMunicipality(),
            'state' => $address->getState(),
            'country_code' => $address->getCountryCode(),
            'references' => $address->getReferences(),
            'is_fiscal_address' => $address->isFiscalAddress(),
            'is_delivery_address' => $address->isDeliveryAddress(),
            'is_default_fiscal' => $address->isDefaultFiscal(),
            'is_default_delivery' => $address->isDefaultDelivery(),
            'is_active' => $address->isActive(),
        ];
    }
}
