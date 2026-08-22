<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\CommercialItemCharacteristicOption;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialItemType;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class CommercialItemCharacteristicManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommercialItemCharacteristicRepository $configurationRepository,
    ) {
    }

    public function create(
        CommercialItem $item,
        CommercialCharacteristic $characteristic,
        CommercialItemCharacteristicData $data,
        User $actor,
    ): CommercialItemCharacteristic {
        $this->assertConfigurableProduct($item);

        if (!$characteristic->isActive()) {
            throw new \DomainException('Solo puedes asignar características activas.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($item, $characteristic, $data, $actor): CommercialItemCharacteristic {
            if ($this->configurationRepository->findOneForItemAndCharacteristic($item, $characteristic) !== null) {
                throw new \DomainException('Esta característica ya está configurada para el Producto.');
            }

            if ($data->displayOrder <= 0) {
                $data->displayOrder = $this->configurationRepository->nextDisplayOrderForItem($item);
            }

            $configuration = (new CommercialItemCharacteristic())
                ->setCommercialItem($item)
                ->setCharacteristic($characteristic)
                ->setIsRequired($data->isRequired)
                ->setDisplayOrder($data->displayOrder);

            $this->syncAllowedOptions($configuration, $data->allowedOptions, false);

            $this->entityManager->persist($configuration);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'commercial_item_characteristic.created',
                'commercial_item_characteristic',
                $configuration->getId(),
                null,
                $this->snapshot($configuration),
            );
            $this->entityManager->flush();

            return $configuration;
        });
    }

    public function update(
        CommercialItemCharacteristic $configuration,
        CommercialItemCharacteristicData $data,
        User $actor,
    ): void {
        $this->assertConfigurableProduct($configuration->getCommercialItem());

        $this->entityManager->wrapInTransaction(function () use ($configuration, $data, $actor): void {
            $oldValues = $this->snapshot($configuration);
            $data->displayOrder = $configuration->getDisplayOrder();
            $newAllowedOptions = $this->validateAllowedOptions(
                $configuration->getCharacteristic(),
                $data->allowedOptions,
                $configuration,
            );
            $newValues = $this->dataSnapshot($configuration, $data, $newAllowedOptions);

            if ($oldValues === $newValues) {
                return;
            }

            $configuration
                ->setIsRequired($data->isRequired)
                ->setDisplayOrder($data->displayOrder);
            $this->replaceAllowedOptions($configuration, $newAllowedOptions);

            $this->auditLogger->record(
                $actor,
                'commercial_item_characteristic.updated',
                'commercial_item_characteristic',
                $configuration->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function remove(CommercialItemCharacteristic $configuration, User $actor): void
    {
        $this->assertConfigurableProduct($configuration->getCommercialItem());

        $this->entityManager->wrapInTransaction(function () use ($configuration, $actor): void {
            $oldValues = $this->snapshot($configuration);
            $configurationId = $configuration->getId();

            $this->entityManager->remove($configuration);
            $this->auditLogger->record(
                $actor,
                'commercial_item_characteristic.removed',
                'commercial_item_characteristic',
                $configurationId,
                $oldValues,
                null,
            );
            $this->entityManager->flush();
        });
    }

    public function reorderForItem(
        CommercialItem $item,
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->assertConfigurableProduct($item);

        $this->entityManager->wrapInTransaction(function () use ($item, $movedId, $beforeId, $afterId, $actor): void {
            $configurations = $this->configurationRepository->findForItemForUpdate($item);
            $moved = $this->findConfiguration($configurations, $movedId);

            if ($moved === null) {
                throw new \DomainException('La característica que intentas reordenar ya no está configurada en este Producto.');
            }

            $oldOrder = $this->orderSnapshot($configurations);
            $movedIndex = array_search($moved, $configurations, true);
            array_splice($configurations, (int) $movedIndex, 1);

            $before = $beforeId !== null ? $this->findConfiguration($configurations, $beforeId) : null;
            $after = $afterId !== null ? $this->findConfiguration($configurations, $afterId) : null;

            if (($beforeId !== null && $before === null) || ($afterId !== null && $after === null)) {
                throw new \DomainException('El orden cambió antes de poder guardar tu movimiento. Inténtalo de nuevo.');
            }

            if ($before !== null && $after !== null) {
                $beforeIndex = array_search($before, $configurations, true);
                $afterIndex = array_search($after, $configurations, true);
                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida.');
                }
            }

            if ($before !== null) {
                array_splice($configurations, (int) array_search($before, $configurations, true), 0, [$moved]);
            } elseif ($after !== null) {
                array_splice($configurations, (int) array_search($after, $configurations, true) + 1, 0, [$moved]);
            } else {
                $configurations[] = $moved;
            }

            $newIds = array_map(static fn (CommercialItemCharacteristic $configuration): int => (int) $configuration->getId(), $configurations);
            if (array_column($oldOrder, 'id') === $newIds) {
                return;
            }

            foreach ($configurations as $index => $configuration) {
                $configuration->setDisplayOrder(($index + 1) * 10);
            }

            $this->auditLogger->record(
                $actor,
                'commercial_item_characteristic.reordered',
                'commercial_item',
                $item->getId(),
                ['characteristic_order' => $oldOrder],
                ['characteristic_order' => $this->orderSnapshot($configurations)],
            );
            $this->entityManager->flush();
        });
    }

    /**
     * @param list<CommercialCharacteristicOption> $options
     */
    private function syncAllowedOptions(
        CommercialItemCharacteristic $configuration,
        array $options,
        bool $allowExistingInactive,
    ): void {
        $validatedOptions = $this->validateAllowedOptions(
            $configuration->getCharacteristic(),
            $options,
            $allowExistingInactive ? $configuration : null,
        );
        $this->replaceAllowedOptions($configuration, $validatedOptions);
    }

    /**
     * @param list<CommercialCharacteristicOption> $options
     * @return list<CommercialCharacteristicOption>
     */
    private function validateAllowedOptions(
        CommercialCharacteristic $characteristic,
        array $options,
        ?CommercialItemCharacteristic $existingConfiguration,
    ): array {
        if (!$characteristic->getInputType()->supportsOptions()) {
            if ($options !== []) {
                throw new \DomainException('Una característica que no es de lista no puede tener opciones permitidas.');
            }

            return [];
        }

        $existingOptionIds = [];
        if ($existingConfiguration !== null) {
            foreach ($existingConfiguration->getAllowedOptions() as $allowedOption) {
                $existingOptionIds[] = $allowedOption->getCharacteristicOption()->getId();
            }
        }

        $uniqueOptions = [];
        foreach ($options as $option) {
            if (!$option instanceof CommercialCharacteristicOption) {
                throw new \LogicException('Las opciones seleccionadas no son válidas.');
            }

            if ($option->getCharacteristic() !== $characteristic) {
                throw new \DomainException('Todas las opciones deben pertenecer a la característica configurada.');
            }

            $optionId = $option->getId();
            if ($optionId === null) {
                throw new \LogicException('La opción seleccionada aún no está registrada.');
            }

            if (!$option->isActive() && !in_array($optionId, $existingOptionIds, true)) {
                throw new \DomainException('Solo puedes agregar opciones activas al Producto.');
            }

            $uniqueOptions[$optionId] = $option;
        }

        if ($uniqueOptions === []) {
            throw new \DomainException('Selecciona al menos una opción permitida para esta característica.');
        }

        return array_values($uniqueOptions);
    }

    /** @param list<CommercialCharacteristicOption> $options */
    private function replaceAllowedOptions(CommercialItemCharacteristic $configuration, array $options): void
    {
        foreach ($configuration->getAllowedOptions()->toArray() as $allowedOption) {
            $configuration->removeAllowedOption($allowedOption);
        }

        foreach ($options as $position => $option) {
            $allowedOption = (new CommercialItemCharacteristicOption())
                ->setCharacteristicOption($option)
                ->setDisplayOrder(($position + 1) * 10);
            $configuration->addAllowedOption($allowedOption);
        }
    }

    private function assertConfigurableProduct(CommercialItem $item): void
    {
        if ($item->getType() !== CommercialItemType::PRODUCT) {
            throw new \DomainException('Las características solo se configuran en Productos comerciales.');
        }
    }

    /** @param list<CommercialItemCharacteristic> $configurations */
    private function findConfiguration(array $configurations, int $id): ?CommercialItemCharacteristic
    {
        foreach ($configurations as $configuration) {
            if ($configuration->getId() === $id) {
                return $configuration;
            }
        }

        return null;
    }

    /** @param list<CommercialItemCharacteristic> $configurations @return list<array{id: int, display_order: int}> */
    private function orderSnapshot(array $configurations): array
    {
        return array_map(
            static fn (CommercialItemCharacteristic $configuration): array => [
                'id' => (int) $configuration->getId(),
                'display_order' => $configuration->getDisplayOrder(),
            ],
            $configurations,
        );
    }

    /** @return array<string, bool|int|string|list<array{id: int, name: string}>> */
    private function snapshot(CommercialItemCharacteristic $configuration): array
    {
        $options = [];
        foreach ($configuration->getAllowedOptions() as $allowedOption) {
            $option = $allowedOption->getCharacteristicOption();
            $options[] = [
                'id' => (int) $option->getId(),
                'name' => $option->getName(),
            ];
        }

        usort($options, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return [
            'commercial_item_id' => (int) $configuration->getCommercialItem()->getId(),
            'characteristic_id' => (int) $configuration->getCharacteristic()->getId(),
            'characteristic' => $configuration->getCharacteristic()->getName(),
            'is_required' => $configuration->isRequired(),
            'display_order' => $configuration->getDisplayOrder(),
            'allowed_options' => $options,
        ];
    }

    /**
     * @param list<CommercialCharacteristicOption> $options
     * @return array<string, bool|int|string|list<array{id: int, name: string}>>
     */
    private function dataSnapshot(
        CommercialItemCharacteristic $configuration,
        CommercialItemCharacteristicData $data,
        array $options,
    ): array {
        $snapshot = $this->snapshot($configuration);
        $snapshot['is_required'] = $data->isRequired;
        $snapshot['display_order'] = $data->displayOrder;
        $snapshot['allowed_options'] = array_map(
            static fn (CommercialCharacteristicOption $option): array => [
                'id' => (int) $option->getId(),
                'name' => $option->getName(),
            ],
            $options,
        );
        usort($snapshot['allowed_options'], static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $snapshot;
    }
}
