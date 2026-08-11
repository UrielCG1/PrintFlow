<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use Symfony\Component\Validator\Constraints as Assert;

final class QuotationCancellationData
{
    #[Assert\NotBlank(message: 'Indica el motivo de cancelación.')]
    #[Assert\Length(max: 2000)]
    public ?string $reason = null;
}
