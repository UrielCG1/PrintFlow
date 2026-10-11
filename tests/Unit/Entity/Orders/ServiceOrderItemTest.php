<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Orders;

use App\Entity\Orders\ServiceOrderItem;
use App\Enum\Orders\ServiceOrderItemDesignStatus;
use PHPUnit\Framework\TestCase;

final class ServiceOrderItemTest extends TestCase
{
    public function testItKeepsTheStableTrackingCodeAndSourceSnapshots(): void
    {
        $item = (new ServiceOrderItem())
            ->setTrackingCode(' os-2026-000001-p002 ')
            ->setRequestDetailsSnapshot(['notes' => 'Respetar el logotipo proporcionado por el cliente.'])
            ->setSourceFileSnapshot([
                'status' => 'CLIENT_FILE_RECEIVED',
                'path' => 'uploads/quotations/original.pdf',
            ]);

        self::assertSame('OS-2026-000001-P002', $item->getTrackingCode());
        self::assertSame('Respetar el logotipo proporcionado por el cliente.', $item->getRequestDetailsSnapshot()['notes']);
        self::assertSame('CLIENT_FILE_RECEIVED', $item->getSourceFileSnapshot()['status']);
    }

    public function testItRejectsAnInvalidTrackingCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ServiceOrderItem())->setTrackingCode('OS 2026 P001');
    }

    public function testItRequiresAnActiveDesignReviewBeforeReleasingTheItemToPrint(): void
    {
        $item = new ServiceOrderItem();

        self::assertSame(ServiceOrderItemDesignStatus::PENDING_REVIEW, $item->getDesignStatus());
        $item->beginDesignReview()->markReadyForPrint();

        self::assertSame(ServiceOrderItemDesignStatus::READY_FOR_PRINT, $item->getDesignStatus());
    }

    public function testItRejectsAReleaseWithoutStartingDesignReview(): void
    {
        $this->expectException(\DomainException::class);

        (new ServiceOrderItem())->markReadyForPrint();
    }
}
