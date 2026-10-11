<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Enum\Orders\ProductionOrderStatus;
use App\Enum\Orders\ServiceOrderDeliveryStatus;
use App\Enum\Orders\ServiceOrderFulfillmentMethod;
use App\Enum\Orders\ServiceOrderStatus;
use PHPUnit\Framework\TestCase;

final class ServiceOrderTest extends TestCase
{
    public function testItStartsPendingPlanningAndNormalizesItsFolio(): void
    {
        $serviceOrder = (new ServiceOrder())->setFolio(' os-2026-000001 ');

        self::assertSame(ServiceOrderStatus::PENDING_PLANNING, $serviceOrder->getStatus());
        self::assertSame(ProductionOrderStatus::WAITING_DESIGN, $serviceOrder->getProductionStatus());
        self::assertSame(ServiceOrderDeliveryStatus::NOT_READY, $serviceOrder->getDeliveryStatus());
        self::assertSame(ServiceOrderFulfillmentMethod::UNDEFINED, $serviceOrder->getFulfillmentMethod());
        self::assertFalse($serviceOrder->isDeliveryPrepared());
        self::assertSame(1, $serviceOrder->getTraceabilityVersion());
        self::assertSame('OS-2026-000001', $serviceOrder->getFolio());
        self::assertNull($serviceOrder->getCommitmentDate());
    }

    public function testItRejectsAnInvalidServiceOrderFolio(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ServiceOrder())->setFolio('OS 2026 1');
    }

    public function testItRequiresACommitmentDateBeforeItCanBeMarkedPlanned(): void
    {
        $this->expectException(\DomainException::class);

        (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->markPlanned(new User());
    }

    public function testItRecordsWhoConfirmedThePlanning(): void
    {
        $actor = new User();
        $serviceOrder = (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->setCommitmentDate(new \DateTimeImmutable('2026-08-20'))
            ->markPlanned($actor);

        self::assertSame(ServiceOrderStatus::PLANNED, $serviceOrder->getStatus());
        self::assertSame($actor, $serviceOrder->getPlannedBy());
        self::assertNotNull($serviceOrder->getPlannedAt());
    }

    public function testItKeepsPlanningProductionAndDeliveryAsSeparateAxes(): void
    {
        $serviceOrder = (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->setCommitmentDate(new \DateTimeImmutable('2026-08-20'))
            ->markPlanned(new User())
            ->beginProduction()
            ->waitForQualityReview()
            ->returnForQualityRework()
            ->resumeProductionAfterQualityRework()
            ->waitForQualityReview()
            ->markProductionCompleted()
            ->markReadyForShipment()
            ->markShipped()
            ->markDelivered();

        self::assertSame(ServiceOrderStatus::PLANNED, $serviceOrder->getStatus());
        self::assertSame(ProductionOrderStatus::PRODUCTION_COMPLETED, $serviceOrder->getProductionStatus());
        self::assertSame(ServiceOrderDeliveryStatus::DELIVERED, $serviceOrder->getDeliveryStatus());
    }

    public function testItRejectsDeliveryBeforeQualityHasReleasedProduction(): void
    {
        $this->expectException(\DomainException::class);

        (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->markReadyForPickup();
    }

    public function testItKeepsDeliveryPreparationVisibleApartFromDeliveryStatus(): void
    {
        $serviceOrder = (new ServiceOrder())
            ->setFolio('OS-2026-000001')
            ->setFulfillmentMethod(ServiceOrderFulfillmentMethod::SHIPPING)
            ->setDeliveryContactSnapshot(['source' => 'COMMERCIAL_CONTACT', 'name' => 'María López'])
            ->setDeliveryPreparationIssue('Falta el domicilio de entrega para el envío a domicilio.');

        self::assertFalse($serviceOrder->isDeliveryPrepared());
        self::assertSame('shipping', $serviceOrder->traceabilityStateSnapshot()['fulfillment_method']);
        self::assertFalse($serviceOrder->traceabilityStateSnapshot()['delivery_prepared']);
    }
}
