<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Inventory\InventoryReceiptCommand;
use App\Application\Inventory\InventoryReceiptLineInput;
use App\Application\Inventory\InventoryReceiptManager;
use App\Application\Inventory\InventoryCycleCountPlanner;
use App\Application\Inventory\InventoryStockUnitOperationCommand;
use App\Application\Inventory\InventoryStockUnitOperationManager;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Enum\Inventory\InventoryStockUnitOperationReason;
use App\Enum\Inventory\InventoryStockUnitOperationType;
use App\Enum\Inventory\InventoryStockUnitStatus;
use App\Tests\Support\IsolatedKernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class InventoryStockUnitOperationManagerTest extends TestCase
{
    private IsolatedKernel $kernel;
    private EntityManagerInterface $entityManager;
    private InventoryReceiptManager $receiptManager;
    private InventoryStockUnitOperationManager $operationManager;
    private User $actor;
    private MaterialVariant $variant;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Run PHP with -d extension=pdo_sqlite for isolated inventory tests.');
        }

        $directory = dirname(__DIR__, 2).'/var/inventory-stock-operation-qa/'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->kernel = new IsolatedKernel($directory);
        $this->kernel->boot();
        $this->entityManager = $this->kernel->getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $unit = (new MeasurementUnit())
            ->setCode('ML')
            ->setName('Metro lineal')
            ->setSymbol('m')
            ->setDimensionType(MeasurementDimensionType::LENGTH)
            ->setConversionFactor('1')
            ->setDecimalScale(4)
            ->setAllowsFraction(true);
        $category = (new MaterialCategory())->setCode('QA-BODEGA')->setName('Bodega QA');
        $material = (new Material())
            ->setCategory($category)
            ->setMeasurementUnit($unit)
            ->setCode('VINIL-OP-QA')
            ->setName('Vinil operación QA');
        $this->variant = new MaterialVariant($material, 'VINIL-OP-QA-152', $unit, $unit, $unit);
        $this->actor = (new User())
            ->setUsername('warehouse-qa')
            ->setFullName('Bodega QA')
            ->setEmail('warehouse-qa@example.invalid')
            ->setPassword('not-used-in-this-test')
            ->setMustChangePassword(false);

        foreach ([$unit, $category, $material, $this->variant, $this->actor] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $services = $this->kernel->getContainer()->get('test.service_container');
        $this->receiptManager = $services->get(InventoryReceiptManager::class);
        $this->operationManager = $services->get(InventoryStockUnitOperationManager::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testItBlocksAndReleasesTheWholeQrUnitWithoutChangingPhysicalStock(): void
    {
        $stockUnit = $this->receive('10');

        $blocked = $this->operationManager->perform(new InventoryStockUnitOperationCommand(
            $stockUnit->getQrPayload(),
            InventoryStockUnitOperationType::BLOCK,
            InventoryStockUnitOperationReason::QUALITY_REVIEW,
            'Sello dañado; pendiente de revisión de calidad.',
        ), $this->actor);

        self::assertSame(InventoryStockUnitStatus::BLOCKED, $stockUnit->getStatus());
        self::assertSame('10.000000', $blocked->getQuantity());
        self::assertNull($blocked->getMovement());
        self::assertEquals(10, $this->scalar('SELECT on_hand_quantity FROM material_variant_costs'));
        self::assertEquals(10, $this->scalar('SELECT blocked_quantity FROM material_variant_costs'));
        self::assertEquals(0, $this->scalar('SELECT committed_quantity FROM material_variant_costs'));

        $released = $this->operationManager->perform(new InventoryStockUnitOperationCommand(
            $stockUnit->getQrPayload(),
            InventoryStockUnitOperationType::RELEASE,
            InventoryStockUnitOperationReason::QUALITY_REVIEW,
            'Calidad verificó el empaque y autorizó su uso.',
        ), $this->actor);

        self::assertSame(InventoryStockUnitStatus::AVAILABLE, $stockUnit->getStatus());
        self::assertSame('10.000000', $released->getQuantity());
        self::assertEquals(10, $this->scalar('SELECT on_hand_quantity FROM material_variant_costs'));
        self::assertEquals(0, $this->scalar('SELECT blocked_quantity FROM material_variant_costs'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM inventory_movements'));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM inventory_stock_unit_operations'));
    }

    public function testItWithdrawsABlockedExpiredUnitAndRecordsAWasteMovement(): void
    {
        $stockUnit = $this->receive('8.5');
        $this->operationManager->perform(new InventoryStockUnitOperationCommand(
            $stockUnit->getQrPayload(),
            InventoryStockUnitOperationType::BLOCK,
            InventoryStockUnitOperationReason::EXPIRED,
            'Caducidad detectada durante la revisión mensual.',
        ), $this->actor);

        $withdrawal = $this->operationManager->perform(new InventoryStockUnitOperationCommand(
            $stockUnit->getQrPayload(),
            InventoryStockUnitOperationType::DISPOSE,
            InventoryStockUnitOperationReason::EXPIRED,
            'Caducidad confirmada; material enviado a disposición final.',
        ), $this->actor);

        self::assertSame(InventoryStockUnitStatus::DISPOSED, $stockUnit->getStatus());
        self::assertSame('0.000000', $stockUnit->getRemainingQuantity());
        self::assertNotNull($withdrawal->getMovement());
        self::assertEquals(0, $this->scalar('SELECT on_hand_quantity FROM material_variant_costs'));
        self::assertEquals(0, $this->scalar('SELECT blocked_quantity FROM material_variant_costs'));
        self::assertSame('WASTE', $this->scalar("SELECT movement_type FROM inventory_movements WHERE source_type = 'inventory_stock_unit_operation'"));
        self::assertEquals(-8.5, $this->scalar("SELECT quantity FROM inventory_movements WHERE source_type = 'inventory_stock_unit_operation'"));
    }

    public function testItRequiresAnExternalReferenceForAReturnToSupplier(): void
    {
        $stockUnit = $this->receive('4');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('folio de remisión');

        $this->operationManager->perform(new InventoryStockUnitOperationCommand(
            $stockUnit->getQrPayload(),
            InventoryStockUnitOperationType::DISPOSE,
            InventoryStockUnitOperationReason::RETURN_TO_SUPPLIER,
            'Proveedor autorizó el retorno del lote defectuoso.',
        ), $this->actor);
    }

    public function testItRecordsSupplierReturnsAsReturnMovements(): void
    {
        $stockUnit = $this->receive('4');

        $this->operationManager->perform(new InventoryStockUnitOperationCommand(
            $stockUnit->getQrPayload(),
            InventoryStockUnitOperationType::DISPOSE,
            InventoryStockUnitOperationReason::RETURN_TO_SUPPLIER,
            'El proveedor aceptó el retorno por defecto de fabricación.',
            'RMA-2048',
        ), $this->actor);

        self::assertSame(InventoryStockUnitStatus::DISPOSED, $stockUnit->getStatus());
        self::assertSame('RETURN', $this->scalar("SELECT movement_type FROM inventory_movements WHERE source_type = 'inventory_stock_unit_operation'"));
        self::assertEquals(-4, $this->scalar("SELECT quantity FROM inventory_movements WHERE source_type = 'inventory_stock_unit_operation'"));
    }

    public function testItSuggestsQrUnitsForCycleCountWithoutChangingStock(): void
    {
        $stockUnit = $this->receive('6');
        $planner = $this->kernel->getContainer()->get('test.service_container')->get(InventoryCycleCountPlanner::class);

        $priorities = $planner->suggest(5, new \DateTimeImmutable('2026-10-13', new \DateTimeZone('UTC')));

        self::assertCount(1, $priorities);
        self::assertSame($stockUnit->getId(), $priorities[0]->stockUnit->getId());
        self::assertSame(6, (int) $this->scalar('SELECT on_hand_quantity FROM material_variant_costs'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM inventory_movements'));
    }

    private function receive(string $quantity): \App\Entity\Inventory\InventoryStockUnit
    {
        $receipt = $this->receiptManager->receive(new InventoryReceiptCommand(
            idempotencyKey: 'warehouse-operation-receipt-'.bin2hex(random_bytes(8)),
            reference: 'REM-WH-QA',
            lines: [new InventoryReceiptLineInput($this->variant, $quantity)],
        ), $this->actor);

        return $receipt->getLines()->first()->getStockUnit();
    }

    private function scalar(string $sql): mixed
    {
        return $this->entityManager->getConnection()->fetchOne($sql);
    }
}
