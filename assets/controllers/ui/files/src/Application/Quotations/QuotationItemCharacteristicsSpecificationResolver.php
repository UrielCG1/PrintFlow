<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\ItemPriceRule;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Valida y congela las características configuradas para un Producto.
 *
 * La configuración del catálogo es la fuente de verdad: el navegador solo
 * presenta los campos y nunca decide qué valores se aceptan.
 */
final class QuotationItemCharacteristicsSpecificationResolver
{
    public const PROFILE = 'COMMERCIAL_CHARACTERISTICS';
    public const SCHEMA_VERSION = 2;

    public static function fieldKey(CommercialCharacteristic $characteristic): string
    {
        return 'characteristic_'.strtolower($characteristic->getCode());
    }

    /**
     * @param list<CommercialItemCharacteristic> $configurations
     * @param array<string, mixed> $submittedSpecifications
     * @return array{quantity: string, schema_version: int, snapshot: array<string, mixed>}
     */
    public function resolve(
        CommercialItem $commercialItem,
        array $configurations,
        array $submittedSpecifications,
        string $submittedQuantity,
        string $submittedQuantityMode,
    ): array {
        $values = [];
        $summaryValues = [];

        foreach ($configurations as $configuration) {
            $characteristic = $configuration->getCharacteristic();
            $fieldKey = self::fieldKey($characteristic);
            $submittedValue = trim((string) (
                $submittedSpecifications[$fieldKey]
                ?? $this->legacyDimensionValue($characteristic, $submittedSpecifications)
                ?? ''
            ));

            if ($submittedValue === '') {
                if ($configuration->isRequired()) {
                    throw new \DomainException(sprintf(
                        'Captura la característica obligatoria "%s".',
                        $characteristic->getName(),
                    ));
                }

                continue;
            }

            $value = $this->resolveValue($configuration, $submittedValue);
            $values[$characteristic->getCode()] = [
                'field_key' => $fieldKey,
                'code' => $characteristic->getCode(),
                'name' => $characteristic->getName(),
                'input_type' => $characteristic->getInputType()->value,
                'unit_label' => $characteristic->getUnitLabel(),
                ...$value,
            ];
            $summaryValues[] = sprintf(
                '%s: %s',
                $characteristic->getName(),
                $value['display_value'],
            );
        }

        $quantity = ItemPriceRule::normalizeMinimumQuantity($submittedQuantity);
        $billingUnit = $commercialItem->getMeasurementUnit();
        $billingQuantity = [
            'value' => $quantity,
            'source' => 'CAPTURED',
            'unit_code' => $billingUnit->getCode(),
            'unit_name' => $billingUnit->getName(),
        ];
        $largeFormat = null;

        if ($commercialItem->getQuotationSpecificationProfile() === QuotationItemSpecificationProfile::LARGE_FORMAT) {
            [$quantity, $billingQuantity, $largeFormat] = $this->resolveLargeFormatQuantity(
                $commercialItem,
                $submittedSpecifications,
                $values,
                $quantity,
                $submittedQuantityMode,
            );
        }

        $summary = $summaryValues === []
            ? 'Sin características capturadas.'
            : 'Características: '.implode('; ', $summaryValues).'.';
        if ($largeFormat !== null) {
            $summary .= sprintf(
                ' Medida terminada: %s × %s cm; área calculada: %s m²; cantidad facturable: %s %s.',
                $largeFormat['values']['finished_width_cm'],
                $largeFormat['values']['finished_height_cm'],
                $largeFormat['calculated']['area_m2'],
                $quantity,
                strtoupper($billingUnit->getCode()) === 'M2' ? 'm²' : $billingUnit->getName(),
            );
        }

        return [
            'quantity' => $quantity,
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot' => [
                'profile' => self::PROFILE,
                'schema_version' => self::SCHEMA_VERSION,
                'values' => $values,
                'billing_quantity' => $billingQuantity,
                'large_format' => $largeFormat,
                'summary' => $summary,
            ],
        ];
    }

    /**
     * @return array{submitted_value: string, display_value: string, option_id?: int, option_code?: string, option_name?: string}
     */
    private function resolveValue(
        CommercialItemCharacteristic $configuration,
        string $submittedValue,
    ): array {
        $characteristic = $configuration->getCharacteristic();

        return match ($characteristic->getInputType()) {
            CommercialCharacteristicInputType::SELECT => $this->resolveSelectValue(
                $configuration,
                $submittedValue,
            ),
            CommercialCharacteristicInputType::DECIMAL => $this->resolveDecimalValue(
                $submittedValue,
                $characteristic,
            ),
            CommercialCharacteristicInputType::TEXT => $this->resolveTextValue(
                $submittedValue,
            ),
            CommercialCharacteristicInputType::BOOLEAN => $this->resolveBooleanValue(
                $submittedValue,
            ),
        };
    }

    /** @return array{submitted_value: string, display_value: string, option_id: int, option_code: string, option_name: string} */
    private function resolveSelectValue(
        CommercialItemCharacteristic $configuration,
        string $submittedValue,
    ): array {
        foreach ($configuration->getAllowedOptions() as $allowedOption) {
            $option = $allowedOption->getCharacteristicOption();

            if ($option->getCode() !== $submittedValue) {
                continue;
            }

            return [
                'submitted_value' => $option->getCode(),
                'display_value' => $option->getName(),
                'option_id' => (int) $option->getId(),
                'option_code' => $option->getCode(),
                'option_name' => $option->getName(),
            ];
        }

        throw new \DomainException(sprintf(
            'El valor seleccionado para "%s" no está permitido para este Producto.',
            $configuration->getCharacteristic()->getName(),
        ));
    }

    /** @return array{submitted_value: string, display_value: string} */
    private function resolveDecimalValue(string $submittedValue, CommercialCharacteristic $characteristic): array
    {
        $value = trim(str_replace(',', '.', $submittedValue));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'La característica "%s" debe ser un número con máximo cuatro decimales.',
                $characteristic->getName(),
            ));
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = (ltrim($integer, '0') ?: '0').'.'.str_pad($decimal, 4, '0');

        if ($normalized === '0.0000') {
            throw new \InvalidArgumentException(sprintf(
                'La característica "%s" debe ser mayor que cero.',
                $characteristic->getName(),
            ));
        }

        return [
            'submitted_value' => $normalized,
            'display_value' => $normalized.($characteristic->getUnitLabel() === null ? '' : ' '.$characteristic->getUnitLabel()),
        ];
    }

    /** @return array{submitted_value: string, display_value: string} */
    private function resolveTextValue(string $submittedValue): array
    {
        if (mb_strlen($submittedValue) > 255) {
            throw new \InvalidArgumentException('Una característica de texto no puede exceder 255 caracteres.');
        }

        return [
            'submitted_value' => $submittedValue,
            'display_value' => $submittedValue,
        ];
    }

    /** @return array{submitted_value: string, display_value: string} */
    private function resolveBooleanValue(string $submittedValue): array
    {
        $value = strtolower($submittedValue);

        return match ($value) {
            '1', 'true', 'si', 'sí' => ['submitted_value' => '1', 'display_value' => 'Sí'],
            '0', 'false', 'no' => ['submitted_value' => '0', 'display_value' => 'No'],
            default => throw new \InvalidArgumentException('El valor Sí / No de una característica no es válido.'),
        };
    }

    /** @param array<string, mixed> $submittedSpecifications */
    private function legacyDimensionValue(
        CommercialCharacteristic $characteristic,
        array $submittedSpecifications,
    ): ?string {
        return match ($characteristic->getCode()) {
            'FINISHED_WIDTH_CM' => isset($submittedSpecifications['finished_width_cm'])
                ? (string) $submittedSpecifications['finished_width_cm']
                : null,
            'FINISHED_HEIGHT_CM' => isset($submittedSpecifications['finished_height_cm'])
                ? (string) $submittedSpecifications['finished_height_cm']
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $submittedSpecifications
     * @param array<string, array<string, mixed>> $values
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function resolveLargeFormatQuantity(
        CommercialItem $commercialItem,
        array $submittedSpecifications,
        array $values,
        string $submittedQuantity,
        string $submittedQuantityMode,
    ): array {
        $width = (string) ($values['FINISHED_WIDTH_CM']['submitted_value']
            ?? $submittedSpecifications['finished_width_cm']
            ?? '');
        $height = (string) ($values['FINISHED_HEIGHT_CM']['submitted_value']
            ?? $submittedSpecifications['finished_height_cm']
            ?? '');
        $width = $this->normalizeDimension($width, 'El ancho terminado');
        $height = $this->normalizeDimension($height, 'El alto terminado');

        $area = BigDecimal::of($width)
            ->multipliedBy($height)
            ->dividedBy('10000', 4, RoundingMode::HalfUp)
            ->__toString();
        $quantityMode = strtoupper(trim($submittedQuantityMode));
        if (!in_array($quantityMode, ['AUTO', 'MANUAL'], true)) {
            throw new \InvalidArgumentException('El origen de la cantidad de la partida no es válido.');
        }

        $isBilledBySquareMetre = strtoupper($commercialItem->getMeasurementUnit()->getCode()) === 'M2';
        $usesCalculatedArea = $isBilledBySquareMetre && $quantityMode === 'AUTO';
        if ($usesCalculatedArea && $area === '0.0000') {
            throw new \DomainException('La superficie calculada es menor a 0.0001 m². Captura medidas mayores.');
        }

        $quantity = $usesCalculatedArea ? $area : $submittedQuantity;
        $billingUnit = $commercialItem->getMeasurementUnit();

        return [
            $quantity,
            [
                'value' => $quantity,
                'source' => $usesCalculatedArea ? 'DIMENSIONS' : 'MANUAL',
                'unit_code' => $billingUnit->getCode(),
                'unit_name' => $billingUnit->getName(),
            ],
            [
                'values' => [
                    'finished_width_cm' => $width,
                    'finished_height_cm' => $height,
                ],
                'calculated' => [
                    'area_m2' => $area,
                    'formula' => 'finished_width_cm * finished_height_cm / 10000',
                    'scale' => 4,
                ],
            ],
        ];
    }

    private function normalizeDimension(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s debe ser un número con máximo cuatro decimales.', $field));
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = (ltrim($integer, '0') ?: '0').'.'.str_pad($decimal, 4, '0');

        if ($normalized === '0.0000') {
            throw new \InvalidArgumentException(sprintf('%s debe ser mayor que cero.', $field));
        }

        return $normalized;
    }
}
