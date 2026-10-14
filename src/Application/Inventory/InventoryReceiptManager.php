<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryReceipt;
use App\Entity\Inventory\InventoryReceiptLine;
use App\Entity\Inventory\InventoryStockUnit;
use App\Entity\Inventory\MaterialVariantCost;
use App\Entity\Users\User;
use App\Enum\Inventory\InventoryReceiptType;
use App\Enum\Inventory\MovementType;
use App\Entity\Inventory\InventoryMovement;
use App\Repository\Inventory\InventoryReceiptRepository;
use App\Repository\Inventory\InventoryMovementRepository;
use App\Repository\Inventory\MaterialVariantCostRepository;
use App\Repository\Inventory\InventoryPurchaseRequestLineRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Registra una recepción física con QR, libro y proyección de saldo en una transacción. */
final class InventoryReceiptManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryReceiptRepository $receiptRepository,
        private readonly InventoryMovementRepository $movementRepository,
        private readonly MaterialVariantCostRepository $balanceRepository,
        private readonly InventoryPurchaseRequestLineRepository $purchaseRequestLineRepository,
        private readonly InventoryReceiptCommitmentAllocator $commitmentAllocator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function receive(InventoryReceiptCommand $command, User $actor): InventoryReceipt
    {
        return $this->register($command, $actor, InventoryReceiptType::RECEIPT);
    }

    public function recordInitialCount(InventoryReceiptCommand $command, User $actor): InventoryReceipt
    {
        if ($command->isProvisional) {
            throw new \InvalidArgumentException('El conteo inicial no puede registrarse como provisional.');
        }

        return $this->register($command, $actor, InventoryReceiptType::INITIAL_COUNT);
    }

    private function register(
        InventoryReceiptCommand $command,
        User $actor,
        InventoryReceiptType $type,
    ): InventoryReceipt
    {
        if ($command->lines === []) {
            throw new \InvalidArgumentException('Registra al menos una unidad física para confirmar la recepción.');
        }

        $fingerprint = $this->fingerprint($command, $type);

        return $this->entityManager->wrapInTransaction(function () use ($command, $actor, $fingerprint, $type): InventoryReceipt {
            $existing = $this->receiptRepository->findOneByIdempotencyKey($command->idempotencyKey);
            if ($existing instanceof InventoryReceipt) {
                if ($existing->getCommandFingerprint() !== $fingerprint) {
                    throw new \DomainException('La clave de idempotencia ya se utilizó para una recepción con datos distintos.');
                }

                return $existing;
            }

            $lines = $this->sortedLines($command->lines);
            $purchaseRequestLines = [];
            foreach ($lines as $line) {
                $this->assertLineCanBeReceived($line);
                $this->entityManager->lock($line->variant, LockMode::PESSIMISTIC_WRITE);
                if ($line->purchaseRequestLine !== null) {
                    if ($type === InventoryReceiptType::INITIAL_COUNT || $line->purchaseRequestLine->getId() === null) {
                        throw new \DomainException('Solo una recepción física ordinaria puede aplicarse a una solicitud de compra registrada.');
                    }
                    $purchaseRequestLine = $this->purchaseRequestLineRepository->findForUpdate($line->purchaseRequestLine->getId());
                    if ($purchaseRequestLine === null) {
                        throw new \DomainException('La partida de solicitud de compra indicada ya no existe.');
                    }
                    $this->entityManager->lock($purchaseRequestLine->getPurchaseRequest(), LockMode::PESSIMISTIC_WRITE);
                    $purchaseRequestLines[spl_object_id($line)] = $purchaseRequestLine;
                }
            }

            if ($type === InventoryReceiptType::INITIAL_COUNT) {
                $this->assertVariantsHaveNoNormalMovement($lines);
            }

            // Otra solicitud idéntica puede haber terminado mientras esta esperaba
            // los bloqueos de variante. Releer evita que el reintento intente
            // insertar una segunda recepción con la misma clave.
            $existing = $this->receiptRepository->findOneByIdempotencyKey($command->idempotencyKey);
            if ($existing instanceof InventoryReceipt) {
                if ($existing->getCommandFingerprint() !== $fingerprint) {
                    throw new \DomainException('La clave de idempotencia ya se utilizó para una recepción con datos distintos.');
                }

                return $existing;
            }

            $receipt = new InventoryReceipt(
                $command->idempotencyKey,
                $fingerprint,
                $actor,
                $command->reference,
                $command->notes,
                $command->isProvisional,
                $type,
            );
            $this->entityManager->persist($receipt);
            $receivedVariants = [];

            foreach ($lines as $index => $input) {
                $purchaseRequestLine = $purchaseRequestLines[spl_object_id($input)] ?? null;
                $stockUnit = new InventoryStockUnit($input->variant, $input->quantity);
                $stockUnit
                    ->assignLot($input->lot)
                    ->setPhysicalAttributes($input->physicalAttributes)
                    ->setStorageLocation($input->storageLocation);

                $line = new InventoryReceiptLine(
                    $receipt,
                    $index + 1,
                    $input->variant,
                    $input->lot,
                    $stockUnit,
                    $input->quantity,
                    $input->unitCostMxn,
                    $purchaseRequestLine,
                );
                $receipt->addLine($line);
                $this->entityManager->persist($stockUnit);
                $this->entityManager->persist($line);
                $this->entityManager->flush();

                $movement = (new InventoryMovement(
                    $input->variant,
                    $input->variant->getInventoryUnit(),
                    $actor,
                    $type === InventoryReceiptType::INITIAL_COUNT ? MovementType::INITIAL_COUNT : MovementType::RECEIPT,
                    $line->getReceivedQuantity(),
                    $type === InventoryReceiptType::INITIAL_COUNT ? 'inventory_initial_count_line' : 'inventory_receipt_line',
                ))
                    ->assignLot($input->lot)
                    ->assignStockUnit($stockUnit)
                    ->setSourceId($line->getId())
                    ->setUnitCostMxn($line->getUnitCostMxn())
                    ->markProvisionalReceipt($command->isProvisional);
                $line->attachMovement($movement);
                $this->entityManager->persist($movement);

                $balance = $this->balanceRepository->findForUpdate($input->variant);
                if (!$balance instanceof MaterialVariantCost) {
                    $balance = new MaterialVariantCost($input->variant);
                    $this->entityManager->persist($balance);
                }
                $balance->increaseOnHand($line->getReceivedQuantity());
                $purchaseRequestLine?->recordPhysicalReceipt($input->variant, $line->getReceivedQuantity());
                $receivedVariants[(int) $input->variant->getId()] = $input->variant;
            }

            $commitmentAllocations = [];
            if ($type === InventoryReceiptType::RECEIPT) {
                // The reservation movements must reference the persisted receipt, never a
                // placeholder ID. This flush remains inside the enclosing transaction.
                $this->entityManager->flush();
                foreach ($receivedVariants as $variant) {
                    $receiptId = $receipt->getId();
                    if ($receiptId === null) {
                        throw new \LogicException('La recepción física debe tener un identificador antes de asignar faltantes.');
                    }
                    $commitmentAllocations[$variant->getId()] = $this->commitmentAllocator->allocate($variant, $actor, $receiptId);
                }
            }

            $this->auditLogger->record(
                $actor,
                $type === InventoryReceiptType::INITIAL_COUNT ? 'inventory.initial_count.recorded' : 'inventory.receipt.recorded',
                'inventory_receipt',
                $receipt->getId(),
                null,
                [
                    'receipt_code' => $receipt->getReceiptCode(),
                    'receipt_type' => $type->value,
                    'line_count' => count($lines),
                    'is_provisional' => $command->isProvisional,
                    'reference' => $command->reference,
                    'commitment_allocations' => $commitmentAllocations,
                ],
            );
            $this->entityManager->flush();

            return $receipt;
        });
    }

    /** @param list<InventoryReceiptLineInput> $lines @return list<InventoryReceiptLineInput> */
    private function sortedLines(array $lines): array
    {
        foreach ($lines as $line) {
            if (!$line instanceof InventoryReceiptLineInput || $line->variant->getId() === null) {
                throw new \InvalidArgumentException('Cada partida debe indicar una variante de material ya registrada.');
            }
        }

        usort($lines, static fn (InventoryReceiptLineInput $left, InventoryReceiptLineInput $right): int => $left->variant->getId() <=> $right->variant->getId());

        return $lines;
    }

    private function assertLineCanBeReceived(InventoryReceiptLineInput $line): void
    {
        $material = $line->variant->getMaterial();
        if (!$line->variant->isActive() || !$material->isActive() || !$material->isStockItem() || !$line->variant->isConsumable()) {
            throw new \DomainException('La variante recibida debe estar activa, controlar existencias y ser consumible.');
        }
        if ($line->lot !== null && $line->lot->getVariant() !== $line->variant) {
            throw new \DomainException('El lote indicado no pertenece a la variante recibida.');
        }
    }

    /** @param list<InventoryReceiptLineInput> $lines */
    private function assertVariantsHaveNoNormalMovement(array $lines): void
    {
        $variants = [];
        foreach ($lines as $line) {
            $variants[$line->variant->getId()] = $line->variant;
        }
        foreach ($variants as $variant) {
            if ($this->movementRepository->hasNonInitialCountForVariant($variant)) {
                throw new \DomainException('No se pueden cargar existencias previas de una variante que ya tiene movimientos normales. Registra una corrección auditada en su lugar.');
            }
        }
    }

    private function fingerprint(InventoryReceiptCommand $command, InventoryReceiptType $type): string
    {
        $lines = [];
        foreach ($command->lines as $line) {
            if (!$line instanceof InventoryReceiptLineInput || $line->variant->getId() === null) {
                throw new \InvalidArgumentException('Cada partida debe indicar una variante de material ya registrada.');
            }
            $lines[] = [
                'attributes' => $this->canonicalize($line->physicalAttributes),
                'cost' => $this->canonicalNonNegativeDecimalOrNull($line->unitCostMxn),
                'location' => trim((string) $line->storageLocation),
                'lot' => $line->lot?->getId(),
                'purchase_request_line' => $line->purchaseRequestLine?->getId(),
                'quantity' => $this->canonicalDecimal($line->quantity),
                'variant' => $line->variant->getId(),
            ];
        }
        usort($lines, static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR) <=> json_encode($right, JSON_THROW_ON_ERROR));

        return hash('sha256', json_encode([
            'type' => $type->value,
            'is_provisional' => $command->isProvisional,
            'lines' => $lines,
            'notes' => trim((string) $command->notes),
            'reference' => trim((string) $command->reference),
        ], JSON_THROW_ON_ERROR));
    }

    private function canonicalDecimal(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?$/D', $value) !== 1 || BigDecimal::of($value)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException('La cantidad recibida debe ser mayor que cero y tener máximo seis decimales.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }

    private function canonicalNonNegativeDecimalOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('El costo unitario no tiene un formato válido.');
        }

        return BigDecimal::of($value)->toScale(6)->__toString();
    }

    /** @return mixed */
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
