<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Equipment\Equipment;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Users\User;
use AppRepository\Production\ProductionPrintingScanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Evidencia de una validación de máquina y material anterior a Impresión. */
#[ORM\Entity(repositoryClass: ProductionPrintingScanRepository::class)]
#[ORM\Table(name: 'production_printing_scans')]
#[ORM\UniqueConstraint(name: 'uniq_printing_scan_plan_current', columns: ['operation_plan_id', 'current_marker'])]
#[ORM\Index(name: 'idx_printing_scan_operator_current', columns: ['operator_user_id', 'current_marker'])]
class ProductionPrintingScan
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_plan_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderOperationPlan $plan;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'design_release_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItemDesignRelease $designRelease;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'equipment_id', nullable: false, onDelete: 'RESTRICT')]
    private Equipment $equipment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_variant_id', nullable: false, onDelete: 'RESTRICT')]
    private MaterialVariant $materialVariant;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operator_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $operator;

    #[ORM\Column(name: 'equipment_code', length: 40)]
    private string $equipmentCode;

    #[ORM\Column(name: 'material_barcode', length: 80)]
    private string $materialBarcode;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'requirements_snapshot', type: Types::JSON)]
    private array $requirementsSnapshot;

    #[ORM\Column(name: 'scan_token', length: 64, unique: true)]
    private string $scanToken;

    #[ORM\Column(name: 'validated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $validatedAt;

    #[ORM\Column(name: 'consumed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\Column(name: 'invalidated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    #[ORM\Column(name: 'current_marker', nullable: true, options: ['unsigned' => true, 'default' => 1])]
    private ?int $currentMarker = 1;

    /** @param array<string, mixed> $requirementsSnapshot */
    public function __construct(
        ServiceOrderOperationPlan $plan,
        ServiceOrderItemDesignRelease $designRelease,
        Equipment $equipment,
        MaterialVariant $materialVariant,
        User $operator,
        array $requirementsSnapshot,
    ) {
        $this->plan = $plan;
        $this->designRelease = $designRelease;
        $this->equipment = $equipment;
        $this->materialVariant = $materialVariant;
        $this->operator = $operator;
        $this->equipmentCode = $equipment->getCode();
        $barcode = $materialVariant->getBarcode();
        if ($barcode === null) {
            throw new \DomainException('El material validado no tiene código de barras.');
        }
        $this->materialBarcode = $barcode;
        $this->requirementsSnapshot = $requirementsSnapshot;
        $this->scanToken = bin2hex(random_bytes(24));
        $this->validatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getPlan(): ServiceOrderOperationPlan { return $this->plan; }
    public function getDesignRelease(): ServiceOrderItemDesignRelease { return $this->designRelease; }
    public function getEquipment(): Equipment { return $this->equipment; }
    public function getMaterialVariant(): MaterialVariant { return $this->materialVariant; }
    public function getOperator(): User { return $this->operator; }
    public function getScanToken(): string { return $this->scanToken; }
    public function getValidatedAt(): \DateTimeImmutable { return $this->validatedAt; }
    public function isAvailable(): bool { return $this->currentMarker !== null && $this->consumedAt === null && $this->invalidatedAt === null; }

    public function consume(User $operator): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('La validación de escaneo ya no está disponible. Escanea nuevamente.');
        }
        if (!$this->sameUser($this->operator, $operator)) {
            throw new \DomainException('La validación pertenece a otro operador.');
        }
        $this->consumedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function invalidate(): void
    {
        if ($this->currentMarker === null) {
            return;
        }
        $this->currentMarker = null;
        $this->invalidatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function sameUser(User $left, User $right): bool
    {
        return $left === $right || ($left->getId() !== null && $left->getId() === $right->getId());
    }
}
