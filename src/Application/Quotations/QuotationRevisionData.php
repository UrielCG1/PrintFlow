<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use Symfony\Component\Validator\Constraints as Assert;

final class QuotationRevisionData
{
    #[Assert\NotBlank(message: 'Indica por qué se requiere una nueva revisión.')]
    #[Assert\Length(max: 2000)]
    public ?string $reason = null;
}
