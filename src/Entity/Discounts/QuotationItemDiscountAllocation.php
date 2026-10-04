<?php

declare(strict_types=1);

namespace App\Entity\Discounts;

use App\Entity\Quotations\QuotationItem;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'quotation_item_discount_allocations')]
#[ORM\UniqueConstraint(name: 'uniq_discount_allocation_item', columns: ['application_id', 'quotation_item_id'])]
class QuotationItemDiscountAllocation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: QuotationDiscountApplication::class)] #[ORM\JoinColumn(name: 'application_id', nullable: false, onDelete: 'CASCADE')] private QuotationDiscountApplication $application;
    #[ORM\ManyToOne(targetEntity: QuotationItem::class)] #[ORM\JoinColumn(name: 'quotation_item_id', nullable: false, onDelete: 'CASCADE')] private QuotationItem $quotationItem;
    #[ORM\Column(name: 'base_amount', type: Types::DECIMAL, precision: 14, scale: 2)] private string $baseAmount = '0.00';
    #[ORM\Column(name: 'discount_amount', type: Types::DECIMAL, precision: 14, scale: 2)] private string $discountAmount = '0.00';
    public function __construct(QuotationDiscountApplication $application, QuotationItem $quotationItem) { $this->application = $application; $this->quotationItem = $quotationItem; }
    public function getId(): ?int { return $this->id; }
    public function getApplication(): QuotationDiscountApplication { return $this->application; }
    public function getQuotationItem(): QuotationItem { return $this->quotationItem; }
    public function getBaseAmount(): string { return $this->baseAmount; }
    public function setBaseAmount(string $v): self { $this->baseAmount = \App\Entity\Quotations\Quotation::normalizeAmount($v, 'La base asignada'); return $this; }
    public function getDiscountAmount(): string { return $this->discountAmount; }
    public function setDiscountAmount(string $v): self { $this->discountAmount = \App\Entity\Quotations\Quotation::normalizeAmount($v, 'El descuento asignado'); return $this; }
}
