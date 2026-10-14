<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/** Result of evaluating how urgently a physical unit should be counted. */
final readonly class InventoryCycleCountPriorityAssessment
{
    /** @param list<string> $reasons */
    public function __construct(
        public int $score,
        public string $level,
        public array $reasons,
    ) {
    }
}
