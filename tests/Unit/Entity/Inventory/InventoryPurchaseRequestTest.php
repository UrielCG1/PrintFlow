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

    public function testItTracksExpectedReceiptAgainstTheEarliestRequiredOrderDate(): void
    {
        $actor = new User();
        $variant = $this->variant();
        $request = new InventoryPurchaseRequest(
            'purchase-request-unit-test-0004',
            hash('sha256', 'purchase-request-unit-test-4'),
            $actor,
        );
        $line = new InventoryPurchaseRequestLine($request, 1, $variant, '10');
        $request->addLine($line);
        $order = (new ServiceOrder())->setCommitmentDate(new \DateTimeImmutable('2026-10-15'));
        $commitment = new InventoryCommitment(
            $order,
            $variant,
            $actor,
            new InventoryCoverage('5.000000', '0.000000', '0.000000', '5.000000'),
        );
        $line->addDemand(new InventoryPurchaseRequestLineDemand($line, $commitment, '5'));

        $request->beginTracking('OC-1004', new \DateTimeImmutable('2026-10-16'));

        self::assertSame('2026-10-15', $request->getRequiredByOn()?->format('Y-m-d'));
        self::assertSame('2026-10-16', $request->getExpectedReceiptOn()?->format('Y-m-d'));
        self::assertTrue($request->isExpectedAfterRequiredDate());
        self::assertTrue($request->isExpectedReceiptOverdue(new \DateTimeImmutable('2026-10-17')));
    }

    public function testItCancelsWithTraceabilityOnlyBeforePhysicalReceipt(): void
    {
        $actor = new User();
        $request = new InventoryPurchaseRequest(
            'purchase-request-unit-test-0005',
            hash('sha256', 'purchase-request-unit-test-5'),
            $actor,
        );
        $line = new InventoryPurchaseRequestLine($request, 1, $this->variant(), '10');
        $request->addLine($line);
        $request->beginTracking('OC-1005');
        $request->updateTracking('OC-1005-R1', new \DateTimeImmutable('2026-10-20'), 'El proveedor confirmó una nueva fecha.');
        $request->cancel($actor, 'El proveedor canceló la disponibilidad del material.');

        self::assertSame(InventoryPurchaseRequestStatus::CANCELLED, $request->getStatus());
        self::assertSame('OC-1005-R1', $request->getTrackingReference());
        self::assertSame('2026-10-20', $request->getExpectedReceiptOn()?->format('Y-m-d'));
        self::assertSame($actor, $request->getCancelledBy());
        self::assertSame('El proveedor canceló la disponibilidad del material.', $request->getCancellationReason());
        self::assertFalse($request->canBeCancelled());
    }

    public function testItRejectsCancellationAfterPhysicalReceipt(): void
    {
        $actor = new User();
        $request = new InventoryPurchaseRequest(
            'purchase-request-unit-test-0006',
            hash('sha256', 'purchase-request-unit-test-6'),
            $actor,
        );
        $line = new InventoryPurchaseRequestLine($request, 1, $this->variant(), '10');
        $request->addLine($line);
        $request->beginTracking('OC-1006');
        $line->recordPhysicalReceipt($line->getVariant(), '1');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('recepciones físicas');
        $request->cancel($actor, 'Se intentó cancelar después de recibir material.');
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
