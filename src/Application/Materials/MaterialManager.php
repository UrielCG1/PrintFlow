<?php

namespace App\Application\Materials;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Suppliers\Supplier;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class MaterialManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(MaterialData $data, User $actor): Material
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($data, $actor): Material {
                $material = new Material();

                $this->applyData($material, $data);

                $this->entityManager->persist($material);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    $actor,
                    'material.created',
                    'material',
                    $material->getId(),
                    null,
                    $this->snapshot($material),
                );

                $this->entityManager->flush();

                return $material;
            },
        );
    }

    public function update(
        Material $material,
        MaterialData $data,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(
            function () use ($material, $data, $actor): void {
                $oldValues = $this->snapshot($material);

                $this->applyData($material, $data);

                $newValues = $this->snapshot($material);

                if ($oldValues === $newValues) {
                    return;
                }

                $this->auditLogger->record(
                    $actor,
                    'material.updated',
                    'material',
                    $material->getId(),
                    $oldValues,
                    $newValues,
                );

                $this->entityManager->flush();
            },
        );
    }

    public function setActive(
        Material $material,
        bool $isActive,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(
            function () use ($material, $isActive, $actor): void {
                if ($material->isActive() === $isActive) {
                    return;
                }

                $oldValues = $this->snapshot($material);

                $material->setIsActive($isActive);

                $this->auditLogger->record(
                    $actor,
                    $isActive ? 'material.activated' : 'material.deactivated',
                    'material',
                    $material->getId(),
                    $oldValues,
                    $this->snapshot($material),
                );

                $this->entityManager->flush();
            },
        );
    }

    private function applyData(Material $material, MaterialData $data): void
    {
        $material
            ->setCode($this->requiredText($data->code, 'El código es obligatorio.'))
            ->setName($this->requiredText($data->name, 'El nombre es obligatorio.'))
            ->setDescription($data->description)
            ->setCategory($this->activeCategory($data))
            ->setMeasurementUnit($this->activeMeasurementUnit($data))
            ->setPrimarySupplier($this->activePrimarySupplier($data))
            ->setReferenceCost(
                $this->requiredText(
                    $data->referenceCost,
                    'El costo de referencia es obligatorio.',
                ),
            )
            ->setMinimumStock(
                $this->requiredText(
                    $data->minimumStock,
                    'El stock mínimo es obligatorio.',
                ),
            )
            ->setNotes($data->notes);
    }

    private function activeCategory(MaterialData $data): MaterialCategory
    {
        $category = $data->category;

        if (!$category instanceof MaterialCategory) {
            throw new \DomainException('Selecciona una categoría de materiales.');
        }

        if (!$category->isActive()) {
            throw new \DomainException(
                'La categoría seleccionada está inactiva.',
            );
        }

        return $category;
    }

    private function activeMeasurementUnit(MaterialData $data): MeasurementUnit
    {
        $measurementUnit = $data->measurementUnit;

        if (!$measurementUnit instanceof MeasurementUnit) {
            throw new \DomainException('Selecciona la unidad de inventario.');
        }

        if (!$measurementUnit->isActive()) {
            throw new \DomainException(
                'La unidad de inventario seleccionada está inactiva.',
            );
        }

        return $measurementUnit;
    }

    private function activePrimarySupplier(MaterialData $data): ?Supplier
    {
        $supplier = $data->primarySupplier;

        if ($supplier === null) {
            return null;
        }

        if (!$supplier->isActive()) {
            throw new \DomainException(
                'El proveedor principal seleccionado está inactivo.',
            );
        }

        return $supplier;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Material $material): array
    {
        $supplier = $material->getPrimarySupplier();

        return [
            'code' => $material->getCode(),
            'name' => $material->getName(),
            'description' => $material->getDescription(),
            'category' => [
                'id' => $material->getCategory()->getId(),
                'code' => $material->getCategory()->getCode(),
                'name' => $material->getCategory()->getName(),
            ],
            'measurement_unit' => [
                'id' => $material->getMeasurementUnit()->getId(),
                'code' => $material->getMeasurementUnit()->getCode(),
                'name' => $material->getMeasurementUnit()->getName(),
            ],
            'primary_supplier' => $supplier === null ? null : [
                'id' => $supplier->getId(),
                'code' => $supplier->getCode(),
                'business_name' => $supplier->getBusinessName(),
            ],
            'reference_cost' => $material->getReferenceCost(),
            'minimum_stock' => $material->getMinimumStock(),
            'notes' => $material->getNotes(),
            'is_active' => $material->isActive(),
        ];
    }

    private function requiredText(?string $value, string $message): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new \DomainException($message);
        }

        return $value;
    }
}