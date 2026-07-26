<?php

namespace App\Application\Materials;

use App\Entity\Materials\MaterialCategory;
use App\Entity\Users\User;
use App\Repository\Materials\MaterialRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;

final class MaterialCategoryManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly MaterialRepository $materialRepository,
    ) {
    }

    public function create(
        MaterialCategoryData $data,
        User $actor,
    ): MaterialCategory {
        return $this->entityManager->wrapInTransaction(
            function () use ($data, $actor): MaterialCategory {
                $category = (new MaterialCategory())
                    ->setCode($this->requiredText($data->code, 'El código es obligatorio.'))
                    ->setName($this->requiredText($data->name, 'El nombre es obligatorio.'))
                    ->setDescription($data->description);

                $this->entityManager->persist($category);
                $this->entityManager->flush();

                $this->auditLogger->record(
                    $actor,
                    'material_category.created',
                    'material_category',
                    $category->getId(),
                    null,
                    $this->snapshot($category),
                );

                $this->entityManager->flush();

                return $category;
            },
        );
    }

    public function update(
        MaterialCategory $category,
        MaterialCategoryData $data,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(
            function () use ($category, $data, $actor): void {
                $oldValues = $this->snapshot($category);

                $category
                    ->setCode($this->requiredText($data->code, 'El código es obligatorio.'))
                    ->setName($this->requiredText($data->name, 'El nombre es obligatorio.'))
                    ->setDescription($data->description);

                $newValues = $this->snapshot($category);

                if ($oldValues === $newValues) {
                    return;
                }

                $this->auditLogger->record(
                    $actor,
                    'material_category.updated',
                    'material_category',
                    $category->getId(),
                    $oldValues,
                    $newValues,
                );

                $this->entityManager->flush();
            },
        );
    }

    public function setActive(
        MaterialCategory $category,
        bool $isActive,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(
            function () use ($category, $isActive, $actor): void {
                if ($category->isActive() === $isActive) {
                    return;
                }

                if (
                    !$isActive
                    && $this->materialRepository->hasActiveForCategory($category)
                ) {
                    throw new \DomainException(
                        'No puedes desactivar una categoría que tiene materiales activos vinculados.',
                    );
                }

                $oldValues = $this->snapshot($category);

                $category->setIsActive($isActive);

                $this->auditLogger->record(
                    $actor,
                    $isActive
                        ? 'material_category.activated'
                        : 'material_category.deactivated',
                    'material_category',
                    $category->getId(),
                    $oldValues,
                    $this->snapshot($category),
                );

                $this->entityManager->flush();
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MaterialCategory $category): array
    {
        return [
            'code' => $category->getCode(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'is_active' => $category->isActive(),
        ];
    }

    private function requiredText(?string $value, string $message): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new \DomainException($message);
        }

        return $value;
    }
}