<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Quotations;

use App\Entity\Quotations\Quotation;
use App\Enum\Quotations\QuotationResponseChannel;
use App\Enum\Quotations\QuotationStatus;
use PHPUnit\Framework\TestCase;

final class QuotationLifecycleTest extends TestCase
{
    public function testDraftCanOnlyStartByBeingIssued(): void
    {
        $quotation = new Quotation();

        self::assertSame(QuotationStatus::DRAFT, $quotation->getStatus());
        self::assertTrue($quotation->isEditable());

        $this->expectException(\DomainException::class);
        $quotation->markSent();
    }

    public function testIssuedQuotationCanBeSentAndResent(): void
    {
        $quotation = $this->issuedQuotation();

        self::assertSame(QuotationStatus::ISSUED, $quotation->getStatus());

        $quotation->markSent();
        self::assertSame(QuotationStatus::ISSUED, $quotation->getStatus());

        $quotation->markSent();
        self::assertSame(QuotationStatus::ISSUED, $quotation->getStatus());
    }

    public function testAcceptedQuotationIsTerminalForCommercialDecisionsAndRevisions(): void
    {
        $quotation = $this->issuedQuotation();
        $quotation->accept(
            QuotationResponseChannel::EMAIL,
            'Cliente de prueba',
            new \DateTimeImmutable('2026-08-19 18:00:00', new \DateTimeZone('UTC')),
            'Aceptación confirmada.',
            null,
        );

        self::assertSame(QuotationStatus::ACCEPTED, $quotation->getStatus());
        self::assertFalse($quotation->getStatus()->canReceiveDecision());
        self::assertFalse($quotation->getStatus()->canBeRevised());

        $this->expectException(\DomainException::class);
        $quotation->reject(
            QuotationResponseChannel::EMAIL,
            'Cliente de prueba',
            new \DateTimeImmutable('2026-08-19 18:10:00', new \DateTimeZone('UTC')),
            'Intento inválido.',
            null,
        );
    }

    public function testAcceptedWithChangesCanBeSupersededByARevision(): void
    {
        $quotation = $this->issuedQuotation();
        $quotation->acceptWithChanges(
            'Cliente de prueba',
            new \DateTimeImmutable('2026-08-19 18:00:00', new \DateTimeZone('UTC')),
            'Cambiar medida final.',
            '127.0.0.1',
        );

        self::assertSame(QuotationStatus::ACCEPTED_WITH_CHANGES, $quotation->getStatus());
        self::assertTrue($quotation->getStatus()->canBeRevised());

        $quotation->supersede(
            'Se crea la revisión solicitada por el cliente.',
            new \DateTimeImmutable('2026-08-19 18:15:00', new \DateTimeZone('UTC')),
        );

        self::assertSame(QuotationStatus::SUPERSEDED, $quotation->getStatus());
        self::assertFalse($quotation->getStatus()->canBeRevised());
    }

    public function testRejectedExpiredAndCancelledStatesRemainRevisable(): void
    {
        $rejected = $this->issuedQuotation('PF-2026-0002');
        $rejected->reject(
            QuotationResponseChannel::EMAIL,
            'Cliente de prueba',
            new \DateTimeImmutable('2026-08-19 18:00:00', new \DateTimeZone('UTC')),
            'Precio fuera de presupuesto.',
            null,
        );
        self::assertSame(QuotationStatus::REJECTED, $rejected->getStatus());
        self::assertTrue($rejected->getStatus()->canBeRevised());

        $expired = $this->issuedQuotation('PF-2026-0003');
        $expired->expire(new \DateTimeImmutable('2026-08-19 18:00:00', new \DateTimeZone('UTC')));
        self::assertSame(QuotationStatus::EXPIRED, $expired->getStatus());
        self::assertTrue($expired->getStatus()->canBeRevised());

        $cancelled = $this->issuedQuotation('PF-2026-0004');
        $cancelled->cancel(
            'Solicitud comercial cancelada.',
            new \DateTimeImmutable('2026-08-19 18:00:00', new \DateTimeZone('UTC')),
        );
        self::assertSame(QuotationStatus::CANCELLED, $cancelled->getStatus());
        self::assertTrue($cancelled->getStatus()->canBeRevised());
    }

    private function issuedQuotation(string $folio = 'PF-2026-0001'): Quotation
    {
        $quotation = new Quotation();
        $quotation->issue(
            $folio,
            new \DateTimeImmutable('2026-08-19 17:00:00', new \DateTimeZone('UTC')),
        );

        return $quotation;
    }

    public function testPublicRequestMustPassThroughReviewBeforeDraft(): void
    {
        $quotation = new Quotation();
        $quotation->initializePublicRequest('SOL-20260819-ABC123','Ana López','ana@example.com','4421234567','Ejemplo SA','email','pickup',new \DateTimeImmutable('2026-08-30'),true);
        self::assertSame(QuotationStatus::REQUEST, $quotation->getStatus());
        self::assertFalse($quotation->isEditable());
        $quotation->startReview();
        self::assertSame(QuotationStatus::IN_REVIEW, $quotation->getStatus());
        $quotation->prepareDraft();
        self::assertSame(QuotationStatus::DRAFT, $quotation->getStatus());
        self::assertTrue($quotation->isEditable());
    }
}
