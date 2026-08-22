<?php

namespace App\Application\Catalog;

use App\Entity\Catalog\CommercialCategory;
use App\Entity\Users\User;
use App\Repository\Catalog\CommercialItemRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Catalog\CommercialCategoryRepository;

final class CommercialCategoryManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommercialItemRepository $commercialItemRepository,
        private readonly CommercialCategoryRepository $commercialCategoryRepository,
    ) {
    }

    public function create(CommercialCategoryData $data, User $actor): CommercialCategory
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $actor): CommercialCategory {
            if ($data->displayOrder <= 0) {
                $data->displayOrder = $this->commercialCategoryRepository->nextDisplayOrder();
            }

            $category = new CommercialCategory();
            $this->applyData($category, $data);

            $this->entityManager->persist($category);
            $this->entityManager->flush();

            $this->auditLogger->record($actor, 'commercial_category.created', 'commercial_category', $category->getId(), null, $this->snapshot($category));
            $this->entityManager->flush();

            return $category;
        });
    }

    public function update(CommercialCategory $category, CommercialCategoryData $data, User $actor): void
    {
        $this->entityManager->wrapInTransaction(function () use ($category, $data, $actor): void {
            $oldValues = $this->snapshot($category);
            $this->applyData($category, $data);
            $newValues = $this->snapshot($category);

            if ($oldValues === $newValues) {
                return;
            }

            $this->auditLogger->record($actor, 'commercial_category.updated', 'commercial_category', $category->getId(), $oldValues, $newValues);
            $this->entityManager->flush();
        });
    }

    public function setActive(CommercialCategory $category, bool $isActive, User $actor): void
    {
        if ($category->isActive() === $isActive) {
            return;
        }

        if (!$isActive && $this->commercialItemRepository->hasActiveForCategory($category)) {
            throw new \DomainException('No puedes desactivar una categoría que tiene productos o servicios activos.');
        }

        $this->entityManager->wrapInTransaction(function () use ($category, $isActive, $actor): void {
            $oldValues = $this->snapshot($category);
            $category->setIsActive($isActive);
            $newValues = $this->snapshot($category);

            $this->auditLogger->record(
                $actor,
                $isActive ? 'commercial_category.activated' : 'commercial_category.deactivated',
                'commercial_category',
                $category->getId(),
                $oldValues,
                $newValues,
            );
            $this->entityManager->flush();
        });
    }

    public function reorderActive(
        int $movedId,
        ?int $beforeId,
        ?int $afterId,
        User $actor,
    ): void {
        $this->entityManager->wrapInTransaction(function () use ($movedId, $beforeId, $afterId, $actor): void {
            $categories = $this->commercialCategoryRepository->findActiveOrderedForUpdate();
            $movedCategory = $this->findActiveCategory($categories, $movedId);

            if ($movedCategory === null) {
                throw new \DomainException('La categoría que intentas reordenar ya no está disponible.');
            }

            $oldOrder = $this->orderSnapshot($categories);

            $movedIndex = array_search($movedCategory, $categories, true);
            array_splice($categories, $movedIndex, 1);

            $beforeCategory = $beforeId !== null
                ? $this->findActiveCategory($categories, $beforeId)
                : null;

            $afterCategory = $afterId !== null
                ? $this->findActiveCategory($categories, $afterId)
                : null;

            if (($beforeId !== null && $beforeCategory === null) || ($afterId !== null && $afterCategory === null)) {
                throw new \DomainException('El orden cambió antes de poder guardar tu movimiento. Inténtalo de nuevo.');
            }

            if ($beforeCategory !== null && $afterCategory !== null) {
                $beforeIndex = array_search($beforeCategory, $categories, true);
                $afterIndex = array_search($afterCategory, $categories, true);

                if ($beforeIndex !== $afterIndex + 1) {
                    throw new \DomainException('La posición seleccionada ya no es válida.');
                }
            }

            if ($beforeCategory !== null) {
                $beforeIndex = array_search($beforeCategory, $categories, true);
                array_splice($categories, $beforeIndex, 0, [$movedCategory]);
            } elseif ($afterCategory !== null) {
                $afterIndex = array_search($afterCategory, $categories, true);
                array_splice($categories, $afterIndex + 1, 0, [$movedCategory]);
            } else {
                $categories[] = $movedCategory;
            }

            $newIds = array_map(
                static fn (CommercialCategory $category): int => $category->getId(),
                $categories,
            );

            $oldIds = array_column($oldOrder, 'id');

            if ($oldIds === $newIds) {
                return;
            }

            foreach ($categories as $index => $category) {
                $category->setDisplayOrder(($index + 1) * 10);
            }

            $this->auditLogger->record(
                $actor,
                'commercial_category.reordered',
                'commercial_category',
                $movedCategory->getId(),
                ['active_order' => $oldOrder],
                ['active_order' => $this->orderSnapshot($categories)],
            );

            $this->entityManager->flush();
        });
    }

    /**
     * @param list<CommercialCategory> $categories
     */
    private function findActiveCategory(array $categories, int $id): ?CommercialCategory
    {
        foreach ($categories as $category) {
            if ($category->getId() === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * @param list<CommercialCategory> $categories
     *
     * @return list<array{id: int, display_order: int}>
     */
    private function orderSnapshot(array $categories): array
    {
        return array_map(
            static fn (CommercialCategory $category): array => [
                'id' => $category->getId(),
                'display_order' => $category->getDisplayOrder(),
            ],
            $categories,
        );
    }

    private function applyData(CommercialCategory $category, CommercialCategoryData $data): void
    {
        $category
            ->setCode((string) $data->code)
            ->setName((string) $data->name)
            ->setDescription($data->description)
            ->setDisplayOrder($data->displayOrder);
    }

    /** @return array<string, bool|int|string|null> */
    private function snapshot(CommercialCategory $category): array
    {
        return [
            'code' => $category->getCode(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'display_order' => $category->getDisplayOrder(),
            'is_active' => $category->isActive(),
        ];
    }
}