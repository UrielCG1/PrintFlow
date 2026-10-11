<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Enum\Production\ProductionCommitmentRisk;

final class ProductionCommitmentAnalyzer
{
    private const TIMEZONE = 'America/Mexico_City';

    public function __construct(private readonly int $warningSlackBusinessDays = 1)
    {
        if ($warningSlackBusinessDays < 0 || $warningSlackBusinessDays > 30) {
            throw new \InvalidArgumentException('El umbral de holgura debe estar entre cero y treinta días laborables.');
        }
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $holidays
     * @return array{
     *     production_finish: ?\DateTimeImmutable,
     *     suggested_delivery: ?\DateTimeImmutable,
     *     commitment_date: ?\DateTimeImmutable,
     *     slack_business_days: ?int,
     *     risk: ProductionCommitmentRisk,
     *     risk_label: string,
     *     risk_tone: string,
     *     risk_cause: string
     * }
     */
    public function analyze(
        ?\DateTimeImmutable $productionFinish,
        ?\DateTimeImmutable $commitmentDate,
        array $holidays = [],
    ): array {
        $suggestedDelivery = $this->suggestedDeliveryDate($productionFinish, $holidays);
        $finishDay = $productionFinish?->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
        $commitmentDay = $commitmentDate?->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
        $slack = null;

        if ($finishDay === null) {
            $risk = ProductionCommitmentRisk::UNPLANNED;
            $cause = 'Falta estimar y reservar las operaciones requeridas.';
        } elseif ($commitmentDay === null) {
            $risk = ProductionCommitmentRisk::NO_COMMITMENT;
            $cause = 'Ventas debe confirmar la fecha compromiso.';
        } elseif ($finishDay > $commitmentDay) {
            $lateDays = $this->businessDaysBetween($commitmentDay, $finishDay, $holidays);
            $risk = ProductionCommitmentRisk::LATE;
            $cause = sprintf(
                'El pronóstico rebasa la fecha compromiso por %d día(s) laborable(s).',
                max(1, $lateDays),
            );
        } else {
            $slack = $this->businessDaysBetween($finishDay, $commitmentDay, $holidays);
            if ($slack <= $this->warningSlackBusinessDays) {
                $risk = ProductionCommitmentRisk::TIGHT;
                $cause = sprintf('Queda(n) %d día(s) laborable(s) de holgura.', $slack);
            } else {
                $risk = ProductionCommitmentRisk::ON_TRACK;
                $cause = sprintf('Quedan %d días laborables de holgura.', $slack);
            }
        }

        return [
            'production_finish' => $productionFinish,
            'suggested_delivery' => $suggestedDelivery,
            'commitment_date' => $commitmentDay,
            'slack_business_days' => $slack,
            'risk' => $risk,
            'risk_label' => $risk->label(),
            'risk_tone' => $risk->tone(),
            'risk_cause' => $cause,
        ];
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $holidays */
    public function suggestedDeliveryDate(?\DateTimeImmutable $productionFinish, array $holidays = []): ?\DateTimeImmutable
    {
        if ($productionFinish === null) {
            return null;
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $date = $productionFinish->setTimezone($timezone)->setTime(0, 0);
        $completeDays = 0;
        for ($offset = 0; $offset < 730 && $completeDays < 3; ++$offset) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') > 5 || $this->isHoliday($date, $holidays)) {
                continue;
            }
            ++$completeDays;
        }

        if ($completeDays !== 3) {
            throw new \DomainException('No fue posible calcular el margen de entrega dentro del calendario disponible.');
        }

        return $date;
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $holidays */
    public function businessDaysBetween(\DateTimeImmutable $from, \DateTimeImmutable $through, array $holidays = []): int
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $from = $from->setTimezone($timezone)->setTime(0, 0);
        $through = $through->setTimezone($timezone)->setTime(0, 0);
        if ($through <= $from) {
            return 0;
        }

        $days = 0;
        for ($date = $from->modify('+1 day'); $date <= $through; $date = $date->modify('+1 day')) {
            if ((int) $date->format('N') <= 5 && !$this->isHoliday($date, $holidays)) {
                ++$days;
            }
        }

        return $days;
    }

    /** @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $holidays */
    private function isHoliday(\DateTimeImmutable $date, array $holidays): bool
    {
        $dayStart = $date->getTimestamp();
        $dayEnd = $date->modify('+1 day')->getTimestamp();
        foreach ($holidays as $holiday) {
            if ($holiday['start']->getTimestamp() < $dayEnd && $holiday['end']->getTimestamp() > $dayStart) {
                return true;
            }
        }

        return false;
    }
}
