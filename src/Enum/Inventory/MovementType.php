<?php
declare(strict_types=1);
namespace App\Enum\Inventory;
enum MovementType: string
{
    case PURCHASE='PURCHASE'; case RECEIPT='RECEIPT'; case PRODUCTION_CONSUMPTION='PRODUCTION_CONSUMPTION';
    case SALE='SALE'; case RETURN='RETURN'; case ADJUSTMENT_IN='ADJUSTMENT_IN'; case ADJUSTMENT_OUT='ADJUSTMENT_OUT';
    case WASTE='WASTE'; case RESERVATION='RESERVATION'; case RELEASE='RELEASE';
    public function affectsOnHand(): bool { return !in_array($this, [self::RESERVATION,self::RELEASE], true); }
}
