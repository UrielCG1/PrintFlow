<?php

declare(strict_types=1);

namespace App\Enum\Orders;

enum ProductionEstimateKind: string
{
    case CALCULATED = 'calculated';
    case MANUAL = 'manual';
    case STANDARD = 'standard';
}
