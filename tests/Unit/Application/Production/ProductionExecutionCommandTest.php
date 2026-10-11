<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionExecutionCommand;
use PHPUnit\Framework\TestCase;

final class ProductionExecutionCommandTest extends TestCase
{
    public function testItNormalizesTheCommandContract(): void
    {
        $command = new ProductionExecutionCommand('  a1b2c3d4e5f6a7b8  ', 3, '  Lectura confirmada.  ');

        self::assertSame('a1b2c3d4e5f6a7b8', $command->commandId);
        self::assertSame(3, $command->expectedOrderVersion);
        self::assertSame('Lectura confirmada.', $command->note);
    }

    public function testItRejectsAnInvalidExpectedVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductionExecutionCommand('a1b2c3d4e5f6a7b8', 0);
    }
}
