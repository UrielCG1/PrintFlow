<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Orders;

use App\Application\Orders\ProductionRouteApplicator;
use App\Application\Orders\ProductionRoutePolicy;
use App\Application\Production\ProductionRouteStepData;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Operations\Operation;
use App\Entity\Operations\OperationArea;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\CommercialItemRouteStep;
use App\Entity\Production\CommercialItemRouteVersion;
use App\Entity\Quotations\QuotationItem;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialItemType;
use App\Enum\Production\RouteCondition;
use PHPUnit\Framework\TestCase;

final class ProductionRouteApplicatorTest extends TestCase
{
    public function testClientFileStillStartsInDesignBeforePrepress(): void
    {
        [$item, $version, $design, $prepress] = $this->routeFixture();
        $source = (new QuotationItem())->setAttachmentPath('/private/artwork.pdf');
        $orderItem = (new ServiceOrderItem())
            ->setSourceQuotationItem($source)
            ->setCommercialItem($item);
        $actor = new User();

        (new ProductionRouteApplicator())->apply($orderItem, $actor);

        self::assertSame($version, $orderItem->getRouteVersion());
        self::assertCount(2, $orderItem->getActiveOperationPlans());
        self::assertSame($design, $orderItem->getActiveOperationPlans()[0]->getOperation());
        self::assertSame($prepress, $orderItem->getActiveOperationPlans()[1]->getOperation());
        self::assertTrue($orderItem->getActiveOperationPlans()[1]->getConditionApplied());
        self::assertNull($orderItem->getPlanningIssue());
    }

    public function testMissingClientFileIncludesDesignAndBlocksPrepressWithCause(): void
    {
        [$item, $version, $design, $prepress] = $this->routeFixture();
        $source = new QuotationItem();
        $orderItem = (new ServiceOrderItem())
            ->setSourceQuotationItem($source)
            ->setCommercialItem($item);

        (new ProductionRouteApplicator())->apply($orderItem, new User());

        $plans = $orderItem->getActiveOperationPlans();
        self::assertSame($version, $orderItem->getRouteVersion());
        self::assertCount(2, $plans);
        self::assertSame($design, $plans[0]->getOperation());
        self::assertNull($plans[0]->getBlockedReason());
        self::assertSame($prepress, $plans[1]->getOperation());
        self::assertFalse($plans[1]->getConditionApplied());
        self::assertSame('Requiere el archivo del cliente antes de continuar.', $plans[1]->getBlockedReason());
        self::assertNull($orderItem->getPlanningIssue());
    }

    public function testMissingPublishedRouteLeavesAnExplicitPlanningIssue(): void
    {
        $item = $this->commercialItem();
        $orderItem = (new ServiceOrderItem())
            ->setSourceQuotationItem(new QuotationItem())
            ->setCommercialItem($item);

        (new ProductionRouteApplicator())->apply($orderItem, new User());

        self::assertNull($orderItem->getRouteVersion());
        self::assertSame('El Producto no tiene una ruta de producción publicada.', $orderItem->getPlanningIssue());
        self::assertCount(0, $orderItem->getActiveOperationPlans());
    }

    public function testRouteWithoutMandatoryDesignFirstLeavesAnExplicitPlanningIssue(): void
    {
        [$item, $version] = $this->routeFixture();
        $version->getSteps()->first()->setOperation($this->operation('PREPRENSA', 'PREPRESS'));
        $orderItem = (new ServiceOrderItem())
            ->setSourceQuotationItem(new QuotationItem())
            ->setCommercialItem($item);

        (new ProductionRouteApplicator())->apply($orderItem, new User());

        self::assertSame(
            'La ruta publicada no inicia con Diseño obligatorio; requiere corrección antes de planificar.',
            $orderItem->getPlanningIssue(),
        );
        self::assertCount(0, $orderItem->getActiveOperationPlans());
    }

    public function testPolicyRejectsPublishingARouteThatDoesNotStartInDesign(): void
    {
        $step = new ProductionRouteStepData();
        $step->operation = $this->operation('PREPRENSA', 'PREPRESS');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('La primera operación obligatoria de toda ruta debe ser Diseño');

        ProductionRoutePolicy::assertStartsWithDesign([$step]);
    }

    /** @return array{CommercialItem, CommercialItemRouteVersion, Operation, Operation} */
    private function routeFixture(): array
    {
        $item = $this->commercialItem();
        $design = $this->operation('PRE-DISENO', 'PREPRESS');
        $prepress = $this->operation('PREPRENSA', 'PREPRESS');
        $version = (new CommercialItemRouteVersion())
            ->setCommercialItem($item)
            ->setVersionNumber(1)
            ->setNameSnapshot('Ruta gran formato')
            ->setDefinitionSnapshot(['steps' => []]);
        $version->addStep((new CommercialItemRouteStep())
            ->setOperation($design)
            ->setSequenceNumber(1)
            ->setCondition(RouteCondition::ALWAYS)
            ->setIsRequired(true)
            ->setOperationSnapshot(['code' => 'DISENO', 'name' => 'Diseño', 'area_name' => 'Diseño']));
        $version->addStep((new CommercialItemRouteStep())
            ->setOperation($prepress)
            ->setSequenceNumber(2)
            ->setCondition(RouteCondition::CLIENT_FILE_PRESENT)
            ->setIsRequired(true)
            ->setOperationSnapshot(['code' => 'PREPRENSA', 'name' => 'Preprensa', 'area_name' => 'Preprensa']));
        $item->setCurrentRouteVersion($version);

        return [$item, $version, $design, $prepress];
    }

    private function commercialItem(): CommercialItem
    {
        $category = (new CommercialCategory())->setCode('QA')->setName('QA');
        $unit = (new MeasurementUnit())->setCode('PZA')->setName('Pieza');

        return (new CommercialItem())
            ->setCode('QA-ITEM')
            ->setName('Producto de QA')
            ->setType(CommercialItemType::PRODUCT)
            ->setCategory($category)
            ->setMeasurementUnit($unit)
            ->setBasePrice('1');
    }

    private function operation(string $code, ?string $areaCode = null): Operation
    {
        $area = (new OperationArea())->setCode($areaCode ?? $code)->setName($areaCode ?? $code);

        return (new Operation())
            ->setCode($code)
            ->setName($code)
            ->setOperationArea($area);
    }
}
