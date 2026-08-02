<?php

namespace App\Entity\Quotations;

use App\Repository\Quotations\QuoteRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuoteRequestRepository::class)]
class QuoteRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(
        name: 'quotation_id',
        referencedColumnName: 'id',
        nullable: true,
        unique: true,
        onDelete: 'SET NULL',
    )]
    private ?Quotation $quotation = null;

    #[ORM\Column(length: 30)]
    private ?string $folio = null;

    #[ORM\Column(length: 64)]
    private ?string $publicToken = null;

    #[ORM\Column(length: 30)]
    private ?string $status = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customerNumber = null;

    #[ORM\Column(length: 150)]
    private ?string $fullName = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 30)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(length: 20)]
    private ?string $contactPreference = null;

    #[ORM\Column(length: 150)]
    private ?string $productType = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $width = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $height = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $measurementUnit = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $material = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $printSides = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $finishes = null;

    #[ORM\Column(length: 30)]
    private ?string $designStatus = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $neededAt = null;

    #[ORM\Column(length: 30)]
    private ?string $deliveryMethod = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column]
    private ?bool $requiresInvoice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;


    public function __construct()
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->publicToken = bin2hex(random_bytes(24));
        $this->status = 'new';
        $this->requiresInvoice = false;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFolio(): ?string
    {
        return $this->folio;
    }

    public function setFolio(string $folio): static
    {
        $this->folio = $folio;

        return $this;
    }

    public function getPublicToken(): ?string
    {
        return $this->publicToken;
    }

    public function setPublicToken(string $publicToken): static
    {
        $this->publicToken = $publicToken;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCustomerNumber(): ?string
    {
        return $this->customerNumber;
    }

    public function setCustomerNumber(?string $customerNumber): static
    {
        $this->customerNumber = $customerNumber;

        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getContactPreference(): ?string
    {
        return $this->contactPreference;
    }

    public function setContactPreference(string $contactPreference): static
    {
        $this->contactPreference = $contactPreference;

        return $this;
    }

    public function getProductType(): ?string
    {
        return $this->productType;
    }

    public function setProductType(string $productType): static
    {
        $this->productType = $productType;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getWidth(): ?string
    {
        return $this->width;
    }

    public function setWidth(?string $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?string
    {
        return $this->height;
    }

    public function setHeight(?string $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getMeasurementUnit(): ?string
    {
        return $this->measurementUnit;
    }

    public function setMeasurementUnit(?string $measurementUnit): static
    {
        $this->measurementUnit = $measurementUnit;

        return $this;
    }

    public function getMaterial(): ?string
    {
        return $this->material;
    }

    public function setMaterial(?string $material): static
    {
        $this->material = $material;

        return $this;
    }

    public function getPrintSides(): ?string
    {
        return $this->printSides;
    }

    public function setPrintSides(?string $printSides): static
    {
        $this->printSides = $printSides;

        return $this;
    }

    public function getFinishes(): ?array
    {
        return $this->finishes;
    }

    public function setFinishes(?array $finishes): static
    {
        $this->finishes = $finishes;

        return $this;
    }

    public function getDesignStatus(): ?string
    {
        return $this->designStatus;
    }

    public function setDesignStatus(string $designStatus): static
    {
        $this->designStatus = $designStatus;

        return $this;
    }

    public function getNeededAt(): ?\DateTimeImmutable
    {
        return $this->neededAt;
    }

    public function setNeededAt(?\DateTimeImmutable $neededAt): static
    {
        $this->neededAt = $neededAt;

        return $this;
    }

    public function getDeliveryMethod(): ?string
    {
        return $this->deliveryMethod;
    }

    public function setDeliveryMethod(string $deliveryMethod): static
    {
        $this->deliveryMethod = $deliveryMethod;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function isRequiresInvoice(): ?bool
    {
        return $this->requiresInvoice;
    }

    public function setRequiresInvoice(bool $requiresInvoice): static
    {
        $this->requiresInvoice = $requiresInvoice;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getQuotation(): ?Quotation
    {
        return $this->quotation;
    }

    public function setQuotation(?Quotation $quotation): static
    {
        $this->quotation = $quotation;

        return $this;
    }
}
