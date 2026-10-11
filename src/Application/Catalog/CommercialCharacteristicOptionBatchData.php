<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class CommercialCharacteristicOptionBatchData
{
    /** @var list<CommercialCharacteristicOptionData> */
    #[Assert\Count(min: 1, minMessage: 'Agrega al menos una opción.')]
    #[Assert\Valid]
    public array $options = [];

    #[Assert\Callback]
    public function validateDistinctOptions(ExecutionContextInterface $context): void
    {
        $codes = [];
        $names = [];

        foreach ($this->options as $index => $option) {
            $code = strtoupper(trim((string) $option->code));
            $name = mb_strtolower(trim((string) $option->name));

            if ($code !== '') {
                if (isset($codes[$code])) {
                    $context->buildViolation('Este código ya aparece en otra opción del formulario.')
                        ->atPath(sprintf('options[%d].code', $index))
                        ->addViolation();
                }
                $codes[$code] = true;
            }

            if ($name !== '') {
                if (isset($names[$name])) {
                    $context->buildViolation('Este nombre ya aparece en otra opción del formulario.')
                        ->atPath(sprintf('options[%d].name', $index))
                        ->addViolation();
                }
                $names[$name] = true;
            }
        }
    }
}
