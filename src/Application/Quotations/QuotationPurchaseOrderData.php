<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class QuotationPurchaseOrderData
{
    #[Assert\Length(max: 120)]
    public ?string $purchaseOrderNumber = null;

    #[Assert\File(maxSize: '20M', extensions: ['pdf'], extensionsMessage: 'La orden de compra debe ser un archivo PDF.')]
    public ?UploadedFile $purchaseOrderFile = null;

    public function __construct(public bool $hasExistingFile = false)
    {
    }

    #[Assert\Callback]
    public function validatePurchaseOrder(ExecutionContextInterface $context): void
    {
        if (($this->purchaseOrderFile !== null || $this->hasExistingFile)
            && trim((string) $this->purchaseOrderNumber) === '') {
            $context
                ->buildViolation('Captura el folio de orden para conservar o adjuntar el PDF.')
                ->atPath('purchaseOrderNumber')
                ->addViolation();
        }
    }
}
