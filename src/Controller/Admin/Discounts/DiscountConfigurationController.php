<?php

declare(strict_types=1);

namespace App\Controller\Admin\Discounts;

use App\Entity\Clients\ClientClass;
use App\Entity\Discounts\CommercialCostingProfile;
use App\Entity\Discounts\VolumeDiscountRule;
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
        ]);
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
    private function actor(): User { $user = $this->getUser(); if (!$user instanceof User) throw $this->createAccessDeniedException(); return $user; }
}
