<?php

declare(strict_types=1);

namespace App\Application\Production;

/** Convierte la captura técnica congelada en datos legibles para la ficha de Diseño. */
final class DesignSpecificationsPresenter
{
    /**
     * @param array<string, mixed> $snapshot
     * @return array{rows: list<array{label: string, value: string}>, billingSource: ?string}
     */
    public function present(array $snapshot): array
    {
        $rows = [];
        $largeFormat = is_array($snapshot['large_format'] ?? null) ? $snapshot['large_format'] : null;

        if ($largeFormat !== null) {
            $this->appendLargeFormatRows($rows, $largeFormat);
        } elseif (($snapshot['profile'] ?? null) === 'LARGE_FORMAT') {
            // Compatibilidad con capturas históricas que guardaban gran formato en la raíz.
            $this->appendLargeFormatRows($rows, $snapshot);
        }

        $values = is_array($snapshot['values'] ?? null) ? $snapshot['values'] : [];
        foreach ($values as $code => $value) {
            if (in_array(strtoupper((string) $code), ['FINISHED_WIDTH_CM', 'FINISHED_HEIGHT_CM'], true) || !is_array($value)) {
                continue;
            }

            $label = $this->readableString($value['name'] ?? null);
            $displayValue = $this->readableString($value['display_value'] ?? $value['submitted_value'] ?? null);
            if ($label !== null && $displayValue !== null) {
                $rows[] = ['label' => $label, 'value' => $displayValue];
            }
        }

        if ($rows === []) {
            $summary = $this->readableString($snapshot['summary'] ?? null);
            if ($summary !== null && $summary !== 'Sin características capturadas.') {
                $rows[] = ['label' => 'Resumen', 'value' => $summary];
            }
        }

        $billing = is_array($snapshot['billing_quantity'] ?? null) ? $snapshot['billing_quantity'] : [];
        $billingSource = match (strtoupper((string) ($billing['source'] ?? ''))) {
            'DIMENSIONS' => 'Calculada a partir de las medidas',
            'MANUAL' => 'Capturada manualmente',
            'CAPTURED' => 'Capturada en la cotización',
            default => null,
        };

        return ['rows' => $rows, 'billingSource' => $billingSource];
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     * @param array<string, mixed> $largeFormat
     */
    private function appendLargeFormatRows(array &$rows, array $largeFormat): void
    {
        $values = is_array($largeFormat['values'] ?? null) ? $largeFormat['values'] : [];
        $width = $this->readableString($values['finished_width_cm'] ?? $values['FINISHED_WIDTH_CM'] ?? null);
        $height = $this->readableString($values['finished_height_cm'] ?? $values['FINISHED_HEIGHT_CM'] ?? null);

        if ($width !== null && $height !== null) {
            $rows[] = ['label' => 'Medida terminada', 'value' => sprintf('%s × %s cm', $this->compactDecimal($width), $this->compactDecimal($height))];
        }

        $calculated = is_array($largeFormat['calculated'] ?? null) ? $largeFormat['calculated'] : [];
        $area = $this->readableString($calculated['area_m2'] ?? null);
        if ($area !== null) {
            $rows[] = ['label' => 'Área calculada', 'value' => $this->compactDecimal($area).' m²'];
        }
    }

    private function readableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function compactDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^-?\d+(?:\.\d+)?$/D', $value) !== 1 || !str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
