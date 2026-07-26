<?php

namespace App\Tests\Unit\Entity\Suppliers;

use App\Entity\Suppliers\Supplier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupplierTest extends TestCase
{
    #[Test]
    public function itNormalizesOperationalSupplierData(): void
    {
        $supplier = (new Supplier())
            ->setCode(' prov-lona_01 ')
            ->setBusinessName('  Lonas del Centro  ')
            ->setLegalName('  Lonas del Centro, S.A. de C.V.  ')
            ->setTaxId('  lce010101ab1  ')
            ->setEmail('  Ventas@Lonas.test  ')
            ->setPhone('  442 123 4567  ')
            ->setNotes('  Entrega local los martes.  ');

        self::assertSame('PROV-LONA_01', $supplier->getCode());
        self::assertSame('Lonas del Centro', $supplier->getBusinessName());
        self::assertSame('Lonas del Centro, S.A. de C.V.', $supplier->getLegalName());
        self::assertSame('LCE010101AB1', $supplier->getTaxId());
        self::assertSame('ventas@lonas.test', $supplier->getEmail());
        self::assertSame('442 123 4567', $supplier->getPhone());
        self::assertSame('Entrega local los martes.', $supplier->getNotes());
        self::assertSame('UTC', $supplier->getCreatedAt()->getTimezone()->getName());
    }

    #[Test]
    public function itUsesSoftDeletionWhenItIsDeactivated(): void
    {
        $supplier = new Supplier();

        $supplier->setIsActive(false);

        self::assertFalse($supplier->isActive());
        self::assertNotNull($supplier->getDeletedAt());
        self::assertSame('UTC', $supplier->getDeletedAt()?->getTimezone()->getName());

        $supplier->setIsActive(true);

        self::assertTrue($supplier->isActive());
        self::assertNull($supplier->getDeletedAt());
    }
}