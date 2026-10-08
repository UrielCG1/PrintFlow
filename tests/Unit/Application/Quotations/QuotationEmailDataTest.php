<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Quotations;

use App\Application\Quotations\QuotationEmailData;
use App\Entity\Quotations\Quotation;
use PHPUnit\Framework\TestCase;

final class QuotationEmailDataTest extends TestCase
{
    public function testCommercialContactIsTheDefaultRecipient(): void
    {
        $quotation = (new Quotation())->setClientSnapshot([
            'business_name' => 'Imprenta Ejemplo',
            'billing_email' => 'facturacion@ejemplo.test',
            'commercial_contact' => [
                'full_name' => 'Ana López',
                'email' => 'ana@ejemplo.test',
            ],
        ]);

        $data = QuotationEmailData::forQuotation($quotation);

        self::assertSame('Ana López', $data->recipientName);
        self::assertSame('ana@ejemplo.test', $data->recipientEmail);
    }
}
