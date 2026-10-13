<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** @param list<array{commitment_id: int, variant_code: string, released_quantity: string}> $releases */
final readonly class InventoryCommitmentReconciliationResult
{
    public function __construct(public array $releases)
    {
    }

    public function hasReleases(): bool
    {
        return $this->releases !== [];
    }
}
