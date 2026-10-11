<?php

declare(strict_types=1);

namespace App\Enum\Production;

enum ProductionFileSource: string
{
    case PUBLIC_REQUEST = 'PUBLIC_REQUEST';
    case LEGACY_MIGRATION = 'LEGACY_MIGRATION';
    case DESIGN_UPLOAD = 'DESIGN_UPLOAD';
}
