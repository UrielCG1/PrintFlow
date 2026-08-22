<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Catalog;

use App\Application\Catalog\CommercialCharacteristicData;
use App\Application\Catalog\CommercialCharacteristicTechnicalContract;
use App\Entity\Catalog\CommercialCharacteristic;
use App\Enum\Catalog\CommercialCharacteristicInputType;
use PHPUnit\Framework\TestCase;

final class CommercialCharacteristicTechnicalContractTest extends TestCase
{
    public function testRecognizesLargeFormatWidthContract(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISHED_WIDTH_CM')
            ->setName('Ancho terminado')
            ->setInputType(CommercialCharacteristicInputType::DECIMAL)
            ->setUnitLabel('cm');

        $contract = (new CommercialCharacteristicTechnicalContract())->forCharacteristic($characteristic);

        self::assertNotNull($contract);
        self::assertSame('FINISHED_WIDTH_CM', $contract['code']);
        self::assertSame(CommercialCharacteristicInputType::DECIMAL, $contract['inputType']);
        self::assertSame('cm', $contract['unitLabel']);
    }

    public function testAllowsVisibleNameChangeWhilePreservingTechnicalDefinition(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISHED_HEIGHT_CM')
            ->setName('Alto terminado')
            ->setInputType(CommercialCharacteristicInputType::DECIMAL)
            ->setUnitLabel('cm');

        $data = new CommercialCharacteristicData();
        $data->code = 'FINISHED_HEIGHT_CM';
        $data->name = 'Alto final';
        $data->inputType = CommercialCharacteristicInputType::DECIMAL;
        $data->unitLabel = 'cm';

        (new CommercialCharacteristicTechnicalContract())->assertDefinitionPreserved($characteristic, $data);
        self::assertTrue(true);
    }

    public function testRejectsChangingProtectedCode(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISHED_WIDTH_CM')
            ->setName('Ancho terminado')
            ->setInputType(CommercialCharacteristicInputType::DECIMAL)
            ->setUnitLabel('cm');

        $data = new CommercialCharacteristicData();
        $data->code = 'WIDTH';
        $data->name = 'Ancho terminado';
        $data->inputType = CommercialCharacteristicInputType::DECIMAL;
        $data->unitLabel = 'cm';

        $this->expectException(\DomainException::class);
        (new CommercialCharacteristicTechnicalContract())->assertDefinitionPreserved($characteristic, $data);
    }

    public function testRejectsChangingProtectedInputType(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISHED_WIDTH_CM')
            ->setName('Ancho terminado')
            ->setInputType(CommercialCharacteristicInputType::DECIMAL)
            ->setUnitLabel('cm');

        $data = new CommercialCharacteristicData();
        $data->code = 'FINISHED_WIDTH_CM';
        $data->name = 'Ancho terminado';
        $data->inputType = CommercialCharacteristicInputType::TEXT;
        $data->unitLabel = 'cm';

        $this->expectException(\DomainException::class);
        (new CommercialCharacteristicTechnicalContract())->assertDefinitionPreserved($characteristic, $data);
    }

    public function testRejectsChangingProtectedUnit(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISHED_HEIGHT_CM')
            ->setName('Alto terminado')
            ->setInputType(CommercialCharacteristicInputType::DECIMAL)
            ->setUnitLabel('cm');

        $data = new CommercialCharacteristicData();
        $data->code = 'FINISHED_HEIGHT_CM';
        $data->name = 'Alto terminado';
        $data->inputType = CommercialCharacteristicInputType::DECIMAL;
        $data->unitLabel = 'mm';

        $this->expectException(\DomainException::class);
        (new CommercialCharacteristicTechnicalContract())->assertDefinitionPreserved($characteristic, $data);
    }

    public function testIgnoresCharacteristicsWithoutTechnicalContract(): void
    {
        $characteristic = (new CommercialCharacteristic())
            ->setCode('FINISH')
            ->setName('Acabado')
            ->setInputType(CommercialCharacteristicInputType::SELECT);

        $service = new CommercialCharacteristicTechnicalContract();

        self::assertNull($service->forCharacteristic($characteristic));
    }
}
