<?php

declare(strict_types=1);

namespace App\Application\Orders;

use App\Entity\Orders\ServiceOrderItem;
use App\Entity\Orders\ServiceOrderOperationPlan;
use App\Entity\Production\CommercialItemRouteStep;
use App\Entity\Users\User;
use App\Enum\Production\RouteCondition;

/** Builds the immutable production-route snapshot from the published Product version. */
final class ProductionRouteApplicator
{
    public function apply(ServiceOrderItem $item, User $actor): void
    {
        $version = $item->getCommercialItem()->getCurrentRouteVersion();
        if ($version === null) {
            $item->setPlanningIssue('El Producto no tiene una ruta de producción publicada.');

            return;
        }

        if ($version->getCommercialItem() !== $item->getCommercialItem()) {
            throw new \LogicException('La ruta publicada no pertenece al Producto de la partida.');
        }

        if (($designFirstIssue = ProductionRoutePolicy::designFirstIssue($version)) !== null) {
            $item->setPlanningIssue($designFirstIssue);

            return;
        }

        $sourceItem = $item->getSourceQuotationItem();
        if ($sourceItem === null) {
            $item->setPlanningIssue('La partida no conserva su vínculo de origen; requiere revisión.');

            return;
        }

        $snapshotSteps = [];
        $sequence = 0;

        foreach ($version->getSteps() as $routeStep) {
            $hasClientFile = trim((string) $sourceItem->getAttachmentPath()) !== '';
            $matches = $this->conditionMatches($routeStep, $item->getProductionSpecificationsSnapshot(), $hasClientFile);
            $blockedReason = !$matches && $routeStep->isRequired() && $routeStep->getCondition() === RouteCondition::CLIENT_FILE_PRESENT
                ? 'Requiere el archivo del cliente antes de continuar.'
                : null;

            $stepSnapshot = [
                'sequence_number' => $routeStep->getSequenceNumber(),
                'operation' => $routeStep->getOperationSnapshot(),
                'condition' => $routeStep->getCondition()->value,
                'condition_config' => $routeStep->getConditionConfig(),
                'is_required' => $routeStep->isRequired(),
                'condition_applied' => $matches,
                'blocked_reason' => $blockedReason,
            ];

            $snapshotSteps[] = $stepSnapshot;
            if (!$matches && $blockedReason === null) {
                continue;
            }

            ++$sequence;
            $plan = (new ServiceOrderOperationPlan())
                ->setServiceOrderItem($item)
                ->setOperation($routeStep->getOperation())
                ->setCreatedBy($actor)
                ->setSequenceNumber($sequence)
                ->setOperationSnapshot($routeStep->getOperationSnapshot())
                ->setRouteStepSnapshot($stepSnapshot)
                ->setConditionApplied($matches)
                ->setBlockedReason($blockedReason);

            $item->addOperationPlan($plan);
        }

        $routeSnapshot = [
            'version_id' => $version->getId(),
            'version_number' => $version->getVersionNumber(),
            'name' => $version->getNameSnapshot(),
            'published_at' => $version->getPublishedAt()->format(\DATE_ATOM),
            'definition' => $version->getDefinitionSnapshot(),
            'technical_specifications' => $item->getProductionSpecificationsSnapshot(),
            'steps' => $snapshotSteps,
        ];

        $item
            ->setRoute($version, $routeSnapshot)
            ->setPlanningIssue($sequence === 0
                ? 'La variante de ruta no generó operaciones aplicables; requiere revisión.'
                : null);
    }

    /** @param array<string, mixed> $specifications */
    private function conditionMatches(CommercialItemRouteStep $step, array $specifications, bool $hasClientFile): bool
    {
        return match ($step->getCondition()) {
            RouteCondition::ALWAYS => true,
            RouteCondition::CLIENT_FILE_PRESENT => $hasClientFile,
            RouteCondition::CLIENT_FILE_MISSING => !$hasClientFile,
            RouteCondition::SPECIFICATION_EQUALS => $this->specificationMatches(
                $specifications,
                $step->getConditionConfig() ?? [],
            ),
        };
    }

    /** @param array<string, mixed> $specifications @param array<string, mixed> $condition */
    private function specificationMatches(array $specifications, array $condition): bool
    {
        $key = trim((string) ($condition['key'] ?? ''));
        $expected = $condition['value'] ?? null;
        if ($key === '' || !is_scalar($expected)) {
            return false;
        }

        $candidate = null;
        $values = $specifications['values'] ?? null;
        if (is_array($values) && array_key_exists($key, $values)) {
            $value = $values[$key];
            $candidate = is_array($value)
                ? ($value['submitted_value'] ?? $value['option_code'] ?? null)
                : $value;
        }
        $candidate ??= $specifications[$key] ?? null;

        return is_scalar($candidate) && (string) $candidate === (string) $expected;
    }
}
