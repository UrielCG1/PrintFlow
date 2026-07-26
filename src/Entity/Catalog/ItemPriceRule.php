<?php

namespace App\Entity\Catalog;

use App\Enum\Catalog\ItemPriceRuleType;
use App\Repository\Catalog\ItemPriceRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemPriceRuleRepository::class)]
#[ORM\Table(name: 'item_price_rules')]
#[ORM\UniqueConstraint(
    name: 'uniq_item_price_rules_threshold',
    columns: ['commercial_item_id', 'rule_type', 'min_quantity'],
)]
#[ORM\Index(
    name: 'idx_item_price_rules_lookup',
    columns: ['commercial_item_id', 'is_active', 'min_quantity'],
)]
#[ORM\HasLifecycleCallbacks]
final class ItemPriceRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'commercial_item_id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialItem $commercialItem;

    #[ORM\Column(name: 'rule_type', length: 30, enumType: ItemPriceRuleType::class)]
    private ItemPriceRuleType $ruleType;

    #[ORM\Column(name: 'min_quantity', type: Types::DECIMAL, precision: 14, scale: 4)]
    private string $minQuantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
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

    public function getRuleType(): ItemPriceRuleType
    {
        return $this->ruleType;
    }

    public function setRuleType(ItemPriceRuleType $ruleType): self
    {
        $this->ruleType = $ruleType;

        return $this;
    }

    public function getMinQuantity(): string
    {
        return $this->minQuantity;
    }

    public function setMinQuantity(string $minQuantity): self
    {
        $this->minQuantity = self::normalizeMinimumQuantity($minQuantity);

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

    public static function normalizeMinimumQuantity(string $quantity): string
    {
        $value = trim(str_replace(',', '.', $quantity));

        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('La cantidad mínima no tiene un formato válido.');
        }

        [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $normalized = $integer.'.'.str_pad($decimal, 4, '0');

        if ($normalized === '0.0000') {
            throw new \InvalidArgumentException('La cantidad mínima debe ser mayor que cero.');
        }

        return $normalized;
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}