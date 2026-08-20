<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum\Quotations;

use App\Enum\Quotations\QuotationStatus;
use PHPUnit\Framework\TestCase;

final class QuotationStatusTest extends TestCase
{
    public function testCapabilityMatrixIsExplicitAndConsistent(): void
    {
        $expected = [
            QuotationStatus::REQUEST->value => [false, false, false, false],
            QuotationStatus::IN_REVIEW->value => [false, false, false, false],
            QuotationStatus::DRAFT->value => [true, false, false, false],
            QuotationStatus::ISSUED->value => [false, true, true, true],
            QuotationStatus::SENT->value => [false, true, true, true],
            QuotationStatus::ACCEPTED->value => [false, false, false, false],
            QuotationStatus::ACCEPTED_WITH_CHANGES->value => [false, false, false, true],
            QuotationStatus::REJECTED->value => [false, false, false, true],
            QuotationStatus::EXPIRED->value => [false, false, false, true],
            QuotationStatus::CANCELLED->value => [false, false, false, true],
            QuotationStatus::SUPERSEDED->value => [false, false, false, false],
        ];

        foreach (QuotationStatus::cases() as $status) {
            self::assertArrayHasKey($status->value, $expected);
            [$editable, $sendable, $decidable, $revisable] = $expected[$status->value];

            self::assertSame($editable, $status->isEditable(), $status->value.' editable');
            self::assertSame($sendable, $status->canBeSent(), $status->value.' sendable');
            self::assertSame($decidable, $status->canReceiveDecision(), $status->value.' decidable');
            self::assertSame($revisable, $status->canBeRevised(), $status->value.' revisable');
        }
    }

    public function testAcceptedWithChangesHasItsOwnCommercialLabel(): void
    {
        self::assertSame('Aceptada con cambios', QuotationStatus::ACCEPTED_WITH_CHANGES->label());
    }
}
