<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InventoryMaterialVariantController extends AbstractController
{
    private const PHYSICAL_FORMS = [
        'ROLLO' => 'Rollo', 'ENVASE' => 'Envase', 'PAQUETE' => 'Paquete',
        'PIEZA' => 'Pieza', 'HOJA' => 'Hoja', 'CAJA' => 'Caja',
        'CARTUCHO' => 'Cartucho', 'BOTELLA' => 'Botella', 'JUEGO' => 'Juego',
        'OTRO' => 'Otro',
    ];

    #[Route('/admin/inventario/presentaciones/nueva', name: 'admin_inventory_variants_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MaterialRepository $materialRepository,
        MaterialVariantRepository $variantRepository,
        MeasurementUnitRepository $unitRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.configure');

        $materials = $materialRepository->findBy(
            ['isActive' => true, 'isStockItem' => true, 'isConsumable' => true],
            ['name' => 'ASC'],
        );
        $units = array_values(array_filter(
            $unitRepository->findBy(['isActive' => true], ['displayOrder' => 'ASC', 'name' => 'ASC']),
            static fn (MeasurementUnit $unit): bool => $unit->getDimensionType() !== 'TIME' && $unit->getCode() !== 'SERV',
        ));
        $formData = [
            'material_id' => $request->query->getString('material'),
            'code' => '',
            'physical_form' => '',
            'purchase_unit_id' => '',
            'inventory_unit_id' => '',
            'consumption_unit_id' => '',
            'purchase_factor' => '',
            'consumption_factor' => '',
            'minimum_stock' => '0',
            'reorder_point' => '0',
            'reorder_quantity' => '0',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inventory_variant_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La solicitud no es válida.');
            }
            foreach (array_keys($formData) as $field) {
                $formData[$field] = $request->request->getString($field);
            }

            $material = $this->findActiveMaterial($materialRepository, $formData['material_id']);
            $purchaseUnit = $this->findActiveUnit($unitRepository, $formData['purchase_unit_id']);
            $inventoryUnit = $this->findActiveUnit($unitRepository, $formData['inventory_unit_id']);
            $consumptionUnit = $this->findActiveUnit($unitRepository, $formData['consumption_unit_id']);

            if ($material === null) {
                $this->addFlash('error', 'Selecciona un material activo con control de existencias.');
            } elseif ($purchaseUnit === null || $inventoryUnit === null || $consumptionUnit === null) {
                $this->addFlash('error', 'Selecciona unidades activas de compra, inventario y consumo.');
            } else {
                try {
                    $code = strtoupper(trim($formData['code']));
                    if (preg_match('/^[A-Z0-9._-]{3,80}$/D', $code) !== 1) {
                        throw new \InvalidArgumentException('El código de presentación debe tener de 3 a 80 caracteres: letras, números, punto, guion o guion bajo.');
                    }
                    if ($variantRepository->findOneBy(['code' => $code]) !== null) {
                        throw new \DomainException('Ya existe una presentación con ese código.');
                    }

                    $variant = (new MaterialVariant($material, $code, $purchaseUnit, $inventoryUnit, $consumptionUnit))
                        ->configurePhysicalPresentation(
                            $formData['physical_form'],
                            $formData['purchase_factor'],
                            $formData['consumption_factor'],
                        )
                        ->setInventoryThresholds(
                            $formData['minimum_stock'],
                            $formData['reorder_point'],
                            $formData['reorder_quantity'],
                        );
                    $actor = $this->getUser();
                    if (!$actor instanceof User) {
                        throw $this->createAccessDeniedException();
                    }

                    $entityManager->wrapInTransaction(function () use ($entityManager, $auditLogger, $actor, $variant): void {
                        $entityManager->persist($variant);
                        $entityManager->flush();
                        $auditLogger->record($actor, 'inventory.variant.created', 'material_variant', $variant->getId(), null, [
                            'material_code' => $variant->getMaterial()->getCode(),
                            'variant_code' => $variant->getCode(),
                            'physical_form' => $variant->getSpecifications()['physical_form'],
                            'purchase_unit' => $variant->getPurchaseUnit()->getCode(),
                            'inventory_unit' => $variant->getInventoryUnit()->getCode(),
                            'consumption_unit' => $variant->getConsumptionUnit()->getCode(),
                            'purchase_to_inventory_factor' => $variant->getPurchaseToInventoryFactor(),
                            'inventory_to_consumption_factor' => $variant->getInventoryToConsumptionFactor(),
                            'minimum_stock' => $variant->getMinimumStock(),
                        ]);
                    });

                    $this->addFlash('success', 'Presentación configurada. Ahora registra cada unidad física por separado para generar su QR.');
                    return $this->redirectToRoute('admin_inventory_receipts_new', ['variant' => $variant->getId()]);
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('error', 'Ya existe una presentación con ese código.');
                    return $this->redirectToRoute('admin_inventory_variants_new', ['material' => $material->getId()]);
                } catch (\DomainException|\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                }
            }
        }

        $configured = array_values(array_filter(
            $variantRepository->findActiveStockItems(),
            static fn (MaterialVariant $variant): bool => isset($variant->getSpecifications()['physical_form'])
                && !isset($variant->getSpecifications()['width_m']),
        ));

        return $this->render('admin/inventory/receipts/new_material_variant.html.twig', [
            'materials' => $materials,
            'units' => $units,
            'physicalForms' => self::PHYSICAL_FORMS,
            'formData' => $formData,
            'configured' => $configured,
        ]);
    }

    private function findActiveMaterial(MaterialRepository $repository, string $id): ?Material
    {
        $material = ctype_digit($id) && (int) $id > 0 ? $repository->find((int) $id) : null;
        return $material instanceof Material && $material->isActive() && $material->isStockItem() && $material->isConsumable()
            ? $material : null;
    }

    private function findActiveUnit(MeasurementUnitRepository $repository, string $id): ?MeasurementUnit
    {
        $unit = ctype_digit($id) && (int) $id > 0 ? $repository->find((int) $id) : null;
        return $unit instanceof MeasurementUnit && $unit->isActive()
            && $unit->getDimensionType() !== 'TIME' && $unit->getCode() !== 'SERV' ? $unit : null;
    }
}
