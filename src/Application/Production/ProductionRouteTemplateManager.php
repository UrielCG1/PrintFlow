<?php

declare(strict_types=1);

namespace App\Application\Production;

use App\Entity\Production\ProductionRouteTemplate;
use App\Entity\Production\ProductionRouteTemplateStep;
use App\Entity\Users\User;
use App\Enum\Production\RouteCondition;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class ProductionRouteTemplateManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function create(ProductionRouteTemplateData $data, User $actor): ProductionRouteTemplate
    {
        $code = strtoupper(trim((string) $data->code));
        $name = trim((string) $data->name);
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/D', $code) !== 1 || $name === '') {
            throw new \DomainException('Captura un código de ruta válido y un nombre.');
        }

        $steps = $this->validSteps($data->steps);
        if ($steps === []) {
            throw new \DomainException('Agrega al menos una operación a la ruta base.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($code, $name, $data, $steps, $actor): ProductionRouteTemplate {
            $template = (new ProductionRouteTemplate())
                ->setCode($code)
                ->setName($name)
                ->setDescription($data->description)
                ->setIsActive($data->isActive)
                ->setCreatedBy($actor);

            foreach ($steps as $index => $stepData) {
                $step = (new ProductionRouteTemplateStep())
                    ->setOperation($stepData->operation)
                    ->setSequenceNumber($index + 1)
                    ->setCondition($stepData->condition)
                    ->setConditionConfig($stepData->conditionConfig())
                    ->setIsRequired($stepData->isRequired);
                $template->addStep($step);
            }

            $this->entityManager->persist($template);
            $this->auditLogger->record(
                actor: $actor,
                action: 'production_route_template.created',
                entityType: 'production_route_template',
                newValues: ['code' => $code, 'name' => $name, 'step_count' => count($steps)],
            );
            $this->entityManager->flush();

            return $template;
        });
    }

    public function update(ProductionRouteTemplate $template, ProductionRouteTemplateData $data, User $actor): void
    {
        $code = strtoupper(trim((string) $data->code));
        $name = trim((string) $data->name);
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/D', $code) !== 1 || $name === '') {
            throw new \DomainException('Captura un código de ruta válido y un nombre.');
        }
        $steps = $this->validSteps($data->steps);
        if ($steps === []) {
            throw new \DomainException('Agrega al menos una operación a la ruta base.');
        }

        $this->entityManager->wrapInTransaction(function () use ($template, $data, $steps, $code, $name, $actor): void {
            $oldValues = ['code' => $template->getCode(), 'name' => $template->getName(), 'step_count' => $template->getSteps()->count(), 'is_active' => $template->isActive()];
            foreach ($template->getSteps()->toArray() as $oldStep) {
                $template->removeStep($oldStep);
                $this->entityManager->remove($oldStep);
            }
            $this->entityManager->flush();

            $template
                ->setCode($code)
                ->setName($name)
                ->setDescription($data->description)
                ->setIsActive($data->isActive);
            foreach ($steps as $index => $stepData) {
                $template->addStep((new ProductionRouteTemplateStep())
                    ->setOperation($stepData->operation)
                    ->setSequenceNumber($index + 1)
                    ->setCondition($stepData->condition)
                    ->setConditionConfig($stepData->conditionConfig())
                    ->setIsRequired($stepData->isRequired));
            }
            $this->auditLogger->record(
                actor: $actor,
                action: 'production_route_template.updated',
                entityType: 'production_route_template',
                entityId: $template->getId(),
                oldValues: $oldValues,
                newValues: ['code' => $code, 'name' => $name, 'step_count' => count($steps), 'is_active' => $data->isActive],
            );
            $this->entityManager->flush();
        });
    }

    /** @param list<ProductionRouteStepData> $steps @return list<ProductionRouteStepData> */
    private function validSteps(array $steps): array
    {
        if (count($steps) > 40) {
            throw new \DomainException('Una ruta base no puede tener más de 40 operaciones.');
        }

        $selected = [];
        $result = [];
        foreach ($steps as $step) {
            if ($step->operation === null) {
                if ($step->condition !== RouteCondition::ALWAYS || trim((string) $step->specificationKey) !== '' || trim((string) $step->specificationValue) !== '' || !$step->isRequired) {
                    throw new \DomainException('Completa o elimina cada fila de operación de la ruta.');
                }
                continue;
            }

            if (!$step->operation->isActive() || !$step->operation->getOperationArea()->isActive()) {
                throw new \DomainException('Solo puedes incluir operaciones y áreas activas en rutas nuevas.');
            }

            $operationId = $step->operation->getId();
            if ($operationId === null || isset($selected[$operationId])) {
                throw new \DomainException('Una operación solo puede aparecer una vez en la ruta inicial.');
            }
            $selected[$operationId] = true;

            if ($step->condition === RouteCondition::SPECIFICATION_EQUALS) {
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
}
