<?php

declare(strict_types=1);

namespace App\Entity\Discounts;

use App\Entity\Quotations\Quotation;
use App\Entity\Users\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_volume_discount_reviews')]
class QuotationVolumeDiscountReview
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Quotation::class)] #[ORM\JoinColumn(name: 'quotation_id', nullable: false, onDelete: 'CASCADE')] private Quotation $quotation;
    #[ORM\ManyToOne(targetEntity: User::class)] #[ORM\JoinColumn(name: 'requested_by_user_id', nullable: true, onDelete: 'SET NULL')] private ?User $requestedBy = null;
    #[ORM\ManyToOne(targetEntity: User::class)] #[ORM\JoinColumn(name: 'reviewed_by_user_id', nullable: true, onDelete: 'SET NULL')] private ?User $reviewedBy = null;
    #[ORM\Column(name: 'volume_calculation_version', options: ['unsigned' => true])] private int $volumeCalculationVersion;
    #[ORM\Column(name: 'review_attempt', options: ['unsigned' => true, 'default' => 1])] private int $reviewAttempt = 1;
    #[ORM\Column(name: 'volume_hash', length: 64)] private string $volumeHash;
    #[ORM\Column(length: 20)] private string $status = 'PENDING';
    #[ORM\Column(length: 20)] private string $origin = 'SYSTEM';
    #[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $notes = null;
    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $requestedAt;
    #[ORM\Column(name: 'reviewed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)] private ?\DateTimeImmutable $reviewedAt = null;
    public function __construct(Quotation $quotation, int $version, string $hash) { $this->quotation = $quotation; $this->volumeCalculationVersion = $version; $this->volumeHash = $hash; $this->requestedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); }
    public function getId(): ?int { return $this->id; }
    public function getQuotation(): Quotation { return $this->quotation; }
    public function getVolumeCalculationVersion(): int { return $this->volumeCalculationVersion; }
    public function getReviewAttempt(): int { return $this->reviewAttempt; }
    public function setReviewAttempt(int $v): self { $this->reviewAttempt = max(1, $v); return $this; }
    public function getVolumeHash(): string { return $this->volumeHash; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = strtoupper(trim($v)); $this->reviewedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')); return $this; }
    public function getOrigin(): string { return $this->origin; }
    public function setOrigin(string $v): self { $this->origin = strtoupper(trim($v)); return $this; }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(?User $v): self { $this->requestedBy = $v; return $this; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $v): self { $this->reviewedBy = $v; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = trim((string) $v) ?: null; return $this; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
}
