<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Operations\Operation;
use App\Enum\Production\RouteCondition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_route_template_steps')]
#[ORM\UniqueConstraint(name: 'uniq_production_route_template_step_sequence', columns: ['template_id', 'sequence_number'])]
#[ORM\UniqueConstraint(name: 'uniq_production_route_template_step_operation', columns: ['template_id', 'operation_id'])]
#[ORM\Index(name: 'idx_production_route_template_step_operation', columns: ['operation_id'])]
class ProductionRouteTemplateStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'template_id', nullable: false, onDelete: 'RESTRICT')]
    private ProductionRouteTemplate $template;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_id', nullable: false, onDelete: 'RESTRICT')]
    private Operation $operation;

    #[ORM\Column(name: 'sequence_number', options: ['unsigned' => true])]
    private int $sequenceNumber;

    #[ORM\Column(name: 'condition_code', length: 32, enumType: RouteCondition::class, options: ['default' => 'ALWAYS'])]
    private RouteCondition $condition = RouteCondition::ALWAYS;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'condition_config', type: Types::JSON, nullable: true)]
    private ?array $conditionConfig = null;

    #[ORM\Column(name: 'is_required', options: ['default' => true])]
    private bool $isRequired = true;

    public function getId(): ?int { return $this->id; }
    public function getTemplate(): ProductionRouteTemplate { return $this->template; }
    public function setTemplate(ProductionRouteTemplate $template): self { $this->template = $template; return $this; }
    public function getOperation(): Operation { return $this->operation; }
    public function setOperation(Operation $operation): self { $this->operation = $operation; return $this; }
    public function getSequenceNumber(): int { return $this->sequenceNumber; }
    public function setSequenceNumber(int $sequenceNumber): self { if ($sequenceNumber < 1) { throw new \InvalidArgumentException('La secuencia debe ser mayor que cero.'); } $this->sequenceNumber = $sequenceNumber; return $this; }
    public function getCondition(): RouteCondition { return $this->condition; }
    public function setCondition(RouteCondition $condition): self { $this->condition = $condition; return $this; }
    /** @return array<string, mixed>|null */
    public function getConditionConfig(): ?array { return $this->conditionConfig; }
    /** @param array<string, mixed>|null $config */
    public function setConditionConfig(?array $config): self { $this->conditionConfig = $config; return $this; }
    public function isRequired(): bool { return $this->isRequired; }
    public function setIsRequired(bool $isRequired): self { $this->isRequired = $isRequired; return $this; }
}
