<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Enum\Catalog\CommercialCharacteristicInputType;

/**
 * Protege características cuyo identificador técnico forma parte del contrato
 * de integración del cotizador. Sus nombres visibles pueden cambiar, pero el
 * código, tipo de captura y unidad técnica deben permanecer estables.
 */
final class CommercialCharacteristicTechnicalContract
{
    /**
     * @var array<string, array{label: string, inputType: CommercialCharacteristicInputType, unitLabel: string, description: string}>
     */
    private const DEFINITIONS = [
        'FINISHED_WIDTH_CM' => [
            'label' => 'Ancho terminado',
            'inputType' => CommercialCharacteristicInputType::DECIMAL,
            'unitLabel' => 'cm',
            'description' => 'El cotizador de Gran formato utiliza este identificador para reconocer el ancho terminado y calcular la superficie.',
        ],
        'FINISHED_HEIGHT_CM' => [
            'label' => 'Alto terminado',
            'inputType' => CommercialCharacteristicInputType::DECIMAL,
            'unitLabel' => 'cm',
            'description' => 'El cotizador de Gran formato utiliza este identificador para reconocer el alto terminado y calcular la superficie.',
        ],
    ];

    /** @return array{code: string, label: string, inputType: CommercialCharacteristicInputType, unitLabel: string, description: string}|null */
    public function forCharacteristic(CommercialCharacteristic $characteristic): ?array
    {
        return $this->forCode($characteristic->getCode());
    }

    /** @return array{code: string, label: string, inputType: CommercialCharacteristicInputType, unitLabel: string, description: string}|null */
    public function forCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        $definition = self::DEFINITIONS[$code] ?? null;

        if ($definition === null) {
            return null;
        }

        return ['code' => $code, ...$definition];
    }

    public function assertDefinitionPreserved(
        CommercialCharacteristic $characteristic,
        CommercialCharacteristicData $data,
    ): void {
        $definition = $this->forCharacteristic($characteristic);
        if ($definition === null) {
            return;
        }

        if (strtoupper(trim((string) $data->code)) !== $definition['code']) {
            throw new \DomainException(sprintf(
                'El código %s forma parte del contrato técnico del cotizador y no puede modificarse.',
                $definition['code'],
            ));
        }

        if ($data->inputType !== $definition['inputType']) {
            throw new \DomainException(sprintf(
                'La característica %s debe conservar el tipo de dato "%s" porque lo utiliza el cotizador.',
                $definition['label'],
                $definition['inputType']->label(),
            ));
        }

        if (mb_strtolower(trim((string) $data->unitLabel)) !== mb_strtolower($definition['unitLabel'])) {
            throw new \DomainException(sprintf(
                'La característica %s debe conservar la unidad "%s" porque lo utiliza el cálculo de Gran formato.',
                $definition['label'],
                $definition['unitLabel'],
            ));
        }
    }
}
