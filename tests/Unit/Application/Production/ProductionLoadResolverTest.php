<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionLoadResolver;
use App\Entity\Orders\ServiceOrderItem;
use App\Enum\Equipment\ProductionLoadUnit;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

final class ProductionLoadResolverTest extends TestCase
{
    public function testItDerivesPrintAreaFromDimensionsAndQuantity(): void
    {
        $item = (new ServiceOrderItem())
            ->setQuantity('10')
            ->setProductionSpecificationsSnapshot([
                'large_format' => ['values' => ['finished_width_cm' => '50', 'finished_height_cm' => '100']],
            ]);
        $load = (new ProductionLoadResolver())->automaticLoad($item, ProductionLoadUnit::SQUARE_METERS);

        self::assertNotNull($load);
        self::assertSame(0, BigDecimal::of($load)->compareTo('5'));
    }

    public function testItNeverGuessesLinearMetersOrCutPathFromPieceCountAndArea(): void
    {
        $item = (new ServiceOrderItem())
            ->setQuantity('1000')
            ->setProductionSpecificationsSnapshot([
                'large_format' => ['values' => ['finished_width_cm' => '10', 'finished_height_cm' => '10']],
            ]);
        $resolver = new ProductionLoadResolver();

        self::assertNull($resolver->automaticLoad($item, ProductionLoadUnit::LINEAR_METERS));
        self::assertNull($resolver->automaticLoad($item, ProductionLoadUnit::CUT_CENTIMETERS));
    }
}
