<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialCharacteristicOption;
use App\Entity\Users\User;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class CommercialCharacteristicManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommercialItemCharacteristicRepository $configurationRepository,
    ) {
    }

    public function create(CommercialCharacteristicData $data, User $actor): CommercialCharacteristic
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): CommercialCharacteristic {
            $characteristic = new CommercialCharacteristic();
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
            if (
                $data->inputType !== null
                && $data->inputType !== $characteristic->getInputType()
                && !$characteristic->getOptions()->isEmpty()
            ) {
                throw new \DomainException('No puedes cambiar el tipo de una característica que ya tiene opciones registradas.');
            }

            $oldValues = $this->characteristicSnapshot($characteristic);
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

            $oldValues = $this->optionSnapshot($option);
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
