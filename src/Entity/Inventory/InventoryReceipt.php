<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Common\Timestampable;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryReceiptType;
use App\Repository\Inventory\InventoryReceiptRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Cabecera inmutable de una recepción física, protegida por clave de idempotencia. */
#[ORM\Entity(repositoryClass: InventoryReceiptRepository::class)]
#[ORM\Table(name: 'inventory_receipts')]
#[ORM\UniqueConstraint(name: 'uniq_inventory_receipt_code', columns: ['receipt_code'])]
#[ORM\UniqueConstraint(name: 'uniq_inventory_receipt_idempotency', columns: ['idempotency_key'])]
#[ORM\HasLifecycleCallbacks]
class InventoryReceipt
{
    use Timestampable;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'receipt_code', length: 40)]
    private string $receiptCode;

    #[ORM\Column(name: 'idempotency_key', length: 100)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'command_fingerprint', length: 64)]
    private string $commandFingerprint;

    #[ORM\Column(name: 'receipt_type', length: 20, enumType: InventoryReceiptType::class)]
    private InventoryReceiptType $type;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'received_by_user_id', nullable: false, onDelete: 'RESTRICT')]
    private User $receivedBy;

    #[ORM\Column(name: 'reference', length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'is_provisional', options: ['default' => false])]
    private bool $isProvisional;

    #[ORM\Column(name: 'received_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $receivedAt;

    /** @var Collection<int, InventoryReceiptLine> */
    #[ORM\OneToMany(mappedBy: 'receipt', targetEntity: InventoryReceiptLine::class, cascade: ['persist'])]
    private Collection $lines;

    public function __construct(
        string $idempotencyKey,
        string $commandFingerprint,
        User $receivedBy,
        ?string $reference,
        ?string $notes,
        bool $isProvisional,
        InventoryReceiptType $type = InventoryReceiptType::RECEIPT,
    ) {
        $idempotencyKey = trim($idempotencyKey);
        if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('La clave de idempotencia de recepción no tiene un formato válido.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $commandFingerprint) !== 1) {
            throw new \InvalidArgumentException('La huella de la recepción no tiene un formato válido.');
        }

        $this->receiptCode = ($type === InventoryReceiptType::INITIAL_COUNT ? 'CON-' : 'REC-').(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $this->idempotencyKey = $idempotencyKey;
        $this->commandFingerprint = $commandFingerprint;
        $this->type = $type;
        $this->receivedBy = $receivedBy;
        $this->reference = self::nullableText($reference, 100, 'La referencia de recepción');
        $this->notes = self::nullableText($notes, 4000, 'Las notas de recepción');
        $this->isProvisional = $isProvisional;
        $this->receivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->lines = new ArrayCollection();
        $this->initializeTimestamps();
    }

    public function getId(): ?int { return $this->id; }
    public function getReceiptCode(): string { return $this->receiptCode; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getCommandFingerprint(): string { return $this->commandFingerprint; }
    public function getType(): InventoryReceiptType { return $this->type; }
    public function isInitialCount(): bool { return $this->type === InventoryReceiptType::INITIAL_COUNT; }
    public function getReceivedBy(): User { return $this->receivedBy; }
    public function getReference(): ?string { return $this->reference; }
    public function getNotes(): ?string { return $this->notes; }
    public function isProvisional(): bool { return $this->isProvisional; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    /** @return Collection<int, InventoryReceiptLine> */ public function getLines(): Collection { return $this->lines; }

    public function addLine(InventoryReceiptLine $line): void
    {
        if ($line->getReceipt() !== $this) {
            throw new \DomainException('La partida debe pertenecer a la recepción indicada.');
        }
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    private static function nullableText(?string $value, int $limit, string $label): ?string
    {
        $value = trim((string) $value);
        if (mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException($label.' no puede superar '.$limit.' caracteres.');
        }

        return $value !== '' ? $value : null;
    }
}
