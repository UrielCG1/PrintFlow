<?php

namespace App\Tests\Unit\Entity\Materials;

use App\Entity\Materials\Material;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MaterialTest extends TestCase
{
    #[Test]
    public function itNormalizesOperationalMaterialData(): void
    {
        $material = (new Material())
            ->setCode(' lona-front_13oz ')
            ->setName('  Lona front 13 oz  ')
            ->setDescription('  Material para impresión de gran formato.  ')
            ->setReferenceCost('125.5')
            ->setMinimumStock('10.25')
            ->setNotes('  Revisar existencias antes de asignar a producción.  ');

        self::assertSame('LONA-FRONT_13OZ', $material->getCode());
        self::assertSame('Lona front 13 oz', $material->getName());
        self::assertSame(
            'Material para impresión de gran formato.',
            $material->getDescription(),
        );
        self::assertSame('125.50', $material->getReferenceCost());
        self::assertSame('10.250', $material->getMinimumStock());
        self::assertSame(
            'Revisar existencias antes de asignar a producción.',
            $material->getNotes(),
        );
        self::assertSame('UTC', $material->getCreatedAt()->getTimezone()->getName());
    }

    #[Test]
    public function itRejectsNegativeOrMalformedReferenceValues(): void
    {
        $material = new Material();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'El costo de referencia no tiene un formato válido.',
        );

        $material->setReferenceCost('-1.00');
    }
}