<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use App\Entity\Clients\ClientClass;
use App\Entity\Discounts\CommercialCostingProfile;
use App\Entity\Discounts\DiscountType;
use App\Entity\Discounts\VolumeDiscountRule;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcula descuentos V2 sobre las bases brutas originales. No lee ClientCategory.
 * Las aplicaciones normalizadas se persisten en una etapa posterior cuando la
 * cotización y sus partidas ya tienen identidad.
 */
final class QuotationDiscountEngine
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @param list<array{line_number:int,quantity:string,line_subtotal:string,commercial_item:object}> $lines */
    public function calculate(array $lines, ?ClientClass $clientClass, string $taxRate, string $additionalPercent = '0.0000', ?string $additionalReason = null): array
    {
        $subtotal = BigDecimal::zero();
        $groups = [];
        foreach ($lines as $line) {
            $amount = BigDecimal::of($line['line_subtotal'])->toScale(2);
            $subtotal = $subtotal->plus($amount);
            $category = $line['commercial_item']->getCategory();
            $categoryId = (string) ($category->getId() ?? '0');
            $groups[$categoryId]['category'] = $category;
            $groups[$categoryId]['volume'] = ($groups[$categoryId]['volume'] ?? BigDecimal::zero())->plus($line['quantity']);
            $groups[$categoryId]['lines'][] = $line;
            $groups[$categoryId]['base'] = ($groups[$categoryId]['base'] ?? BigDecimal::zero())->plus($amount);
        }

        $applications = [];
        $discountTotal = BigDecimal::zero();
        $volumeFingerprint = [];
        $volumeType = $this->entityManager->getRepository(DiscountType::class)->findOneBy(['code' => 'VOLUME']);

        foreach ($groups as $group) {
            $profile = $this->entityManager->getRepository(CommercialCostingProfile::class)->findOneBy([
                'commercialCategory' => $group['category'], 'isActive' => true,
            ]);
            $rule = null;
            if ($profile instanceof CommercialCostingProfile && $volumeType instanceof DiscountType) {
                $rule = $this->entityManager->getRepository(VolumeDiscountRule::class)
                    ->createQueryBuilder('r')
                    ->andWhere('r.profile = :profile')->andWhere('r.discountType = :type')->andWhere('r.isActive = true')
                    ->andWhere('r.minVolume <= :volume')->setParameter('profile', $profile)->setParameter('type', $volumeType)
                    ->setParameter('volume', $group['volume']->toScale(6)->__toString())
                    ->orderBy('r.minVolume', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            }
            $percent = $rule instanceof VolumeDiscountRule ? $rule->getDiscountPercent() : '0.0000';
            $amount = $group['base']->multipliedBy($percent)->dividedBy('100', 2, RoundingMode::HalfUp);
            if ($amount->compareTo('0') > 0) {
                $discountTotal = $discountTotal->plus($amount);
                $applications[] = [
                    'type' => 'VOLUME', 'scope' => 'BUSINESS_LINE', 'category' => $group['category']->getName(),
                    'category_id' => $group['category']->getId(), 'volume' => $group['volume']->toScale(6)->__toString(),
                    'percentage' => $percent, 'base_amount' => $group['base']->toScale(2)->__toString(),
                    'discount_amount' => $amount->toScale(2)->__toString(), 'rule_id' => $rule?->getId(),
                    'min_volume' => $rule?->getMinVolume(),
                    'unit' => $profile?->getCostingUnit()?->getSymbol() ?: $profile?->getCostingUnit()?->getName(),
                ];
            }
            $volumeFingerprint[] = [
                $group['category']->getId(),
                $group['volume']->toScale(6)->__toString(),
                $rule?->getId(),
                $rule?->getMinVolume(),
                $rule?->getConfigRevision(),
                $profile?->getProfileRevision(),
                $profile?->getStrategyVersion(),
                $percent,
            ];
        }

        $loyalty = $clientClass?->getLoyaltyDiscountPercent() ?? '0.0000';
        $loyaltyAmount = $subtotal->multipliedBy($loyalty)->dividedBy('100', 2, RoundingMode::HalfUp);
        if ($loyaltyAmount->compareTo('0') > 0) {
            $discountTotal = $discountTotal->plus($loyaltyAmount);
            $applications[] = ['type' => 'LOYALTY', 'scope' => 'QUOTATION', 'class' => $clientClass?->getCode(), 'percentage' => $loyalty, 'base_amount' => $subtotal->toScale(2)->__toString(), 'discount_amount' => $loyaltyAmount->toScale(2)->__toString()];
        }

        $additionalAmount = $subtotal->multipliedBy($additionalPercent)->dividedBy('100', 2, RoundingMode::HalfUp);
        if ($additionalAmount->compareTo('0') > 0) {
            $discountTotal = $discountTotal->plus($additionalAmount);
            $applications[] = ['type' => 'ADDITIONAL', 'scope' => 'QUOTATION', 'percentage' => $additionalPercent, 'base_amount' => $subtotal->toScale(2)->__toString(), 'discount_amount' => $additionalAmount->toScale(2)->__toString(), 'reason' => $additionalReason];
        }

        if ($discountTotal->compareTo($subtotal) > 0) {
            throw new \DomainException('La combinación de descuentos supera el subtotal bruto de la cotización.');
        }
        $taxable = $subtotal->minus($discountTotal)->toScale(2, RoundingMode::HalfUp);
        $tax = $taxable->multipliedBy($taxRate)->toScale(2, RoundingMode::HalfUp);
        $total = $taxable->plus($tax)->toScale(2, RoundingMode::HalfUp);
        $pricingPayload = ['lines' => array_map(static fn (array $line): array => [$line['line_number'], $line['quantity'], $line['line_subtotal']], $lines), 'applications' => $applications, 'tax_rate' => $taxRate, 'class' => $clientClass?->getCode()];
        $volumePayload = ['groups' => $volumeFingerprint];

        return [
            'subtotal' => $subtotal->toScale(2)->__toString(), 'discount_amount' => $discountTotal->toScale(2)->__toString(),
            'taxable_amount' => $taxable->__toString(), 'tax_amount' => $tax->__toString(), 'total' => $total->__toString(),
            'applications' => $applications, 'pricing_hash' => hash('sha256', (string) json_encode($pricingPayload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            'volume_hash' => hash('sha256', (string) json_encode($volumePayload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            'has_volume_discount' => array_filter($applications, static fn (array $app): bool => $app['type'] === 'VOLUME') !== [],
        ];
    }
}
