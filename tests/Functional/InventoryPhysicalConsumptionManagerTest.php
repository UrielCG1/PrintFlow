<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Inventory\InventoryPhysicalConsumptionManager;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionMaterialUsage;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\QuantitySource;
use App\Enum\Production\CalculationMethod;
use App\Tests\Support\IsolatedKernel;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class InventoryPhysicalConsumptionManagerTest extends TestCase
{
    public function testLinearConsumptionCreatesOneChildQrWithoutChangingTheRemainingTotal(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Run PHP with -d extension=pdo_sqlite for isolated inventory tests.');
        }

        $directory = dirname(__DIR__, 2).'/var/inventory-surplus-qa/'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $kernel = new IsolatedKernel($directory);
        $kernel->boot();

        try {
            $entityManager = $kernel->getContainer()->get('doctrine')->getManager();
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

            $linear = (new MeasurementUnit())->setCode('ML')->setName('Metro lineal')->setSymbol('m')
                ->setDimensionType(MeasurementDimensionType::LENGTH)->setConversionFactor('1')->setDecimalScale(4)->setAllowsFraction(true);
            $area = (new MeasurementUnit())->setCode('M2')->setName('Metro cuadrado')->setSymbol('m²')
                ->setDimensionType(MeasurementDimensionType::AREA)->setConversionFactor('1')->setDecimalScale(4)->setAllowsFraction(true);
            $category = (new MaterialCategory())->setCode('QA-PILOTO')->setName('Piloto');
            $material = (new Material())->setCategory($category)->setMeasurementUnit($linear)->setCode('QA-LONA')->setName('Lona QA');
            $variant = (new MaterialVariant($material, 'QA-LONA-ANCHO-1P5000M', $linear, $linear, $area))->configureRollWidth('1.5');
            $stockUnit = (new InventoryStockUnit($variant, '2'))->setStorageLocation('QA-RACK-01');
            $balance = new MaterialVariantCost($variant);
            $balance->increaseOnHand('2');
            $actor = (new User())->setUsername('surplus-qa')->setFullName('QA sobrantes')->setEmail('surplus@example.invalid')
                ->setPassword('not-used-in-this-test')->setMustChangePassword(false);
            foreach ([$linear, $area, $category, $material, $variant, $stockUnit, $balance, $actor] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $order = new ServiceOrder();
            (new \ReflectionProperty(ServiceOrder::class, 'id'))->setValue($order, 987654);
            $usage = new ProductionMaterialUsage(new ServiceOrderItem(), $variant, $area, '1.5', CalculationMethod::FIXED, 1, []);
            $usage->recordActual('1.5', '0', QuantitySource::MEASURED, $actor, 'medición manual');
            $usage->recordActualInventoryQuantity('1');
            (new \ReflectionProperty(ProductionMaterialUsage::class, 'id'))->setValue($usage, 987654);

            $entityManager->getConnection()->beginTransaction();
            try {
                $result = $kernel->getContainer()->get('test.service_container')->get(InventoryPhysicalConsumptionManager::class)
                    ->recordPrintingConsumption($order, $usage, $stockUnit, '0.5', 'QA-RACK-RET', 'Remanente del mismo ancho', $actor);
                $entityManager->getConnection()->commit();
            } catch (\Throwable $exception) {
                $entityManager->getConnection()->rollBack();
                throw $exception;
            }

            $child = $result->surplusStockUnit;
            self::assertNotNull($child);
            self::assertSame('1.000000', $result->consumedInventoryQuantity);
            self::assertSame('0.500000', $stockUnit->getRemainingQuantity());
            self::assertSame('0.500000', $child->getRemainingQuantity());
            self::assertSame($stockUnit, $child->getParentStockUnit());
            self::assertNotSame($stockUnit->getQrPayload(), $child->getQrPayload());
            self::assertSame('QA-RACK-RET', $child->getStorageLocation());
            self::assertSame('1.000000', $balance->getOnHandQuantity());
            self::assertSame(3, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_movements'));
            self::assertSame(2, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_stock_units'));
        } finally {
            $kernel->shutdown();
        }
    }
}
