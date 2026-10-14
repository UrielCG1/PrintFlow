<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Application\Inventory\InventoryReadinessEvaluator;
use App\Command\InventoryCatalogReadinessCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InventoryCatalogReadinessCommandTest extends TestCase
{
    public function testStrictModeFailsWhenCatalogStillHasObservations(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            [
                'material_code' => 'LONA-FRONT-13OZ',
                'material_name' => 'Lona frontal',
                'variant_code' => 'LONA-FRONT-13OZ-DEFAULT',
                'specifications' => '{"legacy_migration":true}',
                'minimum_stock' => '0.000000',
                'reorder_point' => '0.000000',
                'reorder_quantity' => '0.000000',
                'material_is_hazardous' => false,
                'storage_conditions' => null,
                'inventory_unit_code' => 'PZA',
                'inventory_unit_dimension' => 'COUNT',
                'inventory_unit_allows_fraction' => false,
                'consumption_unit_code' => 'PZA',
                'consumption_unit_dimension' => 'COUNT',
            ],
        ]);
        $connection->method('fetchOne')->willReturn(0);

        $command = new InventoryCatalogReadinessCommand($connection, new InventoryReadinessEvaluator());
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
        self::assertStringContainsString('revisión estricta', $tester->getDisplay());
    }
}
