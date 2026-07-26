<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class CommercialItemManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(CommercialItemData $data, User $actor): CommercialItem
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): CommercialItem {
            $item = new CommercialItem();
            $this->applyCatalogData($item, $data);
            $item->setBasePrice((string) $data->basePrice);
            $this->assertActiveDependencies($item);

            $this->entityManager->persist($item);
            $this->entityManager->flush();

            $this->auditLogger->record($actor, 'commercial_item.created', 'commercial_item', $item->getId(), null, $this->snapshot($item));
            $this->entityManager->flush();

            return $item;
        });
    }

    public function update(CommercialItem $item, CommercialItemData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($item, $data, $actor): void {
            $oldValues = $this->snapshot($item);
            $this->applyCatalogData($item, $data);

            if ($item->isActive()) {
                $this->assertActiveDependencies($item);
            }

            $newValues = $this->snapshot($item);
            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record($actor, 'commercial_item.updated', 'commercial_item', $item->getId(), $oldValues, $newValues);
            $this->entityManager->flush();
        });
    }

    public function updateBasePrice(CommercialItem $item, string $basePrice, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($item, $basePrice, $actor): void {
            $oldValues = $this->snapshot($item);
            $item->setBasePrice($basePrice);
            $newValues = $this->snapshot($item);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record($actor, 'commercial_item.price_updated', 'commercial_item', $item->getId(), $oldValues, $newValues);
            $this->entityManager->flush();
        });
    }

    public function setActive(CommercialItem $item, bool $isActive, User $actor): void
    {
        if ($item->isActive() === $isActive) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($item, $isActive, $actor): void {
            if ($isActive) {
                $this->assertActiveDependencies($item);
            }

            $oldValues = $this->snapshot($item);
            $item->setIsActive($isActive);
            $newValues = $this->snapshot($item);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'commercial_item.activated' : 'commercial_item.deactivated',
                'commercial_item',
                $item->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    private function applyCatalogData(CommercialItem $item, CommercialItemData $data): void
    {
        $item
            ->setCategory($data->category ?? throw new \LogicException('La categoría comercial es obligatoria.'))
            ->setMeasurementUnit($data->measurementUnit ?? throw new \LogicException('La unidad de medida es obligatoria.'))
            ->setCode((string) $data->code)
            ->setType($data->type)
            ->setName((string) $data->name)
            ->setDescription($data->description);
    }

    private function assertActiveDependencies(CommercialItem $item): void
    {
        if (!$item->getCategory()->isActive()) {
            throw new \DomainException('No puedes activar o asignar un concepto a una categoría comercial inactiva.');
        }

        if (!$item->getMeasurementUnit()->isActive()) {
            throw new \DomainException('No puedes activar o asignar un concepto a una unidad de medida inactiva.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function snapshot(CommercialItem $item): array
    {
        return [
            'category_id' => $item->getCategory()->getId(),
            'measurement_unit_id' => $item->getMeasurementUnit()->getId(),
            'code' => $item->getCode(),
            'type' => $item->getType(),
            'name' => $item->getName(),
            'description' => $item->getDescription(),
            'base_price' => $item->getBasePrice(),
            'is_active' => $item->isActive(),
        ];
    }
}