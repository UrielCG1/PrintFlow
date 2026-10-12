<?php

declare(strict_types=1);

namespace App\Controller\Admin\Production;

use App\Application\Production\CommercialItemRouteData;
use App\Application\Production\CommercialItemRoutePublisher;
use App\Application\Production\ProductionRouteStepData;
use App\Application\Production\ProductionRouteTemplateData;
use App\Application\Production\ProductionRouteTemplateManager;
use App\Entity\Catalog\CommercialItem;
use App\Entity\Production\CommercialItemRouteStep;
use App\Entity\Production\ProductionRouteTemplate;
use App\Entity\Users\User;
use App\Enum\Catalog\CommercialItemType;
use App\Form\Admin\Production\CommercialItemRouteType;
use App\Form\Admin\Production\ProductionRouteTemplateType;
use App\Repository\Catalog\CommercialItemRepository;
use App\Repository\Operations\OperationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/produccion/rutas', name: 'admin_production_routes_')]
final class ProductionRouteController extends AbstractController
{
    public function __construct(private readonly FormFactoryInterface $formFactory)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        CommercialItemRepository $commercialItemRepository,
    ): Response {
        $this->denyAccessUnlessGranted('production_routes.manage');
        $templates = $entityManager->getRepository(ProductionRouteTemplate::class)->findBy([], ['name' => 'ASC']);
        $products = $commercialItemRepository->paginateForAdministration(
            isActive: true,
            type: CommercialItemType::PRODUCT,
            page: 1,
            perPage: 100,
        )['items'];

        return $this->render('admin/production/routes/index.html.twig', [
            'templates' => $templates,
            'products' => $products,
        ]);
    }

    #[Route('/nueva', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        OperationRepository $operationRepository,
        ProductionRouteTemplateManager $routeTemplateManager,
    ): Response {
        $this->denyAccessUnlessGranted('production_routes.manage');
        $data = new ProductionRouteTemplateData();
        $data->steps = [new ProductionRouteStepData(), new ProductionRouteStepData(), new ProductionRouteStepData()];
        $form = $this->templateForm($data, $operationRepository->findActiveForServiceOrderPlanning());
        $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
            try {
                $routeTemplateManager->create($data, $this->actor());
                $this->addFlash('success', 'La ruta base se creó. Ya puedes publicarla para cada Producto.');

                return $this->redirectToRoute('admin_production_routes_index');
            } catch (\DomainException $exception) {
                $form->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError('Ya existe una ruta con ese código.'));
            }
        }

        return $this->render('admin/production/routes/template_form.html.twig', [
            'form' => $form,
            'pageTitle' => 'Nueva ruta base',
            'template' => null,
        ]);
    }

    #[Route('/{id}/editar', name: 'edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ProductionRouteTemplate $template,
        OperationRepository $operationRepository,
        ProductionRouteTemplateManager $routeTemplateManager,
    ): Response {
        $this->denyAccessUnlessGranted('production_routes.manage');
        $data = $this->templateData($template);
        $form = $this->templateForm($data, $operationRepository->findActiveForServiceOrderPlanning());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $routeTemplateManager->update($template, $data, $this->actor());
                $this->addFlash('success', 'La ruta base se actualizó. Las versiones publicadas de Productos y órdenes no cambian.');

                return $this->redirectToRoute('admin_production_routes_index');
            } catch (\DomainException $exception) {
                $form->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError('Ya existe otra ruta con ese código.'));
            }
        }

        return $this->render('admin/production/routes/template_form.html.twig', [
            'form' => $form,
            'pageTitle' => 'Editar ruta base',
            'template' => $template,
        ]);
    }

    #[Route('/productos/{id}', name: 'product', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function product(
        Request $request,
        CommercialItem $item,
        EntityManagerInterface $entityManager,
        OperationRepository $operationRepository,
        CommercialItemRoutePublisher $routePublisher,
    ): Response {
        $this->denyAccessUnlessGranted('production_routes.manage');
        if ($item->getType() !== CommercialItemType::PRODUCT) {
            throw $this->createNotFoundException('Las rutas automáticas aplican a Productos.');
        }

        $templates = $entityManager->getRepository(ProductionRouteTemplate::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $operations = $operationRepository->findActiveForServiceOrderPlanning();
        $data = $this->productRouteData($request, $item, $entityManager);
        $currentOperations = array_map(static fn (ProductionRouteStepData $step) => $step->operation, $data->steps);
        foreach ($currentOperations as $operation) {
            if ($operation !== null && !in_array($operation, $operations, true)) {
                $operations[] = $operation;
            }
        }
        $form = $this->formFactory->create(CommercialItemRouteType::class, $data, [
            'templates' => $templates,
            'operations' => $operations,
            'method' => 'POST',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $route = $routePublisher->publish($item, $data->sourceTemplate, $data->steps, $this->actor());
                $this->addFlash('success', sprintf('Se publicó la versión %d de la ruta de %s.', $route->getVersionNumber(), $item->getName()));

                return $this->redirectToRoute('admin_production_routes_index');
            } catch (\DomainException $exception) {
                $form->addError(new \Symfony\Component\Form\FormError($exception->getMessage()));
            } catch (UniqueConstraintViolationException) {
                $form->addError(new \Symfony\Component\Form\FormError('Otra solicitud publicó una versión al mismo tiempo. Vuelve a cargar y revisa la versión actual.'));
            }
        }

        return $this->render('admin/production/routes/product_form.html.twig', [
            'form' => $form,
            'item' => $item,
            'currentRoute' => $item->getCurrentRouteVersion(),
            'baseTemplate' => $data->sourceTemplate,
            'templates' => $templates,
        ]);
    }

    private function templateForm(ProductionRouteTemplateData $data, array $operations): FormInterface
    {
        return $this->formFactory->create(ProductionRouteTemplateType::class, $data, [
            'operations' => $operations,
            'method' => 'POST',
        ]);
    }

    private function templateData(ProductionRouteTemplate $template): ProductionRouteTemplateData
    {
        $data = new ProductionRouteTemplateData();
        $data->code = $template->getCode();
        $data->name = $template->getName();
        $data->description = $template->getDescription();
        $data->isActive = $template->isActive();
        foreach ($template->getSteps() as $step) {
            $stepData = new ProductionRouteStepData();
            $stepData->operation = $step->getOperation();
            $stepData->condition = $step->getCondition();
            $stepData->isRequired = $step->isRequired();
            $stepData->specificationKey = $step->getConditionConfig()['key'] ?? null;
            $stepData->specificationValue = $step->getConditionConfig()['value'] ?? null;
            $data->steps[] = $stepData;
        }
        $data->steps[] = new ProductionRouteStepData();

        return $data;
    }

    private function productRouteData(Request $request, CommercialItem $item, EntityManagerInterface $entityManager): CommercialItemRouteData
    {
        $data = new CommercialItemRouteData();
        $routeVersion = $item->getCurrentRouteVersion();
        $requestedTemplateId = $request->query->getInt('base');
        if ($requestedTemplateId > 0) {
            $standardByOperation = [];
            if ($routeVersion !== null) {
                foreach ($routeVersion->getSteps() as $currentStep) {
                    $operationId = $currentStep->getOperation()->getId();
                    if ($operationId !== null) {
                        $standardByOperation[$operationId] = $currentStep;
                    }
                }
            }
            $template = $entityManager->getRepository(ProductionRouteTemplate::class)->find($requestedTemplateId);
            if ($template instanceof ProductionRouteTemplate && $template->isActive()) {
                $data->sourceTemplate = $template;
                foreach ($template->getSteps() as $step) {
                    $stepData = new ProductionRouteStepData();
                    $stepData->operation = $step->getOperation();
                    $stepData->condition = $step->getCondition();
                    $stepData->isRequired = $step->isRequired();
                    $stepData->specificationKey = $step->getConditionConfig()['key'] ?? null;
                    $stepData->specificationValue = $step->getConditionConfig()['value'] ?? null;
                    $currentStep = $standardByOperation[$step->getOperation()->getId()] ?? null;
                    if ($currentStep instanceof CommercialItemRouteStep) {
                        $stepData->standardBaseMinutes = $currentStep->getStandardBaseMinutes();
                        $stepData->standardMinutesPerUnit = $currentStep->getStandardMinutesPerUnit();
                    }
                    $data->steps[] = $stepData;
                }
            }
        } elseif ($routeVersion !== null) {
            $data->sourceTemplate = $routeVersion->getSourceTemplate();
            foreach ($routeVersion->getSteps() as $step) {
                $data->steps[] = $this->stepData($step);
            }
        }
        $data->steps[] = new ProductionRouteStepData();

        return $data;
    }

    private function stepData(CommercialItemRouteStep $step): ProductionRouteStepData
    {
        $data = new ProductionRouteStepData();
        $data->operation = $step->getOperation();
        $data->condition = $step->getCondition();
        $data->isRequired = $step->isRequired();
        $data->specificationKey = $step->getConditionConfig()['key'] ?? null;
        $data->specificationValue = $step->getConditionConfig()['value'] ?? null;
        $data->standardBaseMinutes = $step->getStandardBaseMinutes();
        $data->standardMinutesPerUnit = $step->getStandardMinutesPerUnit();

        return $data;
    }

    private function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
