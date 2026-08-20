<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\CommercialItemManager;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialItemType;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Quotations\QuotationItemSpecificationProfile;
use App\Repository\Catalog\CommercialItemCharacteristicRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CommercialItemActivationGuardTest extends TestCase
{
    public function testRejectsReactivationWhenCategoryIsInactive(): void
    {
        $category = (new CommercialCategory())
            ->setCode('GRAN_FORMATO')
            ->setName('Gran formato')
            ->setIsActive(false);

        $unit = $this->activeSquareMetre();
        $item = $this->inactiveProduct($category, $unit);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('categoría comercial permanezca inactiva');

        $this->manager()->setActive($item, true, new User());
    }

    public function testRejectsReactivationWhenMeasurementUnitIsInactive(): void
    {
        $category = (new CommercialCategory())
            ->setCode('GRAN_FORMATO')
            ->setName('Gran formato');

        $unit = $this->activeSquareMetre()->setIsActive(false);
        $item = $this->inactiveProduct($category, $unit);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unidad de medida permanezca inactiva');

        $this->manager()->setActive($item, true, new User());
    }

    private function manager(): CommercialItemManager
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        /** @var AuditLogger $auditLogger */
        $auditLogger = (new \ReflectionClass(AuditLogger::class))->newInstanceWithoutConstructor();

        /** @var CommercialItemCharacteristicRepository $configurationRepository */
        $configurationRepository = (new \ReflectionClass(CommercialItemCharacteristicRepository::class))->newInstanceWithoutConstructor();

        return new CommercialItemManager(
            $entityManager,
            $auditLogger,
            $configurationRepository,
        );
    }

    private function activeSquareMetre(): MeasurementUnit
    {
        return (new MeasurementUnit())
            ->setCode('M2')
            ->setName('Metro cuadrado')
            ->setSymbol('m²')
            ->setDimensionType(MeasurementDimensionType::AREA)
            ->setConversionFactor('1');
    }

    private function inactiveProduct(CommercialCategory $category, MeasurementUnit $unit): CommercialItem
    {
        return (new CommercialItem())
            ->setCategory($category)
            ->setMeasurementUnit($unit)
            ->setCode('LONA-FRONT-13OZ')
            ->setType(CommercialItemType::PRODUCT)
            ->setQuotationSpecificationProfile(QuotationItemSpecificationProfile::LARGE_FORMAT)
            ->setName('Lona frontal 13 oz')
            ->setBasePrice('195')
            ->setIsActive(false);
    }
}
