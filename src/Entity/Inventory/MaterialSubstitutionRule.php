<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Materials\MaterialVariant;
use App\Repository\Inventory\MaterialSubstitutionRuleRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Compatibilidad técnica autorizada entre variantes y su equivalencia de inventario. */
#[ORM\Entity(repositoryClass: MaterialSubstitutionRuleRepository::class)]
#[ORM\Table(name: 'material_substitution_rules')]
#[ORM\UniqueConstraint(name: 'uniq_substitution_rule_variants', columns: ['original_variant_id', 'substitute_variant_id'])]
class MaterialSubstitutionRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'original_variant_id', nullable: false, onDelete: 'RESTRICT')] private MaterialVariant $originalVariant;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'substitute_variant_id', nullable: false, onDelete: 'RESTRICT')] private MaterialVariant $substituteVariant;
    #[ORM\Column(name: 'substitute_per_original', type: Types::DECIMAL, precision: 24, scale: 12)] private string $substitutePerOriginal;
    #[ORM\Column(type: Types::TEXT, nullable: true)] private ?string $notes = null;
    #[ORM\Column(name: 'is_active')] private bool $isActive = true;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;

    public function __construct(MaterialVariant $original, MaterialVariant $substitute, string $factor, ?string $notes = null)
    {
        if ($original === $substitute || ($original->getId() !== null && $original->getId() === $substitute->getId())) { throw new \InvalidArgumentException('El sustituto debe ser una variante distinta.'); }
        $this->originalVariant = $original;
        $this->substituteVariant = $substitute;
        $this->substitutePerOriginal = self::positiveFactor($factor);
        $notes = trim((string) $notes);
        $this->notes = $notes !== '' ? mb_substr($notes, 0, 2000) : null;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function getId(): ?int { return $this->id; }
    public function getOriginalVariant(): MaterialVariant { return $this->originalVariant; }
    public function getSubstituteVariant(): MaterialVariant { return $this->substituteVariant; }
    public function getSubstitutePerOriginal(): string { return $this->substitutePerOriginal; }
    public function getNotes(): ?string { return $this->notes; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): void { $this->isActive = $active; }
    private static function positiveFactor(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,12})?$/D', $value) !== 1 || BigDecimal::of($value)->isLessThanOrEqualTo(BigDecimal::zero())) { throw new \InvalidArgumentException('La equivalencia del sustituto debe ser mayor que cero y tener máximo doce decimales.'); }
        return BigDecimal::of($value)->toScale(12)->__toString();
    }
}
