<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Equipment\Equipment;
use App\Enum\Production\ProductionCalendarExceptionType;

final class ProductionCalendarExceptionData
{
    public ?ProductionCalendarExceptionType $type = null;
    public ?Equipment $equipment = null;
    public ?\DateTimeImmutable $startsAt = null;
    public ?\DateTimeImmutable $endsAt = null;
    public string $reason = '';
}
