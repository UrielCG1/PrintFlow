<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Production\ProductionPrintingScan;

final readonly class PrintingScanResult
{
    public function __construct(
        public bool $accepted,
        public string $message,
        public ?ProductionPrintingScan $scan = null,
    ) {
    }
}
