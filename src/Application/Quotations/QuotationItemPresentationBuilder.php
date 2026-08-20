<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Entity\Quotations\QuotationItem;

/**
 * Construye una representación de lectura de una partida congelada.
 *
 * No consulta catálogos ni recalcula importes. Toda la información comercial
 * procede de los snapshots y valores persistidos en QuotationItem.
 */
final class QuotationItemPresentationBuilder
{
    /**
     * @param iterable<QuotationItem> $items
     * @return list<array<string, mixed>>
     */
    public function presentAll(iterable $items): array
    {
        $presented = [];

        foreach ($items as $item) {
            $presented[] = $this->present($item);
        }

        return $presented;
    }

    /** @return array<string, mixed> */
    public function present(QuotationItem $item): array
    {
        $commercialItem = $item->getCommercialItemSnapshot();
        $specifications = $item->getSpecificationsSnapshot() ?? [];
        $unit = is_array($commercialItem['measurement_unit'] ?? null)
            ? $commercialItem['measurement_unit']
            : [];

        $unitCode = trim((string) ($unit['code'] ?? ''));
        $unitName = trim((string) ($unit['name'] ?? ''));
        $unitLabel = $this->unitLabel($unitCode, $unitName);

        $name = trim((string) ($commercialItem['name'] ?? ''));
        if ($name === '') {
            $name = $item->getCommercialItem()->getName();
        }

        return [
            'line_number' => $item->getLineNumber(),
            'product' => [
                'code' => $this->nullableString($commercialItem['code'] ?? null),
                'name' => $name,
                'description' => $this->nullableString($commercialItem['description'] ?? null),
            ],
            'unit' => [
                'code' => $unitCode,
                'name' => $unitName,
                'label' => $unitLabel,
            ],
            'quantity' => $item->getQuantity(),
            'quantity_display' => sprintf(
                '%s%s',
                $this->formatFixedDecimal($item->getQuantity(), 4),
                $unitLabel === '' ? '' : ' '.$unitLabel,
            ),
            'unit_price' => $item->getUnitPrice(),
            'line_subtotal' => $item->getLineSubtotal(),
            'specifications' => $this->specificationRows($specifications),
            'billing_source' => $this->nullableString(
                is_array($specifications['billing_quantity'] ?? null)
                    ? ($specifications['billing_quantity']['source'] ?? null)
                    : null,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return list<array{label: string, value: string}>
     */
    private function specificationRows(array $snapshot): array
    {
        if ($snapshot === []) {
            return [];
        }

        $rows = [];
        $largeFormat = is_array($snapshot['large_format'] ?? null)
            ? $snapshot['large_format']
            : null;

        // Schema 2: las características pueden convivir con gran formato.
        if ($largeFormat !== null) {
            $this->appendLargeFormatRows($rows, $largeFormat);
        } elseif (($snapshot['profile'] ?? null) === 'LARGE_FORMAT') {
            // Compatibilidad con snapshots schema 1 de gran formato.
            $this->appendLargeFormatRows($rows, $snapshot);
        }

        $values = is_array($snapshot['values'] ?? null) ? $snapshot['values'] : [];
        foreach ($values as $code => $value) {
            if (in_array((string) $code, ['FINISHED_WIDTH_CM', 'FINISHED_HEIGHT_CM'], true)) {
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $name = trim((string) ($value['name'] ?? ''));
            $displayValue = trim((string) ($value['display_value'] ?? ''));
            if ($name === '' || $displayValue === '') {
                continue;
            }

            $rows[] = [
                'label' => $name,
                'value' => $displayValue,
            ];
        }

        // Fallback exclusivamente para snapshots históricos sin estructura
        // conocida. Nunca sustituye la estructura de schema 1/2.
        if ($rows === []) {
            $summary = trim((string) ($snapshot['summary'] ?? ''));
            if ($summary !== '' && $summary !== 'Sin características capturadas.') {
                $rows[] = [
                    'label' => 'Especificaciones',
                    'value' => $summary,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     * @param array<string, mixed> $largeFormat
     */
    private function appendLargeFormatRows(array &$rows, array $largeFormat): void
    {
        $values = is_array($largeFormat['values'] ?? null) ? $largeFormat['values'] : [];
        $width = $this->nullableString($values['finished_width_cm'] ?? null);
        $height = $this->nullableString($values['finished_height_cm'] ?? null);

        if ($width !== null && $height !== null) {
            $rows[] = [
                'label' => 'Medida terminada',
                'value' => sprintf(
                    '%s × %s cm',
                    $this->formatCompactDecimal($width),
                    $this->formatCompactDecimal($height),
                ),
            ];
        }

        $calculated = is_array($largeFormat['calculated'] ?? null)
            ? $largeFormat['calculated']
            : [];
        $area = $this->nullableString($calculated['area_m2'] ?? null);

        if ($area !== null) {
            $rows[] = [
                'label' => 'Área',
                'value' => $this->formatFixedDecimal($area, 4).' m²',
            ];
        }
    }

    private function unitLabel(string $code, string $name): string
    {
        return match (strtoupper($code)) {
            'M2' => 'm²',
            'PZA' => 'pza',
            'PAQ' => 'paq',
            default => $name !== '' ? $name : $code,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function formatCompactDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1) {
            return $value;
        }

        if (!str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    private function formatFixedDecimal(string $value, int $scale): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?<sign>-?)(?<integer>\d+)(?:\.(?<decimal>\d+))?$/D', $value, $matches) !== 1) {
            return $value;
        }

        $integer = ltrim($matches['integer'], '0');
        if ($integer === '') {
            $integer = '0';
        }

        $grouped = number_format((int) $integer, 0, '.', ',');
        $decimal = substr(str_pad($matches['decimal'] ?? '', $scale, '0'), 0, $scale);

        return ($matches['sign'] ?? '').$grouped.($scale > 0 ? '.'.$decimal : '');
    }
}
