<?php

declare(strict_types=1);

namespace App\Entity\Quotations;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_status_history')]
#[ORM\Index(name: 'idx_quotation_status_history_timeline', columns: ['quotation_id', 'changed_at'])]
class QuotationStatusHistory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'quotation_id', nullable: false, onDelete: 'CASCADE')]
    private Quotation $quotation;

    #[ORM\Column(name: 'from_status', length: 30, nullable: true)]
    private ?string $fromStatus;

    #[ORM\Column(name: 'to_status', length: 30)]
    private string $toStatus;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(Quotation $quotation, ?string $fromStatus, string $toStatus)
    {
        $this->quotation = $quotation;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->changedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getQuotation(): Quotation { return $this->quotation; }
    public function getFromStatus(): ?string { return $this->fromStatus; }
    public function getToStatus(): string { return $this->toStatus; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
