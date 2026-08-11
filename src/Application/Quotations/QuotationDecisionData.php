<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Enum\Quotations\QuotationResponseChannel;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class QuotationDecisionData
{
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
        if (trim((string) $this->notes) !== '' || trim((string) $this->evidenceReference) !== '') {
            return;
        }

        $context
            ->buildViolation('Registra una observación o una referencia de evidencia de la respuesta.')
            ->atPath('notes')
            ->addViolation();
    }
}
