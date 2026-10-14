<?php

declare(strict_types=1);

namespace App\Application\Orders;

/** @param list<array{commitment_id: int, variant_code: string, released_quantity: string}> $releasedCommitments */
final readonly class ServiceOrderCancellationResult
{
    public function __construct(
        public int $cancelledExecutions,
        public int $releasedReservations,
        public array $releasedCommitments,
    ) {
    }
}
