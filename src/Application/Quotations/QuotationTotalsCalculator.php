<?php

namespace App\Application\Quotations;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class QuotationTotalsCalculator
{
    public function lineSubtotal(string $quantity, string $unitPrice): string
    {
        return BigDecimal::of($quantity)
            ->multipliedBy($unitPrice)
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();
    }

    /**
     * @param list<string> $lineSubtotals
     */
    public function calculate(
        array $lineSubtotals,
        string $discountPercent,
        string $taxRate,
    ): QuotationTotals {
        $subtotal = BigDecimal::zero();

        foreach ($lineSubtotals as $lineSubtotal) {
            $subtotal = $subtotal->plus($lineSubtotal);
        }

        $subtotal = $subtotal->toScale(2, RoundingMode::HalfUp);

        $discountAmount = $subtotal
            ->multipliedBy($discountPercent)
            ->dividedBy('100', 2, RoundingMode::HalfUp);

        $taxableAmount = $subtotal
            ->minus($discountAmount)
            ->toScale(2, RoundingMode::HalfUp);

        $taxAmount = $taxableAmount
            ->multipliedBy($taxRate)
            ->toScale(2, RoundingMode::HalfUp);

        $total = $taxableAmount
            ->plus($taxAmount)
            ->toScale(2, RoundingMode::HalfUp);

        return new QuotationTotals(
            subtotal: $subtotal->__toString(),
            discountAmount: $discountAmount->__toString(),
            taxableAmount: $taxableAmount->__toString(),
            taxAmount: $taxAmount->__toString(),
            total: $total->__toString(),
        );
    }
}