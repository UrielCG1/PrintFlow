<?php

namespace App\Entity\Catalog;

use App\Repository\Catalog\ItemPriceRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemPriceRuleRepository::class)]
#[ORM\Table(name: 'item_price_rules')]
#[ORM\UniqueConstraint(name: 'uniq_item_price_rules_threshold', columns: ['commercial_item_id', 'rule_type', 'min_quantity'])]
#[ORM\Index(name: 'idx_item_price_rules_lookup', columns: ['commercial_item_id', 'is_active', 'min_quantity'])]
#[ORM\HasLifecycleCallbacks]
final class ItemPriceRule
{
    public const TYPE_QUANTITY_TIER = 'QUANTITY_TIER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CommercialItem::class)]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\Column(name: 'rule_type', length: 30)]
    private string $ruleType = self::TYPE_QUANTITY_TIER;

    #[ORM\Column(name: 'min_quantity', type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $minQuantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['comment' => '(DC2Type:datetime_immutable)'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', options: ['comment' => '(DC2Type:datetime_immutable)'])]
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

    public function getCommercialItem(): CommercialItem
    {
        return $this->commercialItem;
    }

    public function setCommercialItem(CommercialItem $commercialItem): self
    {
        $this->commercialItem = $commercialItem;

        return $this;
    }

    public function getRuleType(): string
    {
        return $this->ruleType;
    }

    public function setRuleType(string $ruleType): self
    {
        $ruleType = strtoupper(trim($ruleType));

        if ($ruleType !== self::TYPE_QUANTITY_TIER) {
            throw new \InvalidArgumentException('El tipo de regla de precio no es válido.');
        }

        $this->ruleType = $ruleType;

        return $this;
    }

    public function getMinQuantity(): string
    {
        return $this->minQuantity;
    }

    public function setMinQuantity(string $minQuantity): self
    {
        $normalized = self::normalizeDecimal($minQuantity, 4);

        if (str_replace(['0', '.'], '', $normalized) === '') {
            throw new \InvalidArgumentException('La cantidad mínima debe ser mayor que cero.');
        }

        $this->minQuantity = $normalized;

        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): self
    {
        $this->unitPrice = self::normalizeDecimal($unitPrice, 2);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

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

    private static function normalizeDecimal(string $value, int $scale): string
    {
        $value = str_replace(',', '.', trim($value));

        if (preg_match(sprintf('/^\d+(?:\.\d{1,%d})?$/', $scale), $value) !== 1) {
            throw new \InvalidArgumentException('El valor debe ser un decimal no negativo válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($decimal, $scale, '0');
    }
}