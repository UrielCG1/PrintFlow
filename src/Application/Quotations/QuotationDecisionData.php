<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Enum\Quotations\QuotationResponseChannel;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class QuotationDecisionData
{
    public bool $acceptanceFiles = false;
    #[Assert\NotNull(message: 'Selecciona el canal de respuesta.')]
    public ?QuotationResponseChannel $channel = null;

    #[Assert\NotBlank(message: 'Captura el nombre o medio de contacto que respondió.')]
    #[Assert\Length(max: 160)]
    public ?string $contact = null;

    #[Assert\NotNull(message: 'Captura la fecha y hora de la respuesta.')]
    public ?\DateTimeImmutable $respondedAt = null;

    #[Assert\Length(max: 5000)]
    public ?string $notes = null;

    #[Assert\Length(max: 500)]
    public ?string $evidenceReference = null;

    #[Assert\Length(max: 120)]
    public ?string $purchaseOrderNumber = null;

    #[Assert\File(maxSize: '20M', extensions: ['pdf'], extensionsMessage: 'La orden de compra debe ser un archivo PDF.')]
    public ?UploadedFile $purchaseOrderFile = null;

    #[Assert\File(maxSize: '10M', extensions: ['png', 'jpg', 'jpeg', 'webp'], extensionsMessage: 'La captura debe ser PNG, JPG, JPEG o WEBP.')]
    public ?UploadedFile $responseScreenshot = null;

    /** @var array<string, int|string>|null */
    public ?array $purchaseOrderMetadata = null;

    /** @var array<string, int|string>|null */
    public ?array $responseScreenshotMetadata = null;

    public function __construct()
    {
        $this->respondedAt = new \DateTimeImmutable(
            'now',
            new \DateTimeZone('America/Mexico_City'),
        );
    }

    #[Assert\Callback]
    public function validateEvidence(ExecutionContextInterface $context): void
    {
        if ($this->acceptanceFiles && $this->purchaseOrderFile !== null && trim((string) $this->purchaseOrderNumber) === '') {
            $context->buildViolation('Captura el número de orden de compra para adjuntar el PDF.')->atPath('purchaseOrderNumber')->addViolation();
        }

        if ($this->acceptanceFiles && $this->channel === QuotationResponseChannel::WHATSAPP && $this->responseScreenshot === null) {
            $context->buildViolation('Adjunta la captura de pantalla de la respuesta por WhatsApp.')->atPath('responseScreenshot')->addViolation();
        }

        if (trim((string) $this->notes) !== '' || trim((string) $this->evidenceReference) !== '' || $this->purchaseOrderFile !== null || $this->responseScreenshot !== null) {
            return;
        }

        $context
            ->buildViolation('Registra una observación o una referencia de evidencia de la respuesta.')
            ->atPath('notes')
            ->addViolation();
    }
}
