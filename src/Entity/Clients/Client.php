<?php

namespace App\Entity\Clients;

use App\Repository\Clients\ClientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'clients')]
#[ORM\UniqueConstraint(name: 'uniq_clients_tax_id', columns: ['tax_id'])]
#[ORM\Index(name: 'idx_clients_active_name', columns: ['is_active', 'business_name'])]
#[ORM\Index(name: 'idx_clients_deleted_at', columns: ['deleted_at'])]
#[ORM\Index(name: 'idx_clients_category', columns: ['client_category_id'])]
#[ORM\HasLifecycleCallbacks]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'business_name', length: 160)]
    private string $businessName;

    #[ORM\Column(name: 'tax_id', length: 20, nullable: true)]
    private ?string $taxId = null;

    #[ORM\Column(name: 'legal_name', length: 160, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(name: 'tax_regime_code', length: 3, nullable: true)]
    private ?string $taxRegimeCode = null;

    #[ORM\Column(name: 'fiscal_postal_code', length: 5, nullable: true)]
    private ?string $fiscalPostalCode = null;

    #[ORM\Column(name: 'billing_email', length: 180, nullable: true)]
    private ?string $billingEmail = null;

    #[ORM\Column(name: 'default_cfdi_use_code', length: 10, nullable: true)]
    private ?string $defaultCfdiUseCode = null;

    #[ORM\ManyToOne(targetEntity: ClientCategory::class)]
    #[ORM\JoinColumn(name: 'client_category_id', nullable: true, onDelete: 'RESTRICT')]
    private ?ClientCategory $category = null;

    #[ORM\Column(name: 'default_discount_percent', type: 'float', options: ['default' => '0'])]
    private float $defaultDiscountPercent = 0.0;

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
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(
        name: 'updated_at',
        type: 'datetime_immutable',
        nullable: false,
        options: ['comment' => '(DC2Type:datetime_immutable)']
    )]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(
        name: 'deleted_at',
        type: 'datetime_immutable',
        nullable: true,
        options: ['comment' => '(DC2Type:datetime_immutable)']
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

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function setBusinessName(string $businessName): self
    {
        $this->businessName = trim($businessName);

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

    public function getTaxRegimeCode(): ?string
    {
        return $this->taxRegimeCode;
    }

    public function setTaxRegimeCode(?string $taxRegimeCode): self
    {
        $taxRegimeCode = trim((string) $taxRegimeCode);
        $this->taxRegimeCode = $taxRegimeCode !== '' ? $taxRegimeCode : null;

        return $this;
    }

    public function getFiscalPostalCode(): ?string
    {
        return $this->fiscalPostalCode;
    }

    public function setFiscalPostalCode(?string $fiscalPostalCode): self
    {
        $fiscalPostalCode = trim((string) $fiscalPostalCode);
        $this->fiscalPostalCode = $fiscalPostalCode !== '' ? $fiscalPostalCode : null;

        return $this;
    }

    public function getBillingEmail(): ?string
    {
        return $this->billingEmail;
    }

    public function setBillingEmail(?string $billingEmail): self
    {
        $billingEmail = trim((string) $billingEmail);
        $this->billingEmail = $billingEmail !== '' ? strtolower($billingEmail) : null;

        return $this;
    }

    public function getDefaultCfdiUseCode(): ?string
    {
        return $this->defaultCfdiUseCode;
    }

    public function setDefaultCfdiUseCode(?string $defaultCfdiUseCode): self
    {
        $defaultCfdiUseCode = trim((string) $defaultCfdiUseCode);
        $this->defaultCfdiUseCode = $defaultCfdiUseCode !== '' ? strtoupper($defaultCfdiUseCode) : null;

        return $this;
    }

    public function getCategory(): ?ClientCategory
    {
        return $this->category;
    }

    public function setCategory(?ClientCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getDefaultDiscountPercent(): float
    {
        return $this->defaultDiscountPercent;
    }

    public function setDefaultDiscountPercent(float $defaultDiscountPercent): self
    {
        $this->defaultDiscountPercent = $defaultDiscountPercent;

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
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}