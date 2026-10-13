<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Application\Inventory\InventoryCoverage;
use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryPurchaseRequest;
use App\Entity\Inventory\InventoryPurchaseRequestLine;
use App\Entity\Inventory\InventoryPurchaseRequestLineDemand;
use App\Entity\Materials\Material;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryPurchaseRequestStatus;
use PHPUnit\Framework\TestCase;

final class InventoryPurchaseRequestTest extends TestCase
{
    public function testItTracksPartialPhysicalReceiptsOnlyAfterThePurchaseIsInProgress(): void
    {
        $request = new InventoryPurchaseRequest(
            'purchase-request-unit-test-0001',
            hash('sha256', 'purchase-request-unit-test'),
            new User(),
        );
        $line = new InventoryPurchaseRequestLine($request, 1, $this->variant(), '10');
        $request->addLine($line);

        self::assertSame(InventoryPurchaseRequestStatus::DRAFT, $request->getStatus());
        self::assertSame('10.000000', $line->getRemainingQuantity());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('en seguimiento');
        $line->recordPhysicalReceipt($line->getVariant(), '1');
    }

    public function testItClosesOnlyAfterAllRequestedMaterialWasPhysicallyReceived(): void
    {
        $request = new InventoryPurchaseRequest(
            'purchase-request-unit-test-0002',
            hash('sha256', 'purchase-request-unit-test-2'),
            new User(),
        );
        $line = new InventoryPurchaseRequestLine($request, 1, $this->variant(), '10');
        $request->addLine($line);
        $request->beginTracking('OC-1002');

        $line->recordPhysicalReceipt($line->getVariant(), '4.25');

        self::assertSame('4.250000', $line->getReceivedQuantity());
        self::assertSame('5.750000', $line->getRemainingQuantity());
        self::assertSame(InventoryPurchaseRequestStatus::IN_PROGRESS, $request->getStatus());

        $line->recordPhysicalReceipt($line->getVariant(), '5.75');

        self::assertTrue($line->isFullyReceived());
        self::assertSame(InventoryPurchaseRequestStatus::CLOSED, $request->getStatus());
    }

    public function testItKeepsTheWaitingOrderDemandLinkedToThePurchaseLine(): void
    {
        $actor = new User();
        $variant = $this->variant();
        $request = new InventoryPurchaseRequest(
            'purchase-request-unit-test-0003',
            hash('sha256', 'purchase-request-unit-test-3'),
            $actor,
        );
        $line = new InventoryPurchaseRequestLine($request, 1, $variant, '10');
        $request->addLine($line);
        $commitment = new InventoryCommitment(
            new ServiceOrder(),
            $variant,
            $actor,
            new InventoryCoverage('5.000000', '0.000000', '0.000000', '5.000000'),
        );
        $demand = new InventoryPurchaseRequestLineDemand($line, $commitment, '5');
        $line->addDemand($demand);

        self::assertCount(1, $line->getDemands());
        self::assertSame($commitment, $demand->getCommitment());
        self::assertSame('5.000000', $demand->getPlannedQuantity());
    }

    private function variant(): MaterialVariant
    {
        $unit = (new MeasurementUnit())
            ->setCode('ML')
            ->setName('Metro lineal')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1');

        return new MaterialVariant(new Material(), 'VINIL-BLANCO-152', $unit, $unit, $unit);
    }
}
