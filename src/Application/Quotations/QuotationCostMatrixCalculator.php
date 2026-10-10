<?php

declare(strict_types=1);

namespace App\Application\Quotations;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Calcula magnitudes técnicas de la matriz comercial, sin inferir tarifas.
 *
 * La cantidad física, el costo de producción y el precio de venta son datos
 * distintos. Esta clase sólo resuelve la primera parte; ninguna fórmula de
 * factores sustituye una tarifa, una merma o un cargo de preparación.
 */
final class QuotationCostMatrixCalculator
{
    public const DIGITAL_AREA = 'DIGITAL_AREA';
    public const OFFSET_SHEET_COLOR_THOUSAND = 'OFFSET_SHEET_COLOR_THOUSAND';
    public const PROMOTIONAL_PIECE_PERSONALIZATION = 'PROMOTIONAL_PIECE_PERSONALIZATION';
    public const SCREEN_SIZE_HUNDRED_INK = 'SCREEN_SIZE_HUNDRED_INK';

    /**
     * @param array<string, mixed> $operands Normalized values supplied by the quote form.
     * @param array<string, mixed> $policy Explicit commercial choices; never assumed.
     * @return array{method: string, quantity: string, unit: string, operands: array<string, string>, formula: string}
     */
    public function calculate(string $method, array $operands, array $policy = []): array
    {
        return match ($method) {
            self::DIGITAL_AREA => $this->digitalArea($operands),
            self::OFFSET_SHEET_COLOR_THOUSAND => $this->offset($operands, $policy),
            self::PROMOTIONAL_PIECE_PERSONALIZATION => $this->promotional($operands),
            self::SCREEN_SIZE_HUNDRED_INK => $this->screen($operands, $policy),
            default => throw new \InvalidArgumentException('El método de la matriz de cotización no es válido.'),
        };
    }

    /** @param array<string, mixed> $operands */
    private function digitalArea(array $operands): array
    {
        $width = $this->decimal($operands, 'finished_width_cm');
        $height = $this->decimal($operands, 'finished_height_cm');
        $pieces = $this->integer($operands, 'pieces');
        $area = $width->multipliedBy($height)->multipliedBy($pieces)
            ->dividedBy('10000', 6, RoundingMode::HalfUp);

        if ($area->isZero()) {
            throw new \DomainException('El área total es menor a 0.000001 m².');
        }

        return [
            'method' => self::DIGITAL_AREA,
            'quantity' => (string) $area,
            'unit' => 'M2',
            'operands' => [
                'finished_width_cm' => (string) $width,
                'finished_height_cm' => (string) $height,
                'pieces' => (string) $pieces,
            ],
            'formula' => 'finished_width_cm * finished_height_cm * pieces / 10000',
        ];
    }

    /** @param array<string, mixed> $operands @param array<string, mixed> $policy */
    private function offset(array $operands, array $policy): array
    {
        $pieces = $this->integer($operands, 'pieces');
        $multiples = $this->decimal($operands, 'letter_sheet_multiples');
        $colors = $this->integer($operands, 'chargeable_colors');
        $thousands = $this->blocks($pieces, '1000', $policy, 'thousand_billing');
        $driver = $multiples->multipliedBy($colors)->multipliedBy($thousands)->toScale(6, RoundingMode::HalfUp);

        return [
            'method' => self::OFFSET_SHEET_COLOR_THOUSAND,
            'quantity' => (string) $driver,
            'unit' => 'LETTER_COLOR_THOUSAND',
            'operands' => [
                'pieces' => (string) $pieces,
                'letter_sheet_multiples' => (string) $multiples,
                'chargeable_colors' => (string) $colors,
                'thousands' => (string) $thousands,
                'thousand_billing' => (string) $policy['thousand_billing'],
            ],
            'formula' => 'letter_sheet_multiples * chargeable_colors * thousands',
        ];
    }

    /** @param array<string, mixed> $operands */
    private function promotional(array $operands): array
    {
        $pieces = $this->integer($operands, 'pieces');
        $positions = $this->integer($operands, 'personalization_positions', true);

        // PIEZA + PERSONALIZACIÓN representa dos importes a sumar, no una
        // suma entre unidades físicas incompatibles. Sus tarifas siguen pendientes.
        return [
            'method' => self::PROMOTIONAL_PIECE_PERSONALIZATION,
            'quantity' => (string) $pieces,
            'unit' => 'PIECE',
            'operands' => [
                'pieces' => (string) $pieces,
                'personalization_positions' => (string) $positions,
            ],
            'formula' => 'pieces (base article); personalization is a separate price component',
        ];
    }

    /** @param array<string, mixed> $operands @param array<string, mixed> $policy */
    private function screen(array $operands, array $policy): array
    {
        $pieces = $this->integer($operands, 'pieces');
        $sizeFactor = $this->decimal($operands, 'size_factor');
        $inks = $this->integer($operands, 'chargeable_inks');
        $hundreds = $this->blocks($pieces, '100', $policy, 'hundred_billing');
        $driver = $sizeFactor->multipliedBy($hundreds)->multipliedBy($inks)->toScale(6, RoundingMode::HalfUp);

        return [
            'method' => self::SCREEN_SIZE_HUNDRED_INK,
            'quantity' => (string) $driver,
            'unit' => 'SIZE_HUNDRED_INK',
            'operands' => [
                'pieces' => (string) $pieces,
                'size_factor' => (string) $sizeFactor,
                'chargeable_inks' => (string) $inks,
                'hundreds' => (string) $hundreds,
                'hundred_billing' => (string) $policy['hundred_billing'],
            ],
            'formula' => 'size_factor * hundreds * chargeable_inks',
        ];
    }

    /** @param array<string, mixed> $values */
    private function decimal(array $values, string $key): BigDecimal
    {
        $raw = $values[$key] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            throw new \DomainException(sprintf('Pendiente: %s.', $key));
        }
        $raw = trim(str_replace(',', '.', (string) $raw));
        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,6})?$/D', $raw) !== 1) {
            throw new \DomainException(sprintf('%s debe ser un decimal positivo con máximo seis decimales.', $key));
        }
        $value = BigDecimal::of($raw);
        if ($value->isZero()) {
            throw new \DomainException(sprintf('%s debe ser mayor que cero.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function integer(array $values, string $key, bool $allowZero = false): BigDecimal
    {
        $raw = $values[$key] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            throw new \DomainException(sprintf('Pendiente: %s.', $key));
        }
        $raw = trim((string) $raw);
        if (preg_match($allowZero ? '/^(?:0|[1-9]\d{0,9})$/D' : '/^[1-9]\d{0,9}$/D', $raw) !== 1) {
            throw new \DomainException(sprintf('%s debe ser un entero %s.', $key, $allowZero ? 'no negativo' : 'positivo'));
        }

        return BigDecimal::of($raw);
    }

    /** @param array<string, mixed> $policy */
    private function blocks(BigDecimal $pieces, string $blockSize, array $policy, string $key): BigDecimal
    {
        $mode = $policy[$key] ?? null;
        if (!in_array($mode, ['PROPORTIONAL', 'ROUND_UP'], true)) {
            throw new \DomainException(sprintf('Pendiente: definir %s como PROPORTIONAL o ROUND_UP.', $key));
        }

        return $pieces->dividedBy($blockSize, 6, RoundingMode::HalfUp)
            ->toScale($mode === 'ROUND_UP' ? 0 : 6, $mode === 'ROUND_UP' ? RoundingMode::Ceiling : RoundingMode::HalfUp);
    }
}
