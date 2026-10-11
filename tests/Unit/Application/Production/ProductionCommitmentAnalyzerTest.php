<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Production;

use App\Application\Production\ProductionCommitmentAnalyzer;
use App\Enum\Production\ProductionCommitmentRisk;
use PHPUnit\Framework\TestCase;

final class ProductionCommitmentAnalyzerTest extends TestCase
{
    private const TZ = 'America/Mexico_City';

    public function testSuggestedDeliveryAddsThreeCompleteBusinessDaysAndSkipsHoliday(): void
    {
        $analyzer = new ProductionCommitmentAnalyzer();
        $holiday = [[
            'start' => $this->local('2026-10-14 00:00'),
            'end' => $this->local('2026-10-15 00:00'),
        ]];

        $suggested = $analyzer->suggestedDeliveryDate($this->local('2026-10-12 16:00'), $holiday);

        self::assertSame('2026-10-16', $suggested?->format('Y-m-d'));
    }

    public function testSuggestedDeliverySkipsWeekend(): void
    {
        $suggested = (new ProductionCommitmentAnalyzer())->suggestedDeliveryDate($this->local('2026-10-16 17:00'));

        self::assertSame('2026-10-21', $suggested?->format('Y-m-d'));
    }

    public function testRiskSeparatesLateTightAndOnTrackCommitments(): void
    {
        $analyzer = new ProductionCommitmentAnalyzer();

        $late = $analyzer->analyze($this->local('2026-10-14 09:00'), $this->local('2026-10-13 00:00'));
        $tight = $analyzer->analyze($this->local('2026-10-12 18:00'), $this->local('2026-10-13 00:00'));
        $onTrack = $analyzer->analyze($this->local('2026-10-12 18:00'), $this->local('2026-10-15 00:00'));

        self::assertSame(ProductionCommitmentRisk::LATE, $late['risk']);
        self::assertSame(ProductionCommitmentRisk::TIGHT, $tight['risk']);
        self::assertSame(1, $tight['slack_business_days']);
        self::assertSame(ProductionCommitmentRisk::ON_TRACK, $onTrack['risk']);
        self::assertSame(3, $onTrack['slack_business_days']);
    }

    public function testUnplannedAndMissingCommitmentHaveDistinctRisks(): void
    {
        $analyzer = new ProductionCommitmentAnalyzer();

        self::assertSame(ProductionCommitmentRisk::UNPLANNED, $analyzer->analyze(null, null)['risk']);
        self::assertSame(
            ProductionCommitmentRisk::NO_COMMITMENT,
            $analyzer->analyze($this->local('2026-10-12 12:00'), null)['risk'],
        );
    }

    private function local(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone(self::TZ));
    }
}
