<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Entity\Inventory\InventoryCommitment;
use App\Entity\Inventory\InventoryCommitmentSubstitution;
use App\Entity\Inventory\InventoryShortageDecision;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Enum\Inventory\InventoryCommitmentStatus;
use App\Enum\Inventory\InventoryShortageDecisionType;
use App\Repository\Inventory\InventoryCommitmentRepository;
use App\Repository\Inventory\InventoryCommitmentSubstitutionRepository;
use App\Repository\Inventory\InventoryShortageDecisionRepository;

/** Estado del material que debe ver Impresión antes de escanear una unidad física. */
final class InventoryPrintingMaterialStatus
{
    public function __construct(
        private readonly InventoryCommitmentRepository $commitmentRepository,
        private readonly InventoryShortageDecisionRepository $decisionRepository,
        private readonly InventoryCommitmentSubstitutionRepository $substitutionRepository,
    ) {
    }

    /** @return array{code: string, label: string, message: string, shortage: string, unit: string, substitute: ?string}|null */
    public function forPlan(ServiceOrderOperationPlan $plan): ?array
    {
        return $this->forPlans([$plan])[(int) $plan->getId()] ?? null;
    }

    /**
     * @param list<ServiceOrderOperationPlan> $plans
     * @return array<int, array{code: string, label: string, message: string, shortage: string, unit: string, substitute: ?string}|null>
     */
    public function forPlans(array $plans): array
    {
        $orders = [];
        foreach ($plans as $plan) {
            $order = $plan->getServiceOrderItem()->getServiceOrder();
            $orders[(int) $order->getId()] = $order;
        }

        $byOrder = [];
        foreach ($orders as $orderId => $order) {
            $commitments = [];
            foreach ($this->commitmentRepository->findForOrder($order) as $commitment) {
                $commitments[(int) $commitment->getVariant()->getId()] = $commitment;
            }
            $decisions = [];
            foreach ($this->decisionRepository->findActiveForOrder($order) as $decision) {
                $decisions[(int) $decision->getCommitment()->getId()] = $decision;
            }
            $substitutions = [];
            foreach ($this->substitutionRepository->findForOrder($order) as $substitution) {
                $substitutions[(int) $substitution->getOriginalCommitment()->getVariant()->getId()] = $substitution;
            }
            $byOrder[$orderId] = [$commitments, $decisions, $substitutions];
        }

        $statuses = [];
        foreach ($plans as $plan) {
            $planId = (int) $plan->getId();
            $variant = $plan->getMaterialVariant();
            if ($variant === null) {
                $statuses[$planId] = null;
                continue;
            }

            $orderId = (int) $plan->getServiceOrderItem()->getServiceOrder()->getId();
            [$commitments, $decisions, $substitutions] = $byOrder[$orderId];
            $commitment = $commitments[(int) $variant->getId()] ?? null;
            $statuses[$planId] = self::describe(
                $variant,
                $commitment,
                $commitment === null ? null : ($decisions[(int) $commitment->getId()] ?? null),
                $substitutions[(int) $variant->getId()] ?? null,
            );
        }

        return $statuses;
    }

    /** @return array{code: string, label: string, message: string, shortage: string, unit: string, substitute: ?string} */
    public static function describe(
        MaterialVariant $variant,
        ?InventoryCommitment $commitment,
        ?InventoryShortageDecision $decision,
        ?InventoryCommitmentSubstitution $substitution,
    ): array {
        $unit = $variant->getInventoryUnit()->getSymbol();
        if ($commitment === null) {
            return [
                'code' => 'pending_reservation', 'label' => 'Apartado pendiente',
                'message' => 'Registra el material previsto y actualiza el apartado antes de escanear.',
                'shortage' => '0.000000', 'unit' => $unit, 'substitute' => null,
            ];
        }

        $shortage = $commitment->getShortageQuantity();
        if ($commitment->getStatus() === InventoryCommitmentStatus::SUBSTITUTED && $substitution !== null) {
            $replacement = $substitution->getSubstituteCommitment()->getVariant();
            return [
                'code' => 'substituted', 'label' => 'Sustituto autorizado',
                'message' => $variant->getMaterial()->getName().' no cubre toda la necesidad. Administración autorizó '
                    .$replacement->getMaterial()->getName().' ('.$replacement->getCode().') para los '
                    .$substitution->getSubstituteQuantity().' '.$replacement->getInventoryUnit()->getSymbol().' faltantes. Escanea el QR de ese material.',
                'shortage' => $shortage, 'unit' => $unit,
                'substitute' => $replacement->getMaterial()->getName().' · '.$replacement->getCode(),
            ];
        }

        if ($shortage !== '0.000000') {
            $type = $decision?->getType();
            $code = match ($type) {
                InventoryShortageDecisionType::WAIT_FOR_PURCHASE => 'waiting_purchase',
                InventoryShortageDecisionType::AUTHORIZE_PARTIAL_PRODUCTION => 'partial_authorized',
                default => 'pending_authorization',
            };
            $label = match ($code) {
                'waiting_purchase' => 'En espera de compra',
                'partial_authorized' => 'Producción parcial autorizada',
                default => 'Autorización pendiente',
            };
            $message = match ($code) {
                'waiting_purchase' => 'Faltan '.$shortage.' '.$unit.' de '.$variant->getMaterial()->getName().'. Administración decidió esperar la compra; no inicies la impresión.',
                'partial_authorized' => 'Faltan '.$shortage.' '.$unit.' de '.$variant->getMaterial()->getName().'. Administración autorizó producción parcial. Escanea solo material disponible.',
                default => 'Faltan '.$shortage.' '.$unit.' de '.$variant->getMaterial()->getName().'. El faltante aparece en Avisos de inventario del dashboard administrativo. Espera autorización de sustituto, compra o producción parcial antes de iniciar.',
            };
            return compact('code', 'label', 'message') + ['shortage' => $shortage, 'unit' => $unit, 'substitute' => null];
        }

        return [
            'code' => 'covered', 'label' => 'Material apartado',
            'message' => 'El material previsto está apartado; escanea su unidad física para validar el saldo.',
            'shortage' => '0.000000', 'unit' => $unit, 'substitute' => null,
        ];
    }
}
