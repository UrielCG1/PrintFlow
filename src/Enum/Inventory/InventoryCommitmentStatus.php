<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

enum InventoryCommitmentStatus: string
{
    case COVERED = 'COVERED';
    case PARTIAL = 'PARTIAL';
    case SHORTAGE = 'SHORTAGE';
    case SUBSTITUTED = 'SUBSTITUTED';
}
