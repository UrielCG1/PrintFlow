<?php

namespace App\Controller\Admin\Catalog;

use App\Application\Catalog\ItemPriceRuleData;
use App\Application\Catalog\ItemPriceRuleManager;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Catalog\ItemPriceRule;
use App\Entity\Users\User;
use App\Enum\Catalog\ItemPriceRuleType;
use App\Form\Admin\Catalog\ItemPriceRuleType as ItemPriceRuleFormType;
use App\Repository\Catalog\ItemPriceRuleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/admin/catalogo/conceptos/{item}/rangos-precio',
    name: 'admin_catalog_item_price_rules_',
)]
final class ItemPriceRuleController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        CommercialItem $item,
        ItemPriceRuleRepository $itemPriceRuleRepository,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.update_price');

        return $this->render('admin/catalog/item_price_rules/index.html.twig', [
            'item' => $item,
            'rules' => $itemPriceRuleRepository->findQuantityTiersForItem($item),
        ]);
    }

    #[Route('/nuevo', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        CommercialItem $item,
        ItemPriceRuleManager $itemPriceRuleManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.update_price');

        $data = new ItemPriceRuleData();
        $data->commercialItem = $item;
        $data->ruleType = ItemPriceRuleType::QUANTITY_TIER;

        $form = $this->createForm(ItemPriceRuleFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $itemPriceRuleManager->create($data, $this->getActor());

                $this->addFlash('success', 'Rango de precio registrado correctamente.');

                return $this->redirectToRoute(
                    'admin_catalog_item_price_rules_index',
                    ['item' => $item->getId()],
                );
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/catalog/item_price_rules/form.html.twig', [
            'item' => $item,
            'form' => $form,
            'rule' => null,
            'pageTitle' => 'Nuevo rango de precio',
        ]);
    }

    #[Route('/{rule}/editar', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialItem $item,
        ItemPriceRule $rule,
        ItemPriceRuleManager $itemPriceRuleManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.update_price');
        $this->assertRuleBelongsToItem($rule, $item);

        $data = new ItemPriceRuleData();
        $data->id = $rule->getId();
        $data->commercialItem = $item;
        $data->ruleType = $rule->getRuleType();
        $data->minQuantity = $rule->getMinQuantity();
        $data->unitPrice = $rule->getUnitPrice();

        $form = $this->createForm(ItemPriceRuleFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $itemPriceRuleManager->update($rule, $data, $this->getActor());

                $this->addFlash('success', 'Rango de precio actualizado correctamente.');

                return $this->redirectToRoute(
                    'admin_catalog_item_price_rules_index',
                    ['item' => $item->getId()],
                );
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('admin/catalog/item_price_rules/form.html.twig', [
            'item' => $item,
            'form' => $form,
            'rule' => $rule,
            'pageTitle' => 'Editar rango de precio',
        ]);
    }

    #[Route('/{rule}/estado', name: 'status', methods: ['POST'])]
    public function status(
        Request $request,
        CommercialItem $item,
        ItemPriceRule $rule,
        ItemPriceRuleManager $itemPriceRuleManager,
    ): Response {
        $this->denyAccessUnlessGranted('catalog.items.update_price');
        $this->assertRuleBelongsToItem($rule, $item);

        if (!$this->isCsrfTokenValid(
            'item_price_rule_status_'.$rule->getId(),
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('La solicitud no es válida.');
        }

        $itemPriceRuleManager->setActive(
            $rule,
            !$rule->isActive(),
            $this->getActor(),
        );

        $this->addFlash(
            'success',
            $rule->isActive()
                ? 'Rango de precio reactivado correctamente.'
                : 'Rango de precio desactivado correctamente.',
        );

        return $this->redirectToRoute(
            'admin_catalog_item_price_rules_index',
            ['item' => $item->getId()],
        );
    }

    private function assertRuleBelongsToItem(
        ItemPriceRule $rule,
        CommercialItem $item,
    ): void {
        if ($rule->getCommercialItem()->getId() !== $item->getId()) {
            throw $this->createNotFoundException();
        }
    }

    private function getActor(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}