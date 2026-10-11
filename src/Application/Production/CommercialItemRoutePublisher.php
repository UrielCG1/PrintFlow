<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Catalog\CommercialItem;
use App\Entity\Operations\Operation;
use App\Entity\Production\CommercialItemRouteStep;
use App\Entity\Production\CommercialItemRouteVersion;
use App\Entity\Production\ProductionRouteTemplate;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class CommercialItemRoutePublisher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param list<ProductionRouteStepData> $configuredSteps */
    public function publish(
        CommercialItem $item,
        ProductionRouteTemplate $template,
        array $configuredSteps,
        User $actor,
    ): CommercialItemRouteVersion {
        if (!$item->isActive()) {
            throw new \DomainException('No se puede publicar una ruta para un Producto inactivo.');
        }
        if (!$template->isActive()) {
            throw new \DomainException('La ruta base seleccionada está inactiva.');
        }

        $steps = $this->validSteps($configuredSteps);
        if ($steps === []) {
            throw new \DomainException('La ruta del Producto debe contener al menos una operación.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($item, $template, $steps, $actor): CommercialItemRouteVersion {
            $this->entityManager->lock($item, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($template, LockMode::PESSIMISTIC_WRITE);
            if (!$item->isActive() || !$template->isActive()) {
                throw new \DomainException('El Producto y la ruta base deben seguir activos para publicar.');
            }
            foreach ($steps as $step) {
                $operation = $step->operation;
                if (!$operation instanceof Operation) {
                    throw new \LogicException('Una operación validada dejó de estar disponible.');
                }
                $this->entityManager->lock($operation, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->lock($operation->getOperationArea(), LockMode::PESSIMISTIC_WRITE);
                if (!$operation->isActive() || !$operation->getOperationArea()->isActive()) {
                    throw new \DomainException('Solo puedes publicar operaciones y áreas activas.');
                }
            }
            $maxVersion = (int) $this->entityManager->createQueryBuilder()
                ->select('COALESCE(MAX(version.versionNumber), 0)')
                ->from(CommercialItemRouteVersion::class, 'version')
                ->andWhere('version.commercialItem = :item')
                ->setParameter('item', $item)
                ->getQuery()
                ->getSingleScalarResult();

            $definitionSteps = [];
            $version = (new CommercialItemRouteVersion())
                ->setCommercialItem($item)
                ->setSourceTemplate($template)
                ->setVersionNumber($maxVersion + 1)
                ->setNameSnapshot($template->getName())
                ->setPublishedBy($actor);

            foreach ($steps as $index => $data) {
                $operation = $data->operation;
                if (!$operation instanceof Operation) {
                    throw new \LogicException('Una operación validada dejó de estar disponible.');
                }

                $operationSnapshot = $this->operationSnapshot($operation);
                $step = (new CommercialItemRouteStep())
                    ->setOperation($operation)
                    ->setSequenceNumber($index + 1)
                    ->setCondition($data->condition)
                    ->setConditionConfig($data->conditionConfig())
                    ->setIsRequired($data->isRequired)
                    ->setOperationSnapshot($operationSnapshot);
                $version->addStep($step);
                $definitionSteps[] = [
                    'sequence_number' => $index + 1,
                    'operation' => $operationSnapshot,
                    'condition' => $data->condition->value,
                    'condition_config' => $data->conditionConfig(),
                    'is_required' => $data->isRequired,
                ];
            }

            $version->setDefinitionSnapshot([
                'source_template_id' => $template->getId(),
                'source_template_code' => $template->getCode(),
                'steps' => $definitionSteps,
            ]);
            $oldVersion = $item->getCurrentRouteVersion()?->getVersionNumber();
            $item->setCurrentRouteVersion($version);

            $this->entityManager->persist($version);
            $this->auditLogger->record(
                actor: $actor,
                action: 'commercial_item.production_route_published',
                entityType: 'commercial_item',
                entityId: $item->getId(),
                oldValues: ['route_version' => $oldVersion],
                newValues: [
                    'route_version' => $version->getVersionNumber(),
                    'route_name' => $version->getNameSnapshot(),
                    'step_count' => count($steps),
                ],
            );
            $this->entityManager->flush();

            return $version;
        });
    }

    /** @param list<ProductionRouteStepData> $steps @return list<ProductionRouteStepData> */
    private function validSteps(array $steps): array
    {
        if (count($steps) > 40) {
            throw new \DomainException('Una ruta no puede tener más de 40 operaciones.');
        }

        $seen = [];
        $result = [];
        foreach ($steps as $step) {
            if ($step->operation === null) {
                if ($step->condition !== \App\Enum\Production\RouteCondition::ALWAYS || trim((string) $step->specificationKey) !== '' || trim((string) $step->specificationValue) !== '' || !$step->isRequired) {
                    throw new \DomainException('Completa o elimina cada fila de operación de la ruta.');
                }
                continue;
            }
            if (!$step->operation->isActive() || !$step->operation->getOperationArea()->isActive()) {
                throw new \DomainException('Solo puedes publicar operaciones y áreas activas.');
            }
            $id = $step->operation->getId();
            if ($id === null || isset($seen[$id])) {
                throw new \DomainException('Una operación solo puede aparecer una vez en la ruta inicial.');
            }
            $seen[$id] = true;

            if ($step->condition === \App\Enum\Production\RouteCondition::SPECIFICATION_EQUALS) {
                $key = strtoupper(trim((string) $step->specificationKey));
                $value = trim((string) $step->specificationValue);
                if (preg_match('/^[A-Z0-9_]{1,80}$/D', $key) !== 1 || $value === '' || mb_strlen($value) > 120) {
                    throw new \DomainException('La condición técnica requiere una clave y un valor válidos.');
                }
            }
            $result[] = $step;
        }

        return $result;
    }

    /** @return array<string, int|string|null> */
    private function operationSnapshot(Operation $operation): array
    {
        return [
            'id' => $operation->getId(),
            'code' => $operation->getCode(),
            'name' => $operation->getName(),
            'area_id' => $operation->getOperationArea()->getId(),
            'area_code' => $operation->getOperationArea()->getCode(),
            'area_name' => $operation->getOperationArea()->getName(),
        ];
    }
}
