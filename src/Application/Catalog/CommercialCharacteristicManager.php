<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Users\User;
use App\Repository\Catalog\CommercialCharacteristicOptionRepository;
use App\Repository\Catalog\CommercialCharacteristicRepository;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class CommercialCharacteristicManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommercialItemCharacteristicRepository $configurationRepository,
        private readonly CommercialCharacteristicRepository $characteristicRepository,
        private readonly CommercialCharacteristicOptionRepository $optionRepository,
        private readonly CommercialCharacteristicTechnicalContract $technicalContract,
    ) {
    }

    public function create(CommercialCharacteristicData $data, User $actor): CommercialCharacteristic
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): CommercialCharacteristic {
            $characteristic = new CommercialCharacteristic();
            if ($data->displayOrder <= 0) {
                $data->displayOrder = $this->characteristicRepository->nextDisplayOrder();
            }
            $this->applyCharacteristicData($characteristic, $data);

            $this->entityManager->persist($characteristic);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'commercial_characteristic.created',
                'commercial_characteristic',
                $characteristic->getId(),
                null,
                $this->characteristicSnapshot($characteristic),
            );
            $this->entityManager->flush();

            return $characteristic;
        });
    }

    public function update(CommercialCharacteristic $characteristic, CommercialCharacteristicData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($characteristic, $data, $actor): void {
            $this->technicalContract->assertDefinitionPreserved($characteristic, $data);

            if ($this->configurationRepository->hasConfigurationForCharacteristic($characteristic)) {
                if (strtoupper(trim((string) $data->code)) !== $characteristic->getCode()) {
                    throw new \DomainException('No puedes cambiar el código técnico de una característica que ya está configurada en Productos. Retírala de esos Productos primero.');
                }

                if ($data->inputType !== $characteristic->getInputType()) {
                    throw new \DomainException('No puedes cambiar el tipo de captura de una característica que ya está configurada en Productos. Retírala de esos Productos primero.');
                }

                $submittedUnit = mb_strtolower(trim((string) $data->unitLabel));
                $currentUnit = mb_strtolower(trim((string) $characteristic->getUnitLabel()));
                if ($submittedUnit !== $currentUnit) {
                    throw new \DomainException('No puedes cambiar la unidad visible de una característica que ya está configurada en Productos. Retírala de esos Productos primero.');
                }
            }

            if (
                $data->inputType !== null
                && $data->inputType !== $characteristic->getInputType()
                && !$characteristic->getOptions()->isEmpty()
            ) {
                throw new \DomainException('No puedes cambiar el tipo de una característica que ya tiene opciones registradas.');
            }

            $oldValues = $this->characteristicSnapshot($characteristic);
            $data->displayOrder = $characteristic->getDisplayOrder();
            $this->applyCharacteristicData($characteristic, $data);
            $newValues = $this->characteristicSnapshot($characteristic);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                $actor,
                'commercial_characteristic.updated',
                'commercial_characteristic',
                $characteristic->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function setActive(CommercialCharacteristic $characteristic, bool $isActive, User $actor): void
    {
        if ($characteristic->isActive() === $isActive) {
            return;
        }

        if (!$isActive && $this->configurationRepository->hasActiveProductForCharacteristic($characteristic)) {
            throw new \DomainException('No puedes desactivar una característica asignada a un Producto activo. Retírala primero de esos Productos.');
        }

        $this->entityManager->wrapInTransaction(function () use ($characteristic, $isActive, $actor): void {
            $oldValues = $this->characteristicSnapshot($characteristic);
            $characteristic->setIsActive($isActive);
            $newValues = $this->characteristicSnapshot($characteristic);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'commercial_characteristic.activated' : 'commercial_characteristic.deactivated',
                'commercial_characteristic',
                $characteristic->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function createOption(
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicOptionData $data,
        User $actor,
    ): CommercialCharacteristicOption {
        $this->assertSupportsOptions($characteristic);

        return $this->entityManager->wrapInTransaction(function () use ($characteristic, $data, $actor): CommercialCharacteristicOption {
            if ($data->characteristic !== $characteristic) {
                throw new \LogicException('La opción no corresponde a la característica seleccionada.');
            }

            if ($data->displayOrder <= 0) {
                $data->displayOrder = $this->optionRepository->nextDisplayOrder($characteristic);
            }

            $option = new CommercialCharacteristicOption();
            $this->applyOptionData($option, $data, $characteristic);

            $this->entityManager->persist($option);
            $this->entityManager->flush();

            $this->auditLogger->record(
                $actor,
                'commercial_characteristic_option.created',
                'commercial_characteristic_option',
                $option->getId(),
                null,
                $this->optionSnapshot($option),
            );
            $this->entityManager->flush();

            return $option;
        });
    }

    public function updateOption(
        CommercialCharacteristicOption $option,
        CommercialCharacteristicOptionData $data,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($option, $data, $actor): void {
            $this->assertSupportsOptions($option->getCharacteristic());

            if ($data->characteristic !== $option->getCharacteristic()) {
                throw new \LogicException('La opción no corresponde a la característica seleccionada.');
            }

            if (
                $this->configurationRepository->hasProductForCharacteristicOption($option)
                && strtoupper(trim((string) $data->code)) !== $option->getCode()
            ) {
                throw new \DomainException('No puedes cambiar el código técnico de una opción que ya está permitida en Productos. Retírala de esos Productos primero.');
            }

            $oldValues = $this->optionSnapshot($option);
            $data->displayOrder = $option->getDisplayOrder();
            $this->applyOptionData($option, $data, $option->getCharacteristic());
            $newValues = $this->optionSnapshot($option);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record(
                $actor,
                'commercial_characteristic_option.updated',
                'commercial_characteristic_option',
                $option->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function setOptionActive(CommercialCharacteristicOption $option, bool $isActive, User $actor): void
    {
        if ($option->isActive() === $isActive) {
            return;
        }

        if (!$isActive && $this->configurationRepository->hasActiveProductForCharacteristicOption($option)) {
            throw new \DomainException('No puedes desactivar una opción permitida por un Producto activo. Retírala primero de esos Productos.');
        }

        $this->entityManager->wrapInTransaction(function () use ($option, $isActive, $actor): void {
            $oldValues = $this->optionSnapshot($option);
            $option->setIsActive($isActive);
            $newValues = $this->optionSnapshot($option);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'commercial_characteristic_option.activated' : 'commercial_characteristic_option.deactivated',
                'commercial_characteristic_option',
                $option->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function reorderActive(
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($movedId, $beforeId, $afterId, $actor): void {
            $characteristics = $this->characteristicRepository->findActiveOrderedForUpdate();
            $moved = $this->findCharacteristic($characteristics, $movedId);

            if ($moved === null) {
                throw new \DomainException('La característica que intentas reordenar ya no está disponible.');
            }

            $oldOrder = $this->characteristicOrderSnapshot($characteristics);
            $this->moveCharacteristic($characteristics, $moved, $beforeId, $afterId);

            if (array_column($oldOrder, 'id') === array_map(static fn (CommercialCharacteristic $item): int => (int) $item->getId(), $characteristics)) {
                return;
            }

            foreach ($characteristics as $index => $characteristic) {
                $characteristic->setDisplayOrder(($index + 1) * 10);
            }

            $this->auditLogger->record(
                $actor,
                'commercial_characteristic.reordered',
                'commercial_characteristic',
                $moved->getId(),
                ['active_order' => $oldOrder],
                ['active_order' => $this->characteristicOrderSnapshot($characteristics)],
            );
            $this->entityManager->flush();
        });
    }

    public function reorderActiveOptions(
        CommercialCharacteristic $characteristic,
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->assertSupportsOptions($characteristic);

        $this->entityManager->wrapInTransaction(function () use ($characteristic, $movedId, $beforeId, $afterId, $actor): void {
            $options = $this->optionRepository->findActiveForCharacteristicForUpdate($characteristic);
            $moved = $this->findOption($options, $movedId);

            if ($moved === null) {
                throw new \DomainException('La opción que intentas reordenar ya no está disponible.');
            }

            $oldOrder = $this->optionOrderSnapshot($options);
            $this->moveOption($options, $moved, $beforeId, $afterId);

            if (array_column($oldOrder, 'id') === array_map(static fn (CommercialCharacteristicOption $item): int => (int) $item->getId(), $options)) {
                return;
            }

            foreach ($options as $index => $option) {
                $option->setDisplayOrder(($index + 1) * 10);
            }

            $this->auditLogger->record(
                $actor,
                'commercial_characteristic_option.reordered',
                'commercial_characteristic',
                $characteristic->getId(),
                ['active_option_order' => $oldOrder],
                ['active_option_order' => $this->optionOrderSnapshot($options)],
            );
            $this->entityManager->flush();
        });
    }

    private function applyCharacteristicData(CommercialCharacteristic $characteristic, CommercialCharacteristicData $data): void
    {
        if ($data->inputType === null) {
            throw new \LogicException('Los datos de la característica están incompletos.');
        }

        $characteristic
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setInputType($data->inputType)
            ->setUnitLabel($data->unitLabel)
            ->setDisplayOrder($data->displayOrder);
    }

    private function applyOptionData(
        CommercialCharacteristicOption $option,
        CommercialCharacteristicOptionData $data,
        CommercialCharacteristic $characteristic,
    ): void {
        $option
            ->setCharacteristic($characteristic)
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setDisplayOrder($data->displayOrder);
    }

    private function assertSupportsOptions(CommercialCharacteristic $characteristic): void
    {
        if (!$characteristic->getInputType()->supportsOptions()) {
            throw new \DomainException('Solo las características de lista pueden administrar opciones.');
        }
    }

    /** @param list<CommercialCharacteristic> $characteristics */
    private function findCharacteristic(array $characteristics, int $id): ?CommercialCharacteristic
    {
        foreach ($characteristics as $characteristic) {
            if ($characteristic->getId() === $id) {
                return $characteristic;
            }
        }

        return null;
    }

    /** @param list<CommercialCharacteristicOption> $options */
    private function findOption(array $options, int $id): ?CommercialCharacteristicOption
    {
        foreach ($options as $option) {
            if ($option->getId() === $id) {
                return $option;
            }
        }

        return null;
    }

    /** @param list<CommercialCharacteristic> $items */
    private function moveCharacteristic(array &$items, CommercialCharacteristic $moved, ?int $beforeId, ?int $afterId): void
    {
        $index = array_search($moved, $items, true);
        array_splice($items, (int) $index, 1);

        $before = $beforeId !== null ? $this->findCharacteristic($items, $beforeId) : null;
        $after = $afterId !== null ? $this->findCharacteristic($items, $afterId) : null;
        $this->assertNeighborPosition($beforeId, $afterId, $before, $after, $items);

        if ($before !== null) {
            array_splice($items, (int) array_search($before, $items, true), 0, [$moved]);
        } elseif ($after !== null) {
            array_splice($items, (int) array_search($after, $items, true) + 1, 0, [$moved]);
        } else {
            $items[] = $moved;
        }
    }

    /** @param list<CommercialCharacteristicOption> $items */
    private function moveOption(array &$items, CommercialCharacteristicOption $moved, ?int $beforeId, ?int $afterId): void
    {
        $index = array_search($moved, $items, true);
        array_splice($items, (int) $index, 1);

        $before = $beforeId !== null ? $this->findOption($items, $beforeId) : null;
        $after = $afterId !== null ? $this->findOption($items, $afterId) : null;
        $this->assertNeighborPosition($beforeId, $afterId, $before, $after, $items);

        if ($before !== null) {
            array_splice($items, (int) array_search($before, $items, true), 0, [$moved]);
        } elseif ($after !== null) {
            array_splice($items, (int) array_search($after, $items, true) + 1, 0, [$moved]);
        } else {
            $items[] = $moved;
        }
    }

    /** @param array<int, object> $items */
    private function assertNeighborPosition(?int $beforeId, ?int $afterId, ?object $before, ?object $after, array $items): void
    {
        if (($beforeId !== null && $before === null) || ($afterId !== null && $after === null)) {
            throw new \DomainException('El orden cambió antes de poder guardar tu movimiento. Inténtalo de nuevo.');
        }

        if ($before !== null && $after !== null) {
            $beforeIndex = array_search($before, $items, true);
            $afterIndex = array_search($after, $items, true);

            if ($beforeIndex !== $afterIndex + 1) {
                throw new \DomainException('La posición seleccionada ya no es válida.');
            }
        }
    }

    /** @param list<CommercialCharacteristic> $characteristics @return list<array{id: int, display_order: int}> */
    private function characteristicOrderSnapshot(array $characteristics): array
    {
        return array_map(
            static fn (CommercialCharacteristic $characteristic): array => [
                'id' => (int) $characteristic->getId(),
                'display_order' => $characteristic->getDisplayOrder(),
            ],
            $characteristics,
        );
    }

    /** @param list<CommercialCharacteristicOption> $options @return list<array{id: int, display_order: int}> */
    private function optionOrderSnapshot(array $options): array
    {
        return array_map(
            static fn (CommercialCharacteristicOption $option): array => [
                'id' => (int) $option->getId(),
                'display_order' => $option->getDisplayOrder(),
            ],
            $options,
        );
    }

    /** @return array<string, bool|int|string|null> */
    private function characteristicSnapshot(CommercialCharacteristic $characteristic): array
    {
        return [
            'code' => $characteristic->getCode(),
            'name' => $characteristic->getName(),
            'input_type' => $characteristic->getInputType()->value,
            'unit_label' => $characteristic->getUnitLabel(),
            'display_order' => $characteristic->getDisplayOrder(),
            'is_active' => $characteristic->isActive(),
        ];
    }

    /** @return array<string, bool|int|string> */
    private function optionSnapshot(CommercialCharacteristicOption $option): array
    {
        return [
            'characteristic_id' => (int) $option->getCharacteristic()->getId(),
            'code' => $option->getCode(),
            'name' => $option->getName(),
            'display_order' => $option->getDisplayOrder(),
            'is_active' => $option->isActive(),
        ];
    }
}
