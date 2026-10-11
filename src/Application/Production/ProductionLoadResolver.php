<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Enum\Equipment\ProductionLoadUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class ProductionLoadResolver
{
    public function automaticLoad(ServiceOrderItem $item, ProductionLoadUnit $unit): ?string
    {
        return $unit === ProductionLoadUnit::SQUARE_METERS
            ? $this->printableAreaSquareMeters($item)
            : null;
    }

    public function printableAreaSquareMeters(ServiceOrderItem $item): ?string
    {
        $snapshot = $item->getProductionSpecificationsSnapshot();
        $width = $this->dimension($snapshot, 'finished_width_cm');
        $height = $this->dimension($snapshot, 'finished_height_cm');
        if ($width === null || $height === null) {
            return null;
        }

        return BigDecimal::of($width)
            ->multipliedBy($height)
            ->multipliedBy($item->getQuantity())
            ->dividedBy('10000', 4, RoundingMode::HalfUp)
            ->__toString();
    }

    /** @param array<string, mixed> $snapshot */
    private function dimension(array $snapshot, string $key): ?string
    {
        $candidate = $snapshot['large_format']['values'][$key]
            ?? $snapshot['values'][strtoupper($key)]['submitted_value']
            ?? $snapshot[$key]
            ?? null;

        return is_scalar($candidate) && is_numeric($candidate) && (float) $candidate > 0
            ? (string) $candidate
            : null;
    }
}
