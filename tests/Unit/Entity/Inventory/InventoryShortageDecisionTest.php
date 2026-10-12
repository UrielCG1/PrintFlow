<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Application\Inventory\InventoryCoverage;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryShortageDecision;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryShortageDecisionType;
use PHPUnit\Framework\TestCase;

final class InventoryShortageDecisionTest extends TestCase
{
    public function testItKeepsAnAdministrativeDecisionAndCanSupersedeIt(): void
    {
        $actor = new User();
        $decision = new InventoryShortageDecision(
            new InventoryCommitment(new ServiceOrder(), $this->variant(), $actor, new InventoryCoverage('5.000000', '0.000000', '0.000000', '5.000000')),
            InventoryShortageDecisionType::WAIT_FOR_PURCHASE,
            'Esperar la llegada del proveedor para completar el material.',
            $actor,
        );

        self::assertTrue($decision->isActive());
        self::assertSame(InventoryShortageDecisionType::WAIT_FOR_PURCHASE, $decision->getType());

        $decision->supersede();

        self::assertFalse($decision->isActive());
    }

    private function variant(): MaterialVariant
    {
        $unit = (new MeasurementUnit())
            ->setCode('PZA')
            ->setName('Pieza')
            ->setSymbol('pza')
            ->setDimensionType(MeasurementDimensionType::COUNT)
            ->setConversionFactor('1');

        return new MaterialVariant(new Material(), 'MATERIAL-PRUEBA', $unit, $unit, $unit);
    }
}
