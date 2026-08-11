<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Quotations;

use App\Entity\Quotations\Quotation;
use App\Enum\Quotations\QuotationResponseChannel;
use App\Enum\Quotations\QuotationStatus;
use PHPUnit\Framework\TestCase;

final class QuotationLifecycleTest extends TestCase
{
    public function testIssuedQuotationCanBeSentAndAcceptedWithEvidence(): void
    {
        $quotation = new Quotation();
        $quotation->issue('COT-2026-0001', new \DateTimeImmutable('2026-08-10 12:00:00 UTC'));
        $quotation->markSent();
        $quotation->accept(
            QuotationResponseChannel::WHATSAPP,
            'Ana López',
            new \DateTimeImmutable('2026-08-10 12:15:00', new \DateTimeZone('America/Mexico_City')),
            'El cliente confirma las cantidades y el total.',
            'Conversación de WhatsApp del 10/08/2026.',
        );

        self::assertSame(QuotationStatus::ACCEPTED, $quotation->getStatus());
        self::assertSame(QuotationResponseChannel::WHATSAPP, $quotation->getDecisionChannel());
        self::assertSame('Ana López', $quotation->getDecisionContact());
        self::assertSame('El cliente confirma las cantidades y el total.', $quotation->getDecisionNotes());
    }

    public function testDraftCannotBeSentOrReceiveCommercialDecision(): void
    {
        $quotation = new Quotation();

        $this->expectException(\DomainException::class);
        $quotation->markSent();
    }

    public function testAcceptedQuotationCannotBeReplacedByRevision(): void
    {
        $quotation = new Quotation();
        $quotation->issue('COT-2026-0002', new \DateTimeImmutable('2026-08-10 12:00:00 UTC'));
        $quotation->accept(
            QuotationResponseChannel::EMAIL,
            'Contacto comercial',
            new \DateTimeImmutable('2026-08-10 12:05:00 UTC'),
            'Aceptación recibida por correo.',
            null,
        );

        $this->expectException(\DomainException::class);
        $quotation->supersede('Se solicitó una corrección tardía.', new \DateTimeImmutable('2026-08-10 12:10:00 UTC'));
    }

    public function testRevisionNumberMustBePositive(): void
    {
        $quotation = new Quotation();

        $this->expectException(\InvalidArgumentException::class);
        $quotation->setRevisionNumber(0);
    }
}
