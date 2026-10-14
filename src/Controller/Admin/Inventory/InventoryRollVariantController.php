<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Entity\Materials\Material;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Repository\Catalog\MeasurementUnitRepository;
use App\Repository\Materials\MaterialRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Service\Audit\AuditLogger;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InventoryRollVariantController extends AbstractController
{
    #[Route('/admin/inventario/presentaciones/rollos/nueva', name: 'admin_inventory_roll_variants_new', methods: ['GET', 'POST'])]
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
        $formData = [
            'material_id' => $request->query->getString('material'),
            'width_m' => '',
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inventory_roll_variant_new', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La solicitud no es válida.');
            }

            $formData = [
                'material_id' => $request->request->getString('material_id'),
                'width_m' => $request->request->getString('width_m'),
            ];
            $materialId = filter_var($formData['material_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $material = $materialId === false ? null : $materialRepository->find($materialId);
            $linearMeter = $unitRepository->findOneBy(['code' => 'ML', 'isActive' => true]);
            $squareMeter = $unitRepository->findOneBy(['code' => 'M2', 'isActive' => true]);

            if (!$material instanceof Material || !$material->isActive() || !$material->isStockItem() || !$material->isConsumable()) {
                $this->addFlash('error', 'Selecciona un material activo con control de existencias.');
            } elseif ($linearMeter === null || $squareMeter === null) {
                $this->addFlash('error', 'Configura primero las unidades ML y M2 del catálogo.');
            } else {
                try {
                    $width = trim(str_replace(',', '.', $formData['width_m']));
                    if (preg_match('/^(?:0|[1-9]\d{0,1})(?:\.\d{1,4})?$/D', $width) !== 1 || BigDecimal::of($width)->isLessThanOrEqualTo(BigDecimal::zero())) {
                        throw new \InvalidArgumentException('El ancho debe ser mayor que cero, en metros y con máximo cuatro decimales.');
                    }
                    // The code denotes a technical width; each physical roll has its own QR and measured length.
                    $code = $this->codeForWidth($material, $width);
                    if ($variantRepository->findOneBy(['code' => $code]) !== null) {
                        throw new \DomainException('Ya existe una presentación para ese material y ancho. Selecciónala al registrar el rollo.');
                    }

                    // Buying and receipt quantities are measured in ML because each roll has a variable length.
                    $variant = (new MaterialVariant($material, $code, $linearMeter, $linearMeter, $squareMeter))
                        ->configureRollWidth($width);
                    $actor = $this->getUser();
                    if (!$actor instanceof User) {
                        throw $this->createAccessDeniedException();
                    }
                    $entityManager->wrapInTransaction(function () use ($entityManager, $auditLogger, $actor, $material, $variant): void {
                        $entityManager->persist($variant);
                        $entityManager->flush();
                        $auditLogger->record($actor, 'inventory.roll_variant.created', 'material_variant', $variant->getId(), null, [
                            'material_code' => $material->getCode(),
                            'variant_code' => $variant->getCode(),
                            'width_m' => $variant->getSpecifications()['width_m'],
                        ]);
                    });

                    $this->addFlash('success', sprintf('Presentación %s configurada. Registra cada rollo con su longitud medida.', $variant->getCode()));
                    return $this->redirectToRoute('admin_inventory_receipts_new', ['variant' => $variant->getId()]);
                } catch (\DomainException|\InvalidArgumentException|UniqueConstraintViolationException $exception) {
                    $this->addFlash('error', $exception instanceof UniqueConstraintViolationException
                        ? 'Ya existe una presentación con ese código.'
                        : $exception->getMessage());
                }
            }
        }

        $configuredRolls = array_values(array_filter(
            $variantRepository->findActiveStockItems(),
            static fn (MaterialVariant $variant): bool => ($variant->getSpecifications()['physical_form'] ?? null) === 'ROLLO'
                && isset($variant->getSpecifications()['width_m']),
        ));

        return $this->render('admin/inventory/receipts/new_roll_variant.html.twig', [
            'materials' => $materials,
            'formData' => $formData,
            'configuredRolls' => $configuredRolls,
            'editingVariant' => null,
            'canEdit' => true,
        ]);
    }

    #[Route('/admin/inventario/presentaciones/rollos/{id}/editar', name: 'admin_inventory_roll_variants_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        MaterialVariant $variant,
        MaterialVariantRepository $variantRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response {
        $this->denyAccessUnlessGranted('inventory.configure');
        if (!$variant->isActive() || ($variant->getSpecifications()['physical_form'] ?? null) !== 'ROLLO'
            || !isset($variant->getSpecifications()['width_m'])) {
            throw $this->createNotFoundException('La presentación de rollo no existe.');
        }

        $formData = [
            'material_id' => $variant->getMaterial()->getId(),
            'width_m' => $variant->getSpecifications()['width_m'],
            'expected_width_m' => $variant->getSpecifications()['width_m'],
        ];
        $canEdit = !$variantRepository->hasReferencesThatLockWidth($variant);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inventory_roll_variant_edit_'.$variant->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La solicitud no es válida.');
            }
            $formData['width_m'] = $request->request->getString('width_m');
            $formData['expected_width_m'] = $request->request->getString('expected_width_m');
            if (!$canEdit) {
                $this->addFlash('error', 'Este ancho ya tiene registros asociados. Agrega una presentación nueva para otro ancho.');
            } else {
                try {
                    $actor = $this->getUser();
                    if (!$actor instanceof User) {
                        throw $this->createAccessDeniedException();
                    }
                    $width = trim(str_replace(',', '.', $formData['width_m']));
                    if (preg_match('/^(?:0|[1-9]\d{0,1})(?:\.\d{1,4})?$/D', $width) !== 1 || BigDecimal::of($width)->isLessThanOrEqualTo(BigDecimal::zero())) {
                        throw new \InvalidArgumentException('El ancho debe ser mayor que cero, en metros y con máximo cuatro decimales.');
                    }
                    $code = $this->codeForWidth($variant->getMaterial(), $width);
                    $sameCode = $variantRepository->findOneBy(['code' => $code]);
                    if ($sameCode !== null && $sameCode !== $variant) {
                        throw new \DomainException('Ya existe una presentación para ese material y ancho.');
                    }

                    $entityManager->wrapInTransaction(function () use ($entityManager, $variantRepository, $auditLogger, $actor, $variant, $width, $code, $formData): void {
                        $entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
                        $entityManager->refresh($variant);
                        if ($variant->getSpecifications()['width_m'] !== $formData['expected_width_m']) {
                            throw new \DomainException('Otro administrador modificó este ancho. Recarga la presentación antes de corregirla.');
                        }
                        if ($variantRepository->hasReferencesThatLockWidth($variant)) {
                            throw new \DomainException('Este ancho se empezó a utilizar. Agrega una presentación nueva para otro ancho.');
                        }
                        $before = ['code' => $variant->getCode(), 'width_m' => $variant->getSpecifications()['width_m']];
                        $variant->correctUnusedRollWidth($width, $code);
                        $auditLogger->record($actor, 'inventory.roll_variant.corrected', 'material_variant', $variant->getId(), $before, [
                            'code' => $variant->getCode(),
                            'width_m' => $variant->getSpecifications()['width_m'],
                        ]);
                    });

                    $this->addFlash('success', 'Ancho corregido. La presentación conserva su identificador.');
                    return $this->redirectToRoute('admin_inventory_roll_variants_new', ['material' => $variant->getMaterial()->getId()]);
                } catch (\DomainException|\InvalidArgumentException|UniqueConstraintViolationException $exception) {
                    $this->addFlash('error', $exception instanceof UniqueConstraintViolationException
                        ? 'Ya existe una presentación con ese código.'
                        : $exception->getMessage());
                }
            }
        }

        return $this->render('admin/inventory/receipts/new_roll_variant.html.twig', [
            'materials' => [],
            'formData' => $formData,
            'configuredRolls' => [],
            'editingVariant' => $variant,
            'canEdit' => $canEdit,
        ]);
    }

    private function codeForWidth(Material $material, string $width): string
    {
        $widthCode = str_replace('.', 'P', BigDecimal::of($width)->toScale(4)->__toString());
        $code = $material->getCode().'-ANCHO-'.$widthCode.'M';
        if (mb_strlen($code) > 80) {
            throw new \InvalidArgumentException('El código del material es demasiado largo para generar la presentación por ancho.');
        }

        return $code;
    }
}
