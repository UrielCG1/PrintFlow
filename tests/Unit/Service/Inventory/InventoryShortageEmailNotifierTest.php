<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Orders\ServiceOrder;
use App\Entity\Users\User;
use App\Service\Inventory\InventoryShortageEmailNotifier;
use PHPUnit\Framework\TestCase;

final class InventoryShortageEmailNotifierTest extends TestCase
{
    public function testEmailNamesMissingOrderAndLinksToItsDecisionRow(): void
    {
        $order = $this->createMock(ServiceOrder::class);
        $order->method('getFolio')->willReturn('OS-2026-000013');
        $commitment = $this->createMock(InventoryCommitment::class);
        $commitment->method('getServiceOrder')->willReturn($order);
        $admin = $this->createMock(User::class);
        $admin->method('getEmail')->willReturn('admin@example.com');
        $admin->method('getFullName')->willReturn('Admin de prueba');
        $url = 'https://example.com/admin/ordenes-servicio/13#material-commitment-22';

        $email = InventoryShortageEmailNotifier::createEmail($commitment, $admin, $url, 'no-reply@example.com', 'OoxCorp');

        self::assertSame('admin@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('OS-2026-000013', $email->getSubject());
        self::assertSame($url, $email->getContext()['decisionUrl']);
    }
}
