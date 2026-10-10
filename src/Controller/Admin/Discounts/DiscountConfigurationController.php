<?php

declare(strict_types=1);

namespace App\Controller\Admin\Discounts;

use App\Entity\Clients\ClientClass;
use App\Entity\Discounts\CommercialCostingProfile;
use App\Entity\Discounts\VolumeDiscountRule;
use App\Entity\Discounts\DiscountType;
use App\Entity\Catalog\CommercialCategory;
use App\Entity\Catalog\MeasurementUnit;
use App\Entity\Users\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Brick\Math\BigDecimal;

#[Route('/admin/descuentos', name: 'admin_discounts_')]
final class DiscountConfigurationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('discounts.view');
        return $this->render('admin/discounts/index.html.twig', [
            'classes' => $em->getRepository(ClientClass::class)->findBy([], ['code' => 'ASC']),
            'profiles' => $em->getRepository(CommercialCostingProfile::class)->findBy([], ['id' => 'ASC']),
            'rules' => $em->getRepository(VolumeDiscountRule::class)->findBy([], ['profile' => 'ASC', 'minVolume' => 'ASC']),
            'categories' => $em->getRepository(CommercialCategory::class)->findBy(['isActive' => true], ['name' => 'ASC']),
            'units' => $em->getRepository(MeasurementUnit::class)->findBy(['isActive' => true], ['name' => 'ASC']),
        ]);
    }

    #[Route('/perfiles', name: 'profile_create', methods: ['POST'])]
    public function createProfile(Request $request, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->denyAccessUnlessGranted('discounts.costing_profiles.manage');
        $this->assertCsrf($request, 'discount-profile-create');
        try {
            $category = $em->find(CommercialCategory::class, $request->request->getInt('commercial_category_id'));
            $unit = $em->find(MeasurementUnit::class, $request->request->getInt('costing_unit_id'));
            $method = strtoupper(trim($request->request->getString('calculation_method')));
            $allowed = ['DIGITAL_AREA', 'OFFSET_SHEET_COLOR_THOUSAND', 'PROMOTIONAL_PIECE_PERSONALIZATION', 'SCREEN_SIZE_HUNDRED_INK'];
            if (!$category instanceof CommercialCategory || !$category->isActive()) throw new \DomainException('Selecciona una línea de negocio activa.');
            if (!$unit instanceof MeasurementUnit || !$unit->isActive()) throw new \DomainException('Selecciona una unidad de costeo activa.');
            if (!in_array($method, $allowed, true)) throw new \DomainException('El método de cálculo seleccionado no es válido.');
            if ($em->getRepository(CommercialCostingProfile::class)->findOneBy(['commercialCategory' => $category]) instanceof CommercialCostingProfile) throw new \DomainException('La línea de negocio ya tiene un perfil de costeo.');
            // Un perfil sin sus factores y tarifa es un borrador.
            $profile = (new CommercialCostingProfile())->setCommercialCategory($category)->setCostingUnit($unit)->setCalculationMethod($method)->setParameters([])->setIsActive(false);
            $em->persist($profile);
            $em->flush();
            $audit->record(actor: $this->actor(), action: 'discount.costing_profile_created', entityType: 'commercial_costing_profile', entityId: $profile->getId(), newValues: ['category' => $category->getCode(), 'method' => $method, 'unit' => $unit->getCode()]);
            $em->flush();
            $this->addFlash('success', 'Perfil de costeo creado como pendiente. Falta configurar sus datos y validar la fórmula antes de activar descuentos por volumen.');
        } catch (\Throwable $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('admin_discounts_index');
    }

    #[Route('/reglas', name: 'rule_create', methods: ['POST'])]
    public function createRule(Request $request, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->denyAccessUnlessGranted('discounts.volume_rules.manage');
        $this->assertCsrf($request, 'discount-rule-create');
        try {
            $profile = $em->find(CommercialCostingProfile::class, $request->request->getInt('profile_id'));
            $type = $em->getRepository(DiscountType::class)->findOneBy(['code' => 'VOLUME']);
            if (!$profile instanceof CommercialCostingProfile || !$profile->isActive()) throw new \DomainException('Selecciona un perfil de costeo activo.');
            if (!$type instanceof DiscountType) throw new \DomainException('El tipo de descuento por volumen no está configurado.');
            $rule = (new VolumeDiscountRule())->setProfile($profile)->setDiscountType($type)->setMinVolume($request->request->getString('min_volume'))->setDiscountPercent($request->request->getString('percentage'))->setIsActive(true);
            $em->persist($rule);
            $this->assertMonotonicRules($em, $profile, $rule);
            $em->flush();
            $audit->record(actor: $this->actor(), action: 'discount.volume_rule_created', entityType: 'volume_discount_rule', entityId: $rule->getId(), newValues: ['profile_id' => $profile->getId(), 'min_volume' => $rule->getMinVolume(), 'percentage' => $rule->getDiscountPercent()]);
            $em->flush();
            $this->addFlash('success', 'Regla de volumen creada.');
        } catch (\Throwable $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('admin_discounts_index');
    }

    #[Route('/clases/{id}', name: 'class_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateClass(Request $request, ClientClass $class, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->denyAccessUnlessGranted('discounts.client_classes.manage');
        $this->assertCsrf($request, 'discount-class-'.$class->getId());
        $old = ['name' => $class->getName(), 'percentage' => $class->getLoyaltyDiscountPercent()];
        try {
            $class->setName($request->request->getString('name'))
                ->setLoyaltyDiscountPercent($request->request->getString('percentage'));
            $em->flush();
            $audit->record(actor: $this->actor(), action: 'discount.client_class_updated', entityType: 'client_class', entityId: $class->getId(), oldValues: $old, newValues: ['name' => $class->getName(), 'percentage' => $class->getLoyaltyDiscountPercent()]);
            $em->flush();
            $this->addFlash('success', 'Clase de cliente actualizada.');
        } catch (\Throwable $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('admin_discounts_index');
    }

    #[Route('/reglas/{id}', name: 'rule_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function updateRule(Request $request, VolumeDiscountRule $rule, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->denyAccessUnlessGranted('discounts.volume_rules.manage');
        $this->assertCsrf($request, 'discount-rule-'.$rule->getId());
        $old = ['min_volume' => $rule->getMinVolume(), 'percentage' => $rule->getDiscountPercent(), 'active' => $rule->isActive()];
        try {
            $rule->setMinVolume($request->request->getString('min_volume'))
                ->setDiscountPercent($request->request->getString('percentage'))
                ->setIsActive($request->request->getBoolean('is_active'));
            $activeRules = array_values(array_filter(
                $em->getRepository(VolumeDiscountRule::class)->findBy(['profile' => $rule->getProfile()]),
                static fn (VolumeDiscountRule $candidate): bool => $candidate->isActive(),
            ));
            usort($activeRules, static fn (VolumeDiscountRule $a, VolumeDiscountRule $b): int => BigDecimal::of($a->getMinVolume())->compareTo($b->getMinVolume()));
            $previous = null;
            foreach ($activeRules as $candidate) {
                if ($previous !== null && BigDecimal::of($candidate->getDiscountPercent())->compareTo($previous) < 0) {
                    throw new \DomainException('Los porcentajes activos no pueden disminuir al aumentar el volumen mínimo.');
                }
                $previous = $candidate->getDiscountPercent();
            }
            $em->flush();
            $audit->record(actor: $this->actor(), action: 'discount.volume_rule_updated', entityType: 'volume_discount_rule', entityId: $rule->getId(), oldValues: $old, newValues: ['min_volume' => $rule->getMinVolume(), 'percentage' => $rule->getDiscountPercent(), 'active' => $rule->isActive()]);
            $em->flush();
            $this->addFlash('success', 'Regla de volumen actualizada.');
        } catch (\Throwable $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('admin_discounts_index');
    }

    private function assertCsrf(Request $request, string $id): void { if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) throw $this->createAccessDeniedException(); }
    private function assertMonotonicRules(EntityManagerInterface $em, CommercialCostingProfile $profile, VolumeDiscountRule $newRule): void
    {
        $rules = array_values(array_filter([...$em->getRepository(VolumeDiscountRule::class)->findBy(['profile' => $profile, 'isActive' => true]), $newRule], static fn (VolumeDiscountRule $rule): bool => $rule->isActive()));
        usort($rules, static fn (VolumeDiscountRule $a, VolumeDiscountRule $b): int => BigDecimal::of($a->getMinVolume())->compareTo($b->getMinVolume()));
        $previous = null;
        foreach ($rules as $rule) { if ($previous !== null && BigDecimal::of($rule->getDiscountPercent())->compareTo($previous) < 0) throw new \DomainException('Los porcentajes no pueden disminuir al aumentar el volumen mínimo.'); $previous = $rule->getDiscountPercent(); }
    }
    private function actor(): User { $user = $this->getUser(); if (!$user instanceof User) throw $this->createAccessDeniedException(); return $user; }
}
