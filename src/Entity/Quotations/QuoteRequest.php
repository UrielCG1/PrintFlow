<?php

namespace App\Entity\Quotations;

use App\Entity\Clients\ClientAddress;
use App\Entity\Clients\ClientBranch;
use App\Entity\Clients\ClientContact;
use App\Repository\Quotations\QuoteRequestRepository;
use App\Entity\Users\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'client_contact_id', nullable: true, onDelete: 'SET NULL')]
    private ?ClientContact $clientContact = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'client_branch_id', nullable: true, onDelete: 'SET NULL')]
    private ?ClientBranch $clientBranch = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'delivery_address_id', nullable: true, onDelete: 'SET NULL')]
    private ?ClientAddress $deliveryAddress = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $customerSnapshot = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $deliveryAddressSnapshot = null;

    /** @var Collection<int, QuoteRequestItem> */
    #[ORM\OneToMany(mappedBy: 'quoteRequest', targetEntity: QuoteRequestItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column(length: 30)]
    private ?string $folio = null;

    #[ORM\Column(length: 64)]
    private ?string $publicToken = null;

    #[ORM\Column(name: 'accepted_at', nullable: true)] private ?\DateTimeImmutable $acceptedAt = null;
    #[ORM\Column(name: 'accepted_by_name', length: 160, nullable: true)] private ?string $acceptedByName = null;
    #[ORM\Column(name: 'acceptance_notes', type: Types::TEXT, nullable: true)] private ?string $acceptanceNotes = null;
    #[ORM\Column(name: 'acceptance_ip', length: 45, nullable: true)] private ?string $acceptanceIp = null;
    #[ORM\Column(name: 'accepted_folio_snapshot', length: 30, nullable: true)] private ?string $acceptedFolioSnapshot = null;
    #[ORM\Column(name: 'accepted_amount_snapshot', type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)] private ?string $acceptedAmountSnapshot = null;
    #[ORM\ManyToOne, ORM\JoinColumn(name: 'acceptance_reviewed_by_user_id', nullable: true, onDelete: 'SET NULL')] private ?User $acceptanceReviewedBy = null;
    #[ORM\Column(name: 'acceptance_reviewed_at', nullable: true)] private ?\DateTimeImmutable $acceptanceReviewedAt = null;

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
        $this->items = new ArrayCollection();
        $this->requiresInvoice = false;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getClientContact(): ?ClientContact { return $this->clientContact; }
    public function setClientContact(?ClientContact $value): static { $this->clientContact=$value; return $this; }
    public function getClientBranch(): ?ClientBranch { return $this->clientBranch; }
    public function setClientBranch(?ClientBranch $value): static { $this->clientBranch=$value; return $this; }
    public function getDeliveryAddress(): ?ClientAddress { return $this->deliveryAddress; }
    public function setDeliveryAddress(?ClientAddress $value): static { $this->deliveryAddress=$value; return $this; }
    public function getCustomerSnapshot(): ?array { return $this->customerSnapshot; }
    public function setCustomerSnapshot(?array $value): static { $this->customerSnapshot=$value; return $this; }
    public function getDeliveryAddressSnapshot(): ?array { return $this->deliveryAddressSnapshot; }
    public function setDeliveryAddressSnapshot(?array $value): static { $this->deliveryAddressSnapshot=$value; return $this; }
    /** @return Collection<int, QuoteRequestItem> */ public function getItems(): Collection { return $this->items; }
    public function addItem(QuoteRequestItem $item): static { if(!$this->items->contains($item)){$this->items->add($item);$item->setQuoteRequest($this);} return $this; }
    public function removeItem(QuoteRequestItem $item): static { if($this->items->removeElement($item)){$item->setQuoteRequest(null);} return $this; }

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

    public function acceptFromPublicLink(string $name, ?string $notes, string $ip, string $amount): static
    {
        $notes = trim((string) $notes);
        $this->status = $notes === '' ? 'accepted' : 'accepted_with_changes';
        $this->acceptedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->acceptedByName = trim($name);
        $this->acceptanceNotes = $notes !== '' ? $notes : null;
        $this->acceptanceIp = $ip;
        $this->acceptedFolioSnapshot = $this->folio;
        $this->acceptedAmountSnapshot = $amount;
        return $this;
    }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function getAcceptedByName(): ?string { return $this->acceptedByName; }
    public function getAcceptanceNotes(): ?string { return $this->acceptanceNotes; }
    public function getAcceptanceReviewedBy(): ?User { return $this->acceptanceReviewedBy; }
    public function getAcceptanceReviewedAt(): ?\DateTimeImmutable { return $this->acceptanceReviewedAt; }
    public function markAcceptanceReviewedBy(User $user): static { $this->acceptanceReviewedBy=$user; $this->acceptanceReviewedAt=new \DateTimeImmutable('now', new \DateTimeZone('UTC')); return $this; }

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
