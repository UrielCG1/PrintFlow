<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Inventory\InventoryReceiptCommand;
use App\Application\Inventory\InventoryReceiptLineInput;
use App\Application\Inventory\InventoryReceiptManager;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialCategory;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Enum\Catalog\MeasurementDimensionType;
use App\Tests\Support\IsolatedKernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class InventoryReceiptManagerTest extends TestCase
{
    private IsolatedKernel $kernel;
    private EntityManagerInterface $entityManager;
    private InventoryReceiptManager $manager;
    private User $actor;
    private MaterialVariant $variant;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Run PHP with -d extension=pdo_sqlite for isolated inventory tests.');
        }

        $directory = dirname(__DIR__, 2).'/var/inventory-receipt-qa/'.bin2hex(random_bytes(8));
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
        $category = (new MaterialCategory())->setCode('QA-INSUMOS')->setName('Insumos QA');
        $material = (new Material())
            ->setCategory($category)
            ->setMeasurementUnit($unit)
            ->setCode('VINIL-QA')
            ->setName('Vinil QA');
        $this->variant = new MaterialVariant($material, 'VINIL-QA-152', $unit, $unit, $unit);
        $this->actor = (new User())
            ->setUsername('inventory-qa')
            ->setFullName('Inventario QA')
            ->setEmail('inventory-qa@example.invalid')
            ->setPassword('not-used-in-this-test')
            ->setMustChangePassword(false);

        foreach ([$unit, $category, $material, $this->variant, $this->actor] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->manager = $this->kernel->getContainer()->get('test.service_container')->get(InventoryReceiptManager::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testItRecordsOneQrUnitMovementAndBalanceForAnIdempotentReceipt(): void
    {
        $command = new InventoryReceiptCommand(
            idempotencyKey: 'inventory-receipt-qa-0001',
            reference: 'REM-1001',
            notes: 'Recepción de prueba.',
            isProvisional: true,
            lines: [new InventoryReceiptLineInput(
                variant: $this->variant,
                quantity: '12.5',
                storageLocation: 'A-01',
                physicalAttributes: ['width_mm' => 1520, 'length_m' => 12.5],
                unitCostMxn: '18.75',
            )],
        );

        $receipt = $this->manager->receive($command, $this->actor);
        $retry = $this->manager->receive($command, $this->actor);

        self::assertSame($receipt->getId(), $retry->getId());
        self::assertCount(1, $receipt->getLines());
        self::assertSame('12.500000', $receipt->getLines()->first()->getReceivedQuantity());

        $connection = $this->entityManager->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_receipts'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_receipt_lines'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_stock_units'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_movements'));
        self::assertEquals(12.5, $connection->fetchOne('SELECT on_hand_quantity FROM material_variant_costs'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_movements WHERE stock_unit_id IS NULL'));
    }

    public function testItRejectsAReusedIdempotencyKeyWithDifferentContent(): void
    {
        $this->manager->receive($this->command('8'), $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('datos distintos');

        $this->manager->receive($this->command('9'), $this->actor);
    }

    public function testItCountsSeveralOpeningUnitsButRejectsLateOpeningCounts(): void
    {
        $count = new InventoryReceiptCommand(
            idempotencyKey: 'inventory-initial-count-qa-0001',
            reference: 'CORTE-QA-01',
            lines: [new InventoryReceiptLineInput($this->variant, '6.25')],
        );

        $receipt = $this->manager->recordInitialCount($count, $this->actor);

        self::assertTrue($receipt->isInitialCount());
        self::assertStringStartsWith('CON-', $receipt->getReceiptCode());
        self::assertSame('INITIAL_COUNT', $this->entityManager->getConnection()->fetchOne('SELECT receipt_type FROM inventory_receipts'));
        self::assertSame('INITIAL_COUNT', $this->entityManager->getConnection()->fetchOne('SELECT movement_type FROM inventory_movements'));

        $secondCount = new InventoryReceiptCommand(
            idempotencyKey: 'inventory-initial-count-qa-0002',
            lines: [new InventoryReceiptLineInput($this->variant, '1')],
        );
        $secondReceipt = $this->manager->recordInitialCount($secondCount, $this->actor);
        $retry = $this->manager->recordInitialCount($secondCount, $this->actor);

        self::assertSame($secondReceipt->getId(), $retry->getId());
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM inventory_stock_units'));
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne("SELECT COUNT(*) FROM inventory_movements WHERE movement_type = 'INITIAL_COUNT'"));
        self::assertEquals(7.25, $this->entityManager->getConnection()->fetchOne('SELECT on_hand_quantity FROM material_variant_costs'));

        $this->manager->receive(new InventoryReceiptCommand(
            idempotencyKey: 'inventory-receipt-after-opening-qa-0001',
            lines: [new InventoryReceiptLineInput($this->variant, '2')],
        ), $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('movimientos normales');

        $this->manager->recordInitialCount(new InventoryReceiptCommand(
            idempotencyKey: 'inventory-initial-count-qa-0003',
            lines: [new InventoryReceiptLineInput($this->variant, '1')],
        ), $this->actor);
    }

    private function command(string $quantity): InventoryReceiptCommand
    {
        return new InventoryReceiptCommand(
            idempotencyKey: 'inventory-receipt-qa-0002',
            lines: [new InventoryReceiptLineInput($this->variant, $quantity)],
        );
    }
}
