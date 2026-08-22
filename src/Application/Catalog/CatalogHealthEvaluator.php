<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\CommercialItemCharacteristic;
use App\Entity\Catalog\MeasurementUnit;
use App\Enum\Catalog\CatalogHealthSeverity;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use App\Enum\Catalog\CommercialItemType;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Quotations\QuotationItemSpecificationProfile;

/**
 * Reglas puras del diagnóstico. No persiste ni corrige datos: únicamente
 * describe estados que merecen atención del administrador.
 */
final class CatalogHealthEvaluator
{
    public function __construct(
        private readonly CommercialCharacteristicTechnicalContract $technicalContract,
    ) {
    }

    /**
     * @param list<CommercialItemCharacteristic> $configurations
     */
    public function evaluateItem(CommercialItem $item, array $configurations): ?CatalogHealthFinding
    {
        if (!$item->isActive()) {
            return null;
        }

        $reasons = [];
        $severity = null;

        if (!$item->getCategory()->isActive()) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                'La categoría comercial asociada está inactiva, aunque este registro sigue activo.',
            );
        }

        if (!$item->getMeasurementUnit()->isActive()) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                'La unidad de medida asociada está inactiva, aunque este registro sigue activo.',
            );
        }

        if ((float) $item->getBasePrice() <= 0.0) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::ATTENTION,
                'El precio base es MXN $0.00. Revisa que sea intencional antes de utilizarlo en nuevas cotizaciones.',
            );
        }

        if ($item->getType() === CommercialItemType::SERVICE && $configurations !== []) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                'El Servicio conserva características configuradas. Las características comerciales sólo deben asociarse a Productos.',
            );
        }

        if ($item->getType() === CommercialItemType::PRODUCT) {
            if ($configurations === []) {
                $this->addReason(
                    $reasons,
                    $severity,
                    CatalogHealthSeverity::ATTENTION,
                    'El Producto no tiene características configuradas. Puede ser válido para trabajos simples, pero conviene confirmar que no requiera datos adicionales al cotizar.',
                );
            } else {
                $configuredCodes = [];

                foreach ($configurations as $configuration) {
                    $characteristic = $configuration->getCharacteristic();
                    $configuredCodes[$characteristic->getCode()] = true;

                    if (!$characteristic->isActive()) {
                        $this->addReason(
                            $reasons,
                            $severity,
                            CatalogHealthSeverity::INCOMPLETE,
                            sprintf('Utiliza la característica inactiva "%s".', $characteristic->getName()),
                        );
                    }

                    if ($characteristic->getInputType() === CommercialCharacteristicInputType::SELECT) {
                        $activeAllowedOptions = 0;
                        foreach ($configuration->getAllowedOptions() as $allowedOption) {
                            if ($allowedOption->getCharacteristicOption()->isActive()) {
                                ++$activeAllowedOptions;
                            }
                        }

                        if ($activeAllowedOptions === 0) {
                            $this->addReason(
                                $reasons,
                                $severity,
                                CatalogHealthSeverity::INCOMPLETE,
                                sprintf(
                                    'La característica "%s" es una lista, pero el Producto no tiene opciones activas permitidas.',
                                    $characteristic->getName(),
                                ),
                            );
                        }
                    }
                }

                if ($item->getQuotationSpecificationProfile() === QuotationItemSpecificationProfile::LARGE_FORMAT) {
                    $missing = [];
                    if (!isset($configuredCodes['FINISHED_WIDTH_CM'])) {
                        $missing[] = 'Ancho terminado';
                    }
                    if (!isset($configuredCodes['FINISHED_HEIGHT_CM'])) {
                        $missing[] = 'Alto terminado';
                    }

                    if ($missing !== []) {
                        $this->addReason(
                            $reasons,
                            $severity,
                            CatalogHealthSeverity::ATTENTION,
                            sprintf(
                                'El perfil Gran formato está activo, pero su configuración de características no incluye %s. El perfil aún captura las medidas, aunque conviene alinear el catálogo.',
                                implode(' y ', $missing),
                            ),
                        );
                    }
                }
            }
        }

        return $this->finding(
            area: 'items',
            entityType: 'commercial_item',
            entityId: (int) $item->getId(),
            entityName: $item->getName(),
            entityCode: $item->getCode(),
            severity: $severity,
            reasons: $reasons,
        );
    }

    /** @param array{total: int, active: int} $usage */
    public function evaluateCategory(CommercialCategory $category, array $usage): ?CatalogHealthFinding
    {
        $reasons = [];
        $severity = null;

        if (!$category->isActive() && $usage['active'] > 0) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                sprintf('La categoría está inactiva, pero todavía contiene %d registros comerciales activos.', $usage['active']),
            );
        } elseif ($category->isActive() && $usage['active'] === 0) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::UNUSED,
                'La categoría está activa, pero ningún Producto o Servicio activo la utiliza actualmente.',
            );
        }

        return $this->finding(
            area: 'categories',
            entityType: 'commercial_category',
            entityId: (int) $category->getId(),
            entityName: $category->getName(),
            entityCode: $category->getCode(),
            severity: $severity,
            reasons: $reasons,
        );
    }

    /**
     * @param array{total: int, active: int} $commercialUsage
     * @param array{total: int, active: int} $materialUsage
     */
    public function evaluateUnit(
        MeasurementUnit $unit,
        array $commercialUsage,
        array $materialUsage,
        int $activeDerivedCount,
    ): ?CatalogHealthFinding {
        $reasons = [];
        $severity = null;
        $technicalProblems = [];

        $dimension = MeasurementDimensionType::tryFrom($unit->getDimensionType());
        $baseUnit = $unit->getBaseUnit();
        $factor = $unit->getConversionFactorAsFloat();

        if ($dimension === null) {
            $technicalProblems[] = sprintf('la dimensión "%s" no es reconocida', $unit->getDimensionType());
        }

        if ($factor <= 0.0) {
            $technicalProblems[] = 'el factor de conversión debe ser mayor que cero';
        }

        if ($baseUnit === null && abs($factor - 1.0) > 0.000000000001) {
            $technicalProblems[] = 'una unidad sin base debe conservar un factor de conversión igual a 1';
        }

        if ($baseUnit !== null) {
            if ($baseUnit === $unit || ($baseUnit->getId() !== null && $baseUnit->getId() === $unit->getId())) {
                $technicalProblems[] = 'la unidad se está utilizando a sí misma como unidad base';
            }

            if ($baseUnit->getDimensionType() !== $unit->getDimensionType()) {
                $technicalProblems[] = 'la unidad base pertenece a una dimensión diferente';
            }

            if ($baseUnit->getBaseUnit() !== null) {
                $technicalProblems[] = 'la conversión forma una cadena de más de un nivel';
            }

            if ($unit->isActive() && !$baseUnit->isActive()) {
                $technicalProblems[] = 'la unidad está activa, pero su unidad base está inactiva';
            }
        }

        if ($dimension === MeasurementDimensionType::COUNT && $baseUnit !== null) {
            $technicalProblems[] = 'una presentación de Conteo no debe declarar una conversión universal';
        }

        if (!$unit->allowsFraction() && $unit->getDecimalScale() !== 0) {
            $technicalProblems[] = 'una unidad que no permite fracciones debe tener precisión decimal 0';
        }

        if (strtoupper($unit->getCode()) === 'M2') {
            if (
                $dimension !== MeasurementDimensionType::AREA
                || $baseUnit !== null
                || abs($factor - 1.0) > 0.000000000001
            ) {
                $technicalProblems[] = 'M2 debe permanecer como unidad base de Área con factor 1 porque el cotizador la utiliza como contrato técnico';
            }
        }

        if ($technicalProblems !== []) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                'Configuración técnica inconsistente: '.implode('; ', $technicalProblems).'.',
            );
        }

        $activeUsage = $commercialUsage['active'] + $materialUsage['active'] + $activeDerivedCount;

        if (!$unit->isActive() && $activeUsage > 0) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                'La unidad está inactiva, pero todavía tiene dependencias activas en Productos/Servicios, Materiales o unidades derivadas.',
            );
        } elseif ($unit->isActive() && $activeUsage === 0 && $technicalProblems === []) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::UNUSED,
                'La unidad está activa, pero actualmente no la utilizan Productos/Servicios, Materiales ni otras unidades.',
            );
        }

        return $this->finding(
            area: 'units',
            entityType: 'measurement_unit',
            entityId: (int) $unit->getId(),
            entityName: $unit->getName(),
            entityCode: $unit->getCode(),
            severity: $severity,
            reasons: $reasons,
        );
    }

    /**
     * @param array{total: int, active: int} $options
     * @param array{total: int, active: int} $usage
     */
    public function evaluateCharacteristic(
        CommercialCharacteristic $characteristic,
        array $options,
        array $usage,
    ): ?CatalogHealthFinding {
        $reasons = [];
        $severity = null;

        if (!$characteristic->isActive() && $usage['active'] > 0) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                sprintf('La característica está inactiva, pero continúa configurada en %d Productos activos.', $usage['active']),
            );
        }

        if (
            $characteristic->isActive()
            && $characteristic->getInputType() === CommercialCharacteristicInputType::SELECT
            && $options['active'] === 0
        ) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::INCOMPLETE,
                'La característica utiliza una lista de opciones, pero no tiene ninguna opción activa disponible.',
            );
        }

        $contract = $this->technicalContract->forCharacteristic($characteristic);
        if ($contract !== null) {
            if (
                $characteristic->getInputType() !== $contract['inputType']
                || mb_strtolower((string) $characteristic->getUnitLabel()) !== mb_strtolower($contract['unitLabel'])
            ) {
                $this->addReason(
                    $reasons,
                    $severity,
                    CatalogHealthSeverity::INCOMPLETE,
                    sprintf(
                        'El contrato técnico %s no conserva el tipo de captura o la unidad esperada por el cotizador.',
                        $contract['code'],
                    ),
                );
            }
        }

        if ($characteristic->isActive() && $usage['active'] === 0 && $severity !== CatalogHealthSeverity::INCOMPLETE) {
            $this->addReason(
                $reasons,
                $severity,
                CatalogHealthSeverity::UNUSED,
                'La característica está activa, pero ningún Producto activo la utiliza actualmente.',
            );
        }

        return $this->finding(
            area: 'characteristics',
            entityType: 'commercial_characteristic',
            entityId: (int) $characteristic->getId(),
            entityName: $characteristic->getName(),
            entityCode: $characteristic->getCode(),
            severity: $severity,
            reasons: $reasons,
        );
    }

    /**
     * @param list<string> $reasons
     */
    private function addReason(
        array &$reasons,
        ?CatalogHealthSeverity &$currentSeverity,
        CatalogHealthSeverity $severity,
        string $reason,
    ): void {
        $reasons[] = $reason;

        if ($currentSeverity === null || $severity->priority() > $currentSeverity->priority()) {
            $currentSeverity = $severity;
        }
    }

    /**
     * @param list<string> $reasons
     */
    private function finding(
        string $area,
        string $entityType,
        int $entityId,
        string $entityName,
        string $entityCode,
        ?CatalogHealthSeverity $severity,
        array $reasons,
    ): ?CatalogHealthFinding {
        if ($severity === null || $reasons === []) {
            return null;
        }

        return new CatalogHealthFinding(
            area: $area,
            entityType: $entityType,
            entityId: $entityId,
            entityName: $entityName,
            entityCode: $entityCode,
            severity: $severity,
            reasons: $reasons,
        );
    }
}
