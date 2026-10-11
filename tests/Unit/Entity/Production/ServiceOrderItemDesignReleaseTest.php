<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Production\ProductionFile;
use App\Entity\Production\ServiceOrderItemDesignRelease;
use App\Entity\Users\User;
use App\Enum\Production\DesignAuthorizationType;
use App\Enum\Production\ProductionFilePurpose;
use App\Enum\Production\ProductionFileSource;
use PHPUnit\Framework\TestCase;

final class ServiceOrderItemDesignReleaseTest extends TestCase
{
    public function testItRequiresAnArtworkAndReferenceFromTheSameItem(): void
    {
        $item = new ServiceOrderItem();
        $artwork = $this->file($item, ProductionFilePurpose::PREPARED_ARTWORK, 1);
        $reference = $this->file($item, ProductionFilePurpose::DESIGN_REFERENCE, 1);

        $release = new ServiceOrderItemDesignRelease(
            $item,
            $artwork,
            $reference,
            DesignAuthorizationType::PREPARED_ARTWORK,
            1,
            '  Cortar siguiendo la captura.  ',
            new User(),
        );

        self::assertTrue($release->isCurrent());
        self::assertSame('Cortar siguiendo la captura.', $release->getInstructions());
        self::assertSame(DesignAuthorizationType::PREPARED_ARTWORK, $release->getAuthorizationType());
    }

    public function testItRejectsAReleaseWithoutTheMandatoryReferenceCapture(): void
    {
        $this->expectException(\DomainException::class);

        $item = new ServiceOrderItem();
        new ServiceOrderItemDesignRelease(
            $item,
            $this->file($item, ProductionFilePurpose::PREPARED_ARTWORK, 1),
            $this->file($item, ProductionFilePurpose::PREPARED_ARTWORK, 2),
            DesignAuthorizationType::PREPARED_ARTWORK,
            1,
            null,
            new User(),
        );
    }

    private function file(ServiceOrderItem $item, ProductionFilePurpose $purpose, int $version): ProductionFile
    {
        return new ProductionFile(
            $item,
            $purpose,
            $version,
            'tests/file-'.$purpose->value.'-'.$version.'.bin',
            'archivo-'.$version.'.bin',
            'application/pdf',
            12,
            str_repeat('a', 64),
            ProductionFileSource::DESIGN_UPLOAD,
            null,
        );
    }
}
