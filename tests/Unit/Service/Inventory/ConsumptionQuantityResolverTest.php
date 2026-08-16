<?php
declare(strict_types=1);
namespace App\Tests\Unit\Service\Inventory;
use App\Enum\Inventory\QuantitySource; use App\Service\Inventory\ConsumptionQuantityResolver; use PHPUnit\Framework\TestCase;
final class ConsumptionQuantityResolverTest extends TestCase
{
 public function testUsesCalculatedQuantityWithoutPresentingItAsActual():void{$result=(new ConsumptionQuantityResolver())->resolve('2.15',null);self::assertNull($result['actual_quantity']);self::assertSame('2.150000',$result['posted_quantity']);self::assertSame(QuantitySource::ESTIMATED,$result['quantity_source']);}
 public function testUsesOptionalMeasuredQuantity():void{$result=(new ConsumptionQuantityResolver())->resolve('2.15','2.22');self::assertSame('2.220000',$result['actual_quantity']);self::assertSame('2.220000',$result['posted_quantity']);self::assertSame(QuantitySource::MEASURED,$result['quantity_source']);}
}
