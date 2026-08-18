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
            $this->applyData($item, $data, true);

            $this->entityManager->persist($item);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'commercial_item.created',
                'commercial_item',
                $item->getId(),
                null,
                $this->snapshot($item),
            );

            $this->entityManager->flush();

            return $item;
        });
    }

    public function update(
        CommercialItem $item,
        CommercialItemData $data,
        bool $canUpdatePrice,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($item, $data, $canUpdatePrice, $actor): void {
            $oldValues = $this->snapshot($item);

            $this->applyData($item, $data, $canUpdatePrice);

            $newValues = $this->snapshot($item);

            if ($oldValues === $newValues) {
                return;
            }

            $action = $oldValues['base_price'] !== $newValues['base_price']
                ? 'commercial_item.price_updated'
                : 'commercial_item.updated';

            $this->auditLogger->record(
                $actor,
                $action,
                'commercial_item',
                $item->getId(),
                $oldValues,
                $newValues,
            );

            $this->entityManager->flush();
        });
    }

    public function setActive(CommercialItem $item, bool $isActive, User $actor): void
    {
        if ($item->isActive() === $isActive) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($item, $isActive, $actor): void {
            $oldValues = $this->snapshot($item);

            $item->setIsActive($isActive);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'commercial_item.activated' : 'commercial_item.deactivated',
                'commercial_item',
                $item->getId(),
                $oldValues,
                $this->snapshot($item),
            );

            $this->entityManager->flush();
        });
    }

    private function applyData(
        CommercialItem $item,
        CommercialItemData $data,
        bool $includeBasePrice,
    ): void {
        if (
            $data->category === null
            || $data->measurementUnit === null
            || $data->type === null
            || $data->quotationSpecificationProfile === null
        ) {
            throw new \LogicException('Los datos del concepto comercial están incompletos.');
        }

        $isNewItem = $item->getId() === null;

        if (
            ($isNewItem || $item->getCategory() !== $data->category)
            && !$data->category->isActive()
        ) {
            throw new \DomainException('Solo puedes asignar categorías comerciales activas.');
        }

        if (
            ($isNewItem || $item->getMeasurementUnit() !== $data->measurementUnit)
            && !$data->measurementUnit->isActive()
        ) {
            throw new \DomainException('Solo puedes asignar unidades de medida activas.');
        }

        $item
            ->setCategory($data->category)
            ->setMeasurementUnit($data->measurementUnit)
            ->setCode((string) $data->code)
            ->setType($data->type)
            ->setQuotationSpecificationProfile($data->quotationSpecificationProfile)
            ->setName((string) $data->name)
            ->setDescription($data->description);

        if ($includeBasePrice) {
            $item->setBasePrice((string) $data->basePrice);
        }
    }

    /** @return array<string, bool|string|null> */
    private function snapshot(CommercialItem $item): array
    {
        return [
            'code' => $item->getCode(),
            'type' => $item->getType()->label(),
            'quotation_specification_profile' => $item->getQuotationSpecificationProfile()->label(),
            'name' => $item->getName(),
            'description' => $item->getDescription(),
            'category' => $item->getCategory()->getCode().' — '.$item->getCategory()->getName(),
            'measurement_unit' => $item->getMeasurementUnit()->getCode().' — '.$item->getMeasurementUnit()->getName(),
            'base_price' => $item->getBasePrice(),
            'is_active' => $item->isActive(),
        ];
    }
}
