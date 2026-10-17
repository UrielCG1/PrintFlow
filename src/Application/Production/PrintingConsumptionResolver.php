<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Enum\Inventory\QuantitySource;
use Brick\Math\BigDecimal;

/** Resolves a confirmed printing plan or a measured deviation without treating an estimate as a measurement. */
final class PrintingConsumptionResolver
{
    /** @return array{total: string, planned: ?string, source: QuantitySource, method: string, adjustment_reason: ?string} */
    public function resolve(
        ?string $plannedQuantity,
        bool $sameMaterial,
        ?string $measuredTotal,
        ?string $measurementMethod,
        ?string $adjustmentReason,
        bool $confirmed,
    ): array {
        if (!$confirmed) {
            throw new \DomainException('Confirma el total de material utilizado antes de cerrar la impresión.');
        }

        $planned = $sameMaterial ? $plannedQuantity : null;
        $measuredTotal = trim(str_replace(',', '.', (string) $measuredTotal));
        if ($measuredTotal === '') {
            if ($planned === null || BigDecimal::of($planned)->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw new \DomainException('El material escaneado no tiene una cantidad prevista aplicable. Mide e indica el total realmente utilizado.');
            }

            return [
                'total' => BigDecimal::of($planned)->toScale(6)->__toString(),
                'planned' => BigDecimal::of($planned)->toScale(6)->__toString(),
                'source' => QuantitySource::DERIVED,
                'method' => 'plan confirmado',
                'adjustment_reason' => null,
            ];
        }

        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $measuredTotal) !== 1
            || BigDecimal::of($measuredTotal)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException('Indica un consumo real positivo con máximo seis decimales.');
        }

        $measurementMethod = trim((string) $measurementMethod);
        if (!in_array($measurementMethod, ['medición manual', 'lectura de máquina'], true)) {
            throw new \InvalidArgumentException('Indica cómo mediste el consumo real diferente al previsto.');
        }

        $adjustmentReason = trim((string) $adjustmentReason);
        if (mb_strlen($adjustmentReason) > 500) {
            throw new \InvalidArgumentException('El motivo del cambio no puede superar 500 caracteres.');
        }
        if (($planned === null || !BigDecimal::of($measuredTotal)->isEqualTo(BigDecimal::of($planned)))
            && mb_strlen($adjustmentReason) < 5) {
            throw new \InvalidArgumentException('Explica por qué el consumo real difiere del material previsto o utiliza un sustituto.');
        }

        return [
            'total' => BigDecimal::of($measuredTotal)->toScale(6)->__toString(),
            'planned' => $planned !== null ? BigDecimal::of($planned)->toScale(6)->__toString() : null,
            'source' => QuantitySource::MEASURED,
            'method' => $measurementMethod,
            'adjustment_reason' => $adjustmentReason !== '' ? $adjustmentReason : null,
        ];
    }
}
