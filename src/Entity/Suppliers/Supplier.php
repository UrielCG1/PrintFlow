<?php

namespace App\Entity\Suppliers;

use App\Repository\Suppliers\SupplierRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Table(name: 'suppliers')]
#[ORM\UniqueConstraint(name: 'uniq_suppliers_code', columns: ['code'])]
#[ORM\UniqueConstraint(name: 'uniq_suppliers_tax_id', columns: ['tax_id'])]
#[ORM\Index(name: 'idx_suppliers_active_name', columns: ['is_active', 'business_name'])]
#[ORM\Index(name: 'idx_suppliers_deleted_at', columns: ['deleted_at'])]
#[ORM\HasLifecycleCallbacks]
final class Supplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $code;

    #[ORM\Column(name: 'business_name', length: 160)]
    private string $businessName;

    #[ORM\Column(name: 'legal_name', length: 160, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(name: 'tax_id', length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(
        name: 'created_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(
        name: 'deleted_at',
        type: 'datetime_immutable',
        nullable: true,
        options: ['comment' => '(DC2Type:datetime_immutable)'],
    )]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function setBusinessName(string $businessName): self
    {
        $this->businessName = trim($businessName);

        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): self
    {
        $legalName = trim((string) $legalName);
        $this->legalName = $legalName !== '' ? $legalName : null;

        return $this;
    }

    public function getTaxId(): ?string
    {
        return $this->taxId;
    }

    public function setTaxId(?string $taxId): self
    {
        $taxId = trim((string) $taxId);
        $this->taxId = $taxId !== '' ? strtoupper($taxId) : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $email = trim((string) $email);
        $this->email = $email !== '' ? strtolower($email) : null;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $phone = trim((string) $phone);
        $this->phone = $phone !== '' ? $phone : null;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = trim((string) $notes);
        $this->notes = $notes !== '' ? $notes : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->deletedAt = $isActive
            ? null
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}