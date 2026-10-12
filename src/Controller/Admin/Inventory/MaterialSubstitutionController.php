<?php

declare(strict_types=1);

namespace App\Controller\Admin\Inventory;

use App\Entity\Inventory\MaterialSubstitutionRule;
use App\Entity\Materials\MaterialVariant;
use App\Entity\Users\User;
use App\Repository\Inventory\MaterialSubstitutionRuleRepository;
use App\Repository\Materials\MaterialVariantRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inventario/sustituciones', name: 'admin_inventory_substitutions_')]
final class MaterialSubstitutionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, MaterialVariantRepository $variantRepository, MaterialSubstitutionRuleRepository $ruleRepository, EntityManagerInterface $entityManager, AuditLogger $auditLogger): Response
    {
        $this->denyAccessUnlessGranted('inventory.substitutions.manage');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('inventory_substitution_rule_new', $request->request->getString('_token'))) { throw $this->createAccessDeniedException('La solicitud no es válida.'); }
            $original = $variantRepository->find($this->positiveId($request->request->getString('original_variant_id')));
            $substitute = $variantRepository->find($this->positiveId($request->request->getString('substitute_variant_id')));
            if (!$original instanceof MaterialVariant || !$substitute instanceof MaterialVariant) {
                $this->addFlash('error', 'Selecciona ambas variantes para configurar la sustitución.');
            } else {
                try {
                    if ($ruleRepository->findOneBy(['originalVariant' => $original, 'substituteVariant' => $substitute]) instanceof MaterialSubstitutionRule) { throw new \DomainException('Esa regla de sustitución ya existe.'); }
                    $rule = new MaterialSubstitutionRule($original, $substitute, $request->request->getString('substitute_per_original'), $request->request->getString('notes'));
                    $entityManager->persist($rule);
                    $auditLogger->record($this->actor(), 'inventory.substitution_rule.created', 'material_substitution_rule', null, null, ['original_variant_id' => $original->getId(), 'substitute_variant_id' => $substitute->getId(), 'factor' => $rule->getSubstitutePerOriginal()]);
                    $entityManager->flush();
                    $this->addFlash('success', 'Regla de sustitución registrada.');
                } catch (\DomainException|\InvalidArgumentException $exception) { $this->addFlash('error', $exception->getMessage()); }
            }
        }
        return $this->render('admin/inventory/substitutions/index.html.twig', ['variants' => $variantRepository->findActiveStockItems(), 'rules' => $ruleRepository->findAllForConfiguration()]);
    }

    #[Route('/{id}/estado', name: 'toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(Request $request, MaterialSubstitutionRule $rule, EntityManagerInterface $entityManager, AuditLogger $auditLogger): Response
    {
        $this->denyAccessUnlessGranted('inventory.substitutions.manage');
        if (!$this->isCsrfTokenValid('inventory_substitution_rule_toggle_'.$rule->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException('La solicitud no es válida.'); }
        $rule->setIsActive(!$rule->isActive());
        $auditLogger->record($this->actor(), 'inventory.substitution_rule.toggled', 'material_substitution_rule', $rule->getId(), null, ['is_active' => $rule->isActive()]);
        $entityManager->flush();
        return $this->redirectToRoute('admin_inventory_substitutions_index');
    }

    private function positiveId(string $value): ?int { return preg_match('/^[1-9]\d*$/D', trim($value)) === 1 ? (int) $value : null; }
    private function actor(): User { $user = $this->getUser(); if (!$user instanceof User) { throw $this->createAccessDeniedException(); } return $user; }
}
