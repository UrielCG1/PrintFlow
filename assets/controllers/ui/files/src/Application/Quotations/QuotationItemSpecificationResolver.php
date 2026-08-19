<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Normaliza y congela las especificaciones técnicas de una partida interna.
 *
 * El navegador solo mejora la captura. Esta clase es la autoridad para la
 * fórmula, la validación y el origen de la cantidad que llega al precio.
 */
final class QuotationItemSpecificationResolver
{
    private const SCHEMA_VERSION = 1;
    private const QUANTITY_MODE_AUTO = 'AUTO';
    private const QUANTITY_MODE_MANUAL = 'MANUAL';

    /**
     * @param array<string, mixed> $submittedSpecifications
     *
     * @return array{quantity: string, schema_version: int, snapshot: array<string, mixed>}
     */
    public function __construct(
        private readonly ?CommercialItemCharacteristicRepository $configurationRepository = null,
        private readonly ?QuotationItemCharacteristicsSpecificationResolver $characteristicsResolver = null,
    ) {
    }

    public function resolve(
        CommercialItem $commercialItem,
        array $submittedSpecifications,
        string $submittedQuantity,
        string $submittedQuantityMode,
    ): array {
        $submittedQuantity = ItemPriceRule::normalizeMinimumQuantity($submittedQuantity);

        $configurations = $this->configurationRepository?->findForQuotationItem($commercialItem) ?? [];
        if ($configurations !== []) {
            if ($this->characteristicsResolver === null) {
                throw new \LogicException('El resolvedor de características comerciales no está disponible.');
            }

            return $this->characteristicsResolver->resolve(
                $commercialItem,
                $configurations,
                $submittedSpecifications,
                $submittedQuantity,
                $submittedQuantityMode,
            );
        }

        if ($commercialItem->getQuotationSpecificationProfile() === QuotationItemSpecificationProfile::NONE) {
            return [
                'quantity' => $submittedQuantity,
                'schema_version' => self::SCHEMA_VERSION,
                'snapshot' => [],
            ];
        }

        return $this->resolveLargeFormat(
            $commercialItem,
            $submittedSpecifications,
            $submittedQuantity,
            $submittedQuantityMode,
        );
    }

    /**
     * @param array<string, mixed> $submittedSpecifications
     *
     * @return array{quantity: string, schema_version: int, snapshot: array<string, mixed>}
     */
    private function resolveLargeFormat(
        CommercialItem $commercialItem,
        array $submittedSpecifications,
        string $submittedQuantity,
        string $submittedQuantityMode,
    ): array {
        $width = $this->normalizeDimension(
            $submittedSpecifications['finished_width_cm'] ?? null,
            'El ancho terminado',
        );
        $height = $this->normalizeDimension(
            $submittedSpecifications['finished_height_cm'] ?? null,
            'El alto terminado',
        );

        $area = BigDecimal::of($width)
            ->multipliedBy($height)
            ->dividedBy('10000', 4, RoundingMode::HalfUp)
            ->__toString();

        $quantityMode = strtoupper(trim($submittedQuantityMode));
        if (!in_array($quantityMode, [self::QUANTITY_MODE_AUTO, self::QUANTITY_MODE_MANUAL], true)) {
            throw new \InvalidArgumentException('El origen de la cantidad de la partida no es válido.');
        }

        $isBilledBySquareMetre = strtoupper($commercialItem->getMeasurementUnit()->getCode()) === 'M2';
        $usesCalculatedArea = $isBilledBySquareMetre && $quantityMode === self::QUANTITY_MODE_AUTO;

        if ($usesCalculatedArea && $area === '0.0000') {
            throw new \DomainException(
                'La superficie calculada es menor a 0.0001 m². Captura medidas mayores.',
            );
        }

        $quantity = $usesCalculatedArea ? $area : $submittedQuantity;
        $billingUnit = $commercialItem->getMeasurementUnit();
        $billingUnitLabel = $isBilledBySquareMetre ? 'm²' : $billingUnit->getName();

        return [
            'quantity' => $quantity,
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot' => [
                'profile' => QuotationItemSpecificationProfile::LARGE_FORMAT->value,
                'schema_version' => self::SCHEMA_VERSION,
                'values' => [
                    'finished_width_cm' => $width,
                    'finished_height_cm' => $height,
                ],
                'calculated' => [
                    'area_m2' => $area,
                    'formula' => 'finished_width_cm * finished_height_cm / 10000',
                    'scale' => 4,
                ],
                'billing_quantity' => [
                    'value' => $quantity,
                    'source' => $usesCalculatedArea ? 'DIMENSIONS' : 'MANUAL',
                    'unit_code' => $billingUnit->getCode(),
                    'unit_name' => $billingUnit->getName(),
                ],
                'summary' => sprintf(
                    'Medida terminada: %s × %s cm; área calculada: %s m²; cantidad facturable: %s %s.',
                    $width,
                    $height,
                    $area,
                    $quantity,
                    $billingUnitLabel,
                ),
            ],
        ];
    }

    private function normalizeDimension(mixed $value, string $field): string
    {
        $value = trim(str_replace(',', '.', (string) $value));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s debe ser un número con máximo cuatro decimales.', $field));
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $normalized = $integer.'.'.str_pad($decimal, 4, '0');

        if ($normalized === '0.0000') {
            throw new \InvalidArgumentException(sprintf('%s debe ser mayor que cero.', $field));
        }

        return $normalized;
    }
}
