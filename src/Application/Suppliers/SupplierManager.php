<?php

namespace App\Application\Suppliers;

use App\Entity\Suppliers\Supplier;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Materials\MaterialRepository;

final class SupplierManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly MaterialRepository $materialRepository,
    ) {
    }

    public function create(SupplierData $data, User $actor): Supplier
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): Supplier {
            $supplier = new Supplier();
            $this->applyData($supplier, $data);

            $this->entityManager->persist($supplier);
            $this->entityManager->flush();

            $this->auditLogger->record(
                actor: $actor,
                action: 'supplier.created',
                entityType: 'supplier',
                entityId: $supplier->getId(),
                newValues: $this->snapshot($supplier),
            );

            $this->entityManager->flush();

            return $supplier;
        });
    }

    public function update(Supplier $supplier, SupplierData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($supplier, $data, $actor): void {
            $oldValues = $this->snapshot($supplier);

            $this->applyData($supplier, $data);

            $newValues = $this->snapshot($supplier);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'supplier.updated',
                entityType: 'supplier',
                entityId: $supplier->getId(),
                oldValues: $oldValues,
                newValues: $newValues,
            );

            $this->entityManager->flush();
        });
    }

    public function setActive(Supplier $supplier, bool $isActive, User $actor): void
    {
        if ($supplier->isActive() === $isActive) {
            return;
        }

        if (
            !$isActive
            && $this->materialRepository->hasActiveForPrimarySupplier($supplier)
        ) {
            throw new \DomainException(
                'No puedes desactivar un proveedor que es principal de materiales activos.',
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($supplier, $isActive, $actor): void {
            $oldValues = $this->snapshot($supplier);

            $supplier->setIsActive($isActive);

            $this->auditLogger->record(
                actor: $actor,
                action: $isActive ? 'supplier.activated' : 'supplier.deactivated',
                entityType: 'supplier',
                entityId: $supplier->getId(),
                oldValues: $oldValues,
                newValues: $this->snapshot($supplier),
            );

            $this->entityManager->flush();
        });
    }

    private function applyData(Supplier $supplier, SupplierData $data): void
    {
        $supplier
            ->setCode((string) $data->code)
            ->setBusinessName((string) $data->businessName)
            ->setLegalName($data->legalName)
            ->setTaxId($data->taxId)
            ->setBusinessActivity($data->businessActivity)
            ->setWebsite($data->website)
            ->setEmail($data->email)
            ->setPhone($data->phone)
            ->setNotes($data->notes);
    }

    /**
     * @return array<string, bool|string|null>
     */
    private function snapshot(Supplier $supplier): array
    {
        return [
            'code' => $supplier->getCode(),
            'business_name' => $supplier->getBusinessName(),
            'legal_name' => $supplier->getLegalName(),
            'tax_id' => $supplier->getTaxId(),
            'business_activity' => $supplier->getBusinessActivity(),
            'website' => $supplier->getWebsite(),
            'email' => $supplier->getEmail(),
            'phone' => $supplier->getPhone(),
            'notes' => $supplier->getNotes(),
            'is_active' => $supplier->isActive(),
        ];
    }
}
