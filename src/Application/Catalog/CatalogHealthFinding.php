<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Enum\Catalog\CatalogHealthSeverity;

/**
 * Una observación consolidada por registro del catálogo. Un mismo registro puede
 * tener varias razones, pero el dashboard lo muestra una sola vez con la
 * severidad más alta encontrada.
 */
final readonly class CatalogHealthFinding
{
    /**
     * @param non-empty-list<string> $reasons
     */
    public function __construct(
        public string $area,
        public string $entityType,
        public int $entityId,
        public string $entityName,
        public string $entityCode,
        public CatalogHealthSeverity $severity,
        public array $reasons,
    ) {
    }
}
