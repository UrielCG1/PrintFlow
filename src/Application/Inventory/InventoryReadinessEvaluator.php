<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/**
 * Evalúa, sin modificar datos, si las variantes activas ya tienen la
 * configuración mínima para entrar al inventario físico con QR.
 */
final class InventoryReadinessEvaluator
{
    /**
     * @param list<array<string, mixed>> $variants
     * @return array{
     *     summary: array{variants: int, ready: int, requires_review: int},
     *     findings: list<array{severity: string, material: string, variant: string, rule: string, action: string}>
     * }
     */
    public function evaluate(array $variants, int $movementCount, int $lotCount): array
    {
        $findings = [];
        $ready = 0;

        foreach ($variants as $variant) {
            $variantFindings = $this->evaluateVariant($variant);

            if ($variantFindings === []) {
                ++$ready;
            }

            array_push($findings, ...$variantFindings);
        }

        if ($movementCount === 0 && $lotCount === 0 && $variants !== []) {
            $findings[] = [
                'severity' => 'ADVERTENCIA',
                'material' => 'Catálogo completo',
                'variant' => '—',
                'rule' => 'CONTEO_INICIAL_PENDIENTE',
                'action' => 'No hay lotes ni movimientos registrados. Realiza y aprueba el conteo físico inicial por unidad antes de automatizar saldos, reservas o consumos.',
            ];
        }

        return [
            'summary' => [
                'variants' => count($variants),
                'ready' => $ready,
                'requires_review' => count($variants) - $ready,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string, mixed> $variant
     * @return list<array{severity: string, material: string, variant: string, rule: string, action: string}>
     */
    private function evaluateVariant(array $variant): array
    {
        $material = (string) ($variant['material_code'] ?? 'SIN-CODIGO');
        $variantCode = (string) ($variant['variant_code'] ?? 'SIN-VARIANTE');
        $findings = [];

        $add = static function (string $severity, string $rule, string $action) use (&$findings, $material, $variantCode): void {
            $findings[] = [
                'severity' => $severity,
                'material' => $material,
                'variant' => $variantCode,
                'rule' => $rule,
                'action' => $action,
            ];
        };

        if (trim($variantCode) === '' || trim((string) ($variant['inventory_unit_code'] ?? '')) === '' || trim((string) ($variant['consumption_unit_code'] ?? '')) === '') {
            $add(
                'BLOQUEO',
                'UNIDADES_INCOMPLETAS',
                'Configura código de variante y unidades de inventario y consumo antes de recibir material.',
            );

            return $findings;
        }

        $specifications = $this->decodeSpecifications($variant['specifications'] ?? []);
        if (($specifications['legacy_migration'] ?? false) === true) {
            $add(
                'ADVERTENCIA',
                'PRESENTACION_LEGADA_POR_REVISAR',
                'Confirma qué representa cada unidad física, sus dimensiones/contenido y conversiones antes del conteo inicial y de imprimir QR.',
            );
        }

        if ($this->isZero($variant['minimum_stock'] ?? null)
            && $this->isZero($variant['reorder_point'] ?? null)
            && $this->isZero($variant['reorder_quantity'] ?? null)) {
            $add(
                'ADVERTENCIA',
                'POLITICA_DE_MINIMOS_PENDIENTE',
                'El administrador debe confirmar mínimo, punto de reorden y cantidad sugerida, o marcar explícitamente que no aplican.',
            );
        }

        $inventoryDimension = (string) ($variant['inventory_unit_dimension'] ?? '');
        $consumptionDimension = (string) ($variant['consumption_unit_dimension'] ?? '');
        if ($inventoryDimension === 'COUNT' && !$this->toBoolean($variant['inventory_unit_allows_fraction'] ?? false)) {
            $add(
                'ADVERTENCIA',
                'PRESENTACION_CONTABLE_POR_CONFIRMAR',
                'Define si una unidad de inventario representa el objeto físico completo y, si tiene contenido medible, configura su presentación y conversión antes de registrar saldos parciales.',
            );
        }

        if ($inventoryDimension !== '' && $consumptionDimension !== '' && $inventoryDimension !== $consumptionDimension) {
            $add(
                'ADVERTENCIA',
                'CONVERSION_ENTRE_DIMENSIONES_POR_REVISAR',
                'Verifica el factor de presentación y el método de cálculo. Una conversión entre objeto, longitud, área, volumen o peso no debe asumirse como 1:1.',
            );
        }

        if ($this->toBoolean($variant['material_is_hazardous'] ?? false) && trim((string) ($variant['storage_conditions'] ?? '')) === '') {
            $add(
                'ADVERTENCIA',
                'ALMACENAMIENTO_DE_RIESGO_PENDIENTE',
                'Registra condiciones de almacenamiento y manejo para que estén disponibles en la etiqueta y en bodega.',
            );
        }

        return $findings;
    }

    /** @return array<string, mixed> */
    private function decodeSpecifications(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function isZero(mixed $value): bool
    {
        return $value === null || (float) $value === 0.0;
    }

    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
