<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Users\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Evidencia inmutable de sustituir el faltante de un compromiso por otra variante. */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_commitment_substitutions')]
#[ORM\Index(name: 'idx_commitment_substitution_original', columns: ['original_commitment_id'])]
class InventoryCommitmentSubstitution
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'original_commitment_id', nullable: false, onDelete: 'RESTRICT')] private InventoryCommitment $originalCommitment;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'substitute_commitment_id', nullable: false, onDelete: 'RESTRICT')] private InventoryCommitment $substituteCommitment;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'substitution_rule_id', nullable: false, onDelete: 'RESTRICT')] private MaterialSubstitutionRule $rule;
    #[ORM\ManyToOne] #[ORM\JoinColumn(name: 'authorized_by_user_id', nullable: false, onDelete: 'RESTRICT')] private User $authorizedBy;
    #[ORM\Column(name: 'original_shortage_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $originalShortageQuantity;
    #[ORM\Column(name: 'substitute_quantity', type: Types::DECIMAL, precision: 20, scale: 6)] private string $substituteQuantity;
    #[ORM\Column(type: Types::TEXT)] private string $reason;
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;

    public function __construct(InventoryCommitment $original, InventoryCommitment $substitute, MaterialSubstitutionRule $rule, string $originalShortage, string $substituteQuantity, string $reason, User $actor)
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) { throw new \InvalidArgumentException('Explica la sustitución con entre 5 y 2000 caracteres.'); }
        $this->originalCommitment = $original; $this->substituteCommitment = $substitute; $this->rule = $rule; $this->originalShortageQuantity = $originalShortage; $this->substituteQuantity = $substituteQuantity; $this->reason = $reason; $this->authorizedBy = $actor; $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function getOriginalCommitment(): InventoryCommitment { return $this->originalCommitment; }
    public function getSubstituteCommitment(): InventoryCommitment { return $this->substituteCommitment; }
    public function getSubstituteQuantity(): string { return $this->substituteQuantity; }
}
