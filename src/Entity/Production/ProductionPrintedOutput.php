<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Users\User;
use App\Repository\Production\ProductionPrintedOutputRepository;
use Doctrine\ORM\Mapping as ORM;

/** Salida física vigente de una partida impresa y su identidad QR. */
#[ORM\Entity(repositoryClass: ProductionPrintedOutputRepository::class)]
#[ORM\Table(name: 'production_printed_outputs')]
#[ORM\UniqueConstraint(name: 'uniq_printed_output_item_version', columns: ['service_order_item_id', 'version_number'])]
#[ORM\UniqueConstraint(name: 'uniq_printed_output_item_current', columns: ['service_order_item_id', 'current_marker'])]
#[ORM\UniqueConstraint(name: 'uniq_printed_output_label_code', columns: ['label_code'])]
#[ORM\Index(name: 'idx_printed_output_execution', columns: ['operation_execution_id'])]
class ProductionPrintedOutput
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'service_order_item_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItem $serviceOrderItem;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'operation_execution_id', nullable: false, onDelete: 'RESTRICT')]
    private ProductionOperationExecution $execution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'design_release_id', nullable: false, onDelete: 'RESTRICT')]
    private ServiceOrderItemDesignRelease $designRelease;

    #[ORM\Column(name: 'version_number', options: ['unsigned' => true])]
    private int $versionNumber;

    #[ORM\Column(name: 'label_code', length:64)]
    private string $labelCode;

    #[ORM\Column(name: 'label_ready_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $labelReadyAt = null;

    #[ORM\Column(name: 'label_placed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $labelPlacedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'label_placed_by_user_id', nullable: true, onDelete: 'RESTRICT')]
    private ?User $labelPlacedBy = null;

    #[ORM\Column(name: 'current_marker', nullable: true, options: ['unsigned' => true, 'default' => 1])]
    private ?int $currentMarker = 1;

    #[ORM\Column(name: 'superseded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ServiceOrderItem $item,
        ProductionOperationExecution $execution,
        ServiceOrderItemDesignRelease $release,
        int $versionNumber,
    ) {
        if ($versionNumber < 1 || $execution->getPlan()->getServiceOrderItem() !== $item || $release->getServiceOrderItem() !== $item) {
            throw new \DomainException('La salida impresa debe pertenecer a la misma partida y a una versión válida.');
        }
        $this->serviceOrderItem = $item;
        $this->execution = $execution;
        $this->designRelease = $release;
        $this->versionNumber = $versionNumber;
        $this->labelCode = 'PF-'.strtoupper(bin2hex(random_bytes(12)));
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getServiceOrderItem(): ServiceOrderItem { return $this->serviceOrderItem; }
    public function getExecution(): ProductionOperationExecution { return $this->execution; }
    public function getDesignRelease(): ServiceOrderItemDesignRelease { return $this->designRelease; }
    public function getVersionNumber(): int { return $this->versionNumber; }
    public function getLabelCode(): string { return $this->labelCode; }
    public function getLabelReadyAt(): ?\DateTimeImmutable { return $this->labelReadyAt; }
    public function getLabelPlacedAt(): ?\DateTimeImmutable { return $this->labelPlacedAt; }
    public function isCurrent(): bool { return $this->currentMarker !== null; }
    public function isLabelPlaced(): bool { return $this->labelPlacedAt !== null; }
    public function getQrPayload(): string
    {
        return sprintf('PF|1|%s|P%s|%s', $this->serviceOrderItem->getServiceOrder()->getFolio(), $this->serviceOrderItem->getDisplayLineNumber(), $this->labelCode);
    }
    public function markLabelReady(): void
    {
        $this->labelReadyAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function confirmLabelPlaced(User $actor): void
    {
        if ($this->labelReadyAt === null) {
            throw new \DomainException('Genera la etiqueta antes de confirmar su colocación.');
        }
        $this->labelPlacedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->labelPlacedBy = $actor;
    }
    public function supersede(): void
    {
        if ($this->currentMarker === null) { return; }
        $this->currentMarker = null;
        $this->supersededAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
