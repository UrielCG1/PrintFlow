<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class ItemPriceRuleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(CommercialItem $item, ItemPriceRuleData $data, User $actor): ItemPriceRule
    {
        return $this->entityManager->wrapInTransaction(function () use ($item, $data, $actor): ItemPriceRule {
            $rule = new ItemPriceRule();
            $rule->setCommercialItem($item)->setRuleType(ItemPriceRule::TYPE_QUANTITY_TIER);
            $this->applyData($rule, $data);

            $this->entityManager->persist($rule);
            $this->entityManager->flush();

            $this->auditLogger->record($actor, 'item_price_rule.created', 'item_price_rule', $rule->getId(), null, $this->snapshot($rule));
            $this->entityManager->flush();

            return $rule;
        });
    }

    public function update(ItemPriceRule $rule, ItemPriceRuleData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($rule, $data, $actor): void {
            $oldValues = $this->snapshot($rule);
            $this->applyData($rule, $data);
            $newValues = $this->snapshot($rule);

            if ($oldValues === $newValues) {
                return;
            }

            $action = $oldValues['unit_price'] !== $newValues['unit_price']
                ? 'item_price_rule.price_updated'
                : 'item_price_rule.updated';

            $this->auditLogger->record($actor, $action, 'item_price_rule', $rule->getId(), $oldValues, $newValues);
            $this->entityManager->flush();
        });
    }

    public function setActive(ItemPriceRule $rule, bool $isActive, User $actor): void
    {
        if ($rule->isActive() === $isActive) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($rule, $isActive, $actor): void {
            $oldValues = $this->snapshot($rule);
            $rule->setIsActive($isActive);
            $newValues = $this->snapshot($rule);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'item_price_rule.activated' : 'item_price_rule.deactivated',
                'item_price_rule',
                $rule->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    private function applyData(ItemPriceRule $rule, ItemPriceRuleData $data): void
    {
        $rule
            ->setMinQuantity((string) $data->minQuantity)
            ->setUnitPrice((string) $data->unitPrice);
    }

    /** @return array<string, bool|int|string> */
    private function snapshot(ItemPriceRule $rule): array
    {
        return [
            'commercial_item_id' => $rule->getCommercialItem()->getId(),
            'rule_type' => $rule->getRuleType(),
            'min_quantity' => $rule->getMinQuantity(),
            'unit_price' => $rule->getUnitPrice(),
            'is_active' => $rule->isActive(),
        ];
    }
}