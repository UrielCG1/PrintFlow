<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Entity\Suppliers\SupplierMaterialVariant;
use App\Repository\Inventory\InventoryPurchaseRequestLineRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A requested material quantity, reconciled only by actual physical receipt lines. */
#[ORM\Entity(repositoryClass: InventoryPurchaseRequestLineRepository::class)]
#[ORM\Table(name: 'inventory_purchase_request_lines')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_request_line_number', columns: ['purchase_request_id', 'line_number'])]
#[ORM\UniqueConstraint(name: 'uniq_purchase_request_line_variant', columns: ['purchase_request_id', 'material_variant_id'])]
#[ORM\Index(name: 'idx_purchase_request_line_variant', columns: ['material_variant_id'])]
class InventoryPurchaseRequestLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'purchase_request_id', nullable: false, onDelete: 'RESTRICT')]
    private InventoryPurchaseRequest $purchaseRequest;

    #[ORM\Column(name: 'line_number')]
    private int $lineNumber;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialVariant $variant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'supplier_material_variant_id', nullable: true, onDelete: 'RESTRICT')]
    private ?SupplierMaterialVariant $supplierOffer;

    #[ORM\Column(name: 'requested_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $requestedQuantity;

    #[ORM\Column(name: 'received_quantity', type: Types::DECIMAL, precision: 20, scale: 6)]
    private string $receivedQuantity = '0.000000';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        InventoryPurchaseRequest $purchaseRequest,
        int $lineNumber,
        MaterialVariant $variant,
        string $requestedQuantity,
        ?SupplierMaterialVariant $supplierOffer = null,
    ) {
        if ($lineNumber < 1) {
            throw new \InvalidArgumentException('La partida de compra debe tener un número positivo.');
        }
        if ($supplierOffer !== null && $supplierOffer->getVariant() !== $variant) {
            throw new \DomainException('La oferta de proveedor debe corresponder a la misma presentación solicitada.');
        }

        $this->purchaseRequest = $purchaseRequest;
        $this->lineNumber = $lineNumber;
        $this->variant = $variant;
        $this->supplierOffer = $supplierOffer;
        $this->requestedQuantity = self::positiveDecimal($requestedQuantity, 'La cantidad solicitada');
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseRequest(): InventoryPurchaseRequest { return $this->purchaseRequest; }
    public function getVariant(): MaterialVariant { return $this->variant; }
    public function getSupplierOffer(): ?SupplierMaterialVariant { return $this->supplierOffer; }
    public function getRequestedQuantity(): string { return $this->requestedQuantity; }
    public function getReceivedQuantity(): string { return $this->receivedQuantity; }
    public function getRemainingQuantity(): string { return BigDecimal::of($this->requestedQuantity)->minus($this->receivedQuantity)->toScale(6)->__toString(); }
    public function isFullyReceived(): bool { return BigDecimal::of($this->getRemainingQuantity())->isZero(); }

    public function recordPhysicalReceipt(MaterialVariant $variant, string $quantity): void
    {
        if (!$this->purchaseRequest->isInProgress()) {
            throw new \DomainException('La solicitud debe estar en seguimiento antes de registrar una recepción parcial.');
        }
        if ($variant !== $this->variant) {
            throw new \DomainException('La recepción física debe corresponder a la misma presentación solicitada.');
        }

        $quantity = self::positiveDecimal($quantity, 'La cantidad recibida');
        if (BigDecimal::of($quantity)->isGreaterThan(BigDecimal::of($this->getRemainingQuantity()))) {
            throw new \DomainException('La recepción supera el saldo pendiente de la solicitud de compra. Registra el excedente como una recepción independiente.');
        }
        $this->receivedQuantity = BigDecimal::of($this->receivedQuantity)->plus($quantity)->toScale(6)->__toString();
        $this->purchaseRequest->closeIfFullyReceived();
    }

    private static function positiveDecimal(string $value, string $field): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1 || BigDecimal::of($value)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException($field.' debe ser mayor que cero y tener máximo seis decimales.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }
}
