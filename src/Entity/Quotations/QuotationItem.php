<?php

namespace App\Entity\Quotations;

use App\Entity\Catalog\CommercialItem;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_items')]
#[ORM\UniqueConstraint(name: 'uniq_quotation_items_line_number', columns: ['quotation_id', 'line_number'])]
#[ORM\Index(name: 'idx_quotation_items_commercial_item', columns: ['commercial_item_id'])]
#[ORM\HasLifecycleCallbacks]
class QuotationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'quotation_id', nullable: false, onDelete: 'RESTRICT')]
    private Quotation $quotation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\Column(name: 'line_number', options: ['unsigned' => true])]
    private int $lineNumber;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $quantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'line_subtotal', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $lineSubtotal;

    #[ORM\Column(name: 'commercial_item_snapshot', type: Types::JSON)]
    private array $commercialItemSnapshot = [];

    #[ORM\Column(name: 'price_rule_snapshot', type: Types::JSON, nullable: true)]
    private ?array $priceRuleSnapshot = null;

    #[ORM\Column(name: 'specifications_snapshot', type: Types::JSON)]
    private array $specificationsSnapshot = [];

    #[ORM\Column(name: 'specification_schema_version', options: ['unsigned' => true, 'default' => 1])]
    private int $specificationSchemaVersion = 1;

    #[ORM\Column(name: 'request_details', type: Types::JSON, nullable: true)]
    private ?array $requestDetails = null;

    #[ORM\Column(name: 'attachment_path', length: 255, nullable: true)]
    private ?string $attachmentPath = null;

    #[ORM\Column(name: 'attachment_original_name', length: 255, nullable: true)]
    private ?string $attachmentOriginalName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

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

    public function getRequestDetails(): ?array { return $this->requestDetails; }
    public function setRequestDetails(?array $value): self { $this->requestDetails=$value; return $this; }
    public function getAttachmentPath(): ?string { return $this->attachmentPath; }
    public function setAttachmentPath(?string $value): self { $this->attachmentPath=$value; return $this; }
    public function getAttachmentOriginalName(): ?string { return $this->attachmentOriginalName; }
    public function setAttachmentOriginalName(?string $value): self { $this->attachmentOriginalName=$value; return $this; }

    public function getQuotation(): Quotation
    {
        return $this->quotation;
    }

    public function setQuotation(Quotation $quotation): self
    {
        $this->quotation = $quotation;

        return $this;
    }

    public function getCommercialItem(): CommercialItem
    {
        return $this->commercialItem;
    }

    public function setCommercialItem(CommercialItem $commercialItem): self
    {
        $this->commercialItem = $commercialItem;

        return $this;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function setLineNumber(int $lineNumber): self
    {
        if ($lineNumber < 1) {
            throw new \InvalidArgumentException('El número de partida debe ser mayor que cero.');
        }

        $this->lineNumber = $lineNumber;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $value = trim(str_replace(',', '.', $quantity));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('La cantidad no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $normalized = $integer.'.'.str_pad($decimal, 4, '0');

        if ($normalized === '0.0000') {
            throw new \InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        $this->quantity = $normalized;

        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): self
    {
        $value = trim(str_replace(',', '.', $unitPrice));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('El precio unitario no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';

        $this->unitPrice = $integer.'.'.str_pad($decimal, 2, '0');

        return $this;
    }

    public function getLineSubtotal(): string
    {
        return $this->lineSubtotal;
    }

    public function setLineSubtotal(string $lineSubtotal): self
    {
        $this->lineSubtotal = Quotation::normalizeAmount(
            $lineSubtotal,
            'El subtotal de la partida',
        );

        return $this;
    }

    public function getCommercialItemSnapshot(): array
    {
        return $this->commercialItemSnapshot;
    }

    public function setCommercialItemSnapshot(array $commercialItemSnapshot): self
    {
        $this->commercialItemSnapshot = $commercialItemSnapshot;

        return $this;
    }

    public function getPriceRuleSnapshot(): ?array
    {
        return $this->priceRuleSnapshot;
    }

    public function setPriceRuleSnapshot(?array $priceRuleSnapshot): self
    {
        $this->priceRuleSnapshot = $priceRuleSnapshot;

        return $this;
    }

    public function getSpecificationsSnapshot(): array
    {
        return $this->specificationsSnapshot;
    }

    public function setSpecificationsSnapshot(array $specificationsSnapshot): self
    {
        $this->specificationsSnapshot = $specificationsSnapshot;

        return $this;
    }

    public function getSpecificationSchemaVersion(): int
    {
        return $this->specificationSchemaVersion;
    }

    public function setSpecificationSchemaVersion(int $specificationSchemaVersion): self
    {
        if ($specificationSchemaVersion < 1) {
            throw new \InvalidArgumentException('La versión del esquema técnico debe ser positiva.');
        }

        $this->specificationSchemaVersion = $specificationSchemaVersion;

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

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
