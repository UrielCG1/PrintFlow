<?php

namespace App\Application\Quotations;

final readonly class QuotationTotals
{
    public function __construct(
        public string $subtotal,
        public string $discountAmount,
        public string $taxableAmount,
        public string $taxAmount,
        public string $total,
    ) {
    }
}